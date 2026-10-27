#!/usr/bin/env python3
"""Loopback-only, offline Sandbox credential staging. Never changes app .env."""
import argparse
import html
import json
import os
from pathlib import Path
import re
import secrets
import stat
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs

ROOT = Path(__file__).resolve().parents[2]
FIELDS = ('client_id', 'client_secret', 'merchant_id', 'merchant_scope', 'product_id', 'webhook_id')
LABELS = ('Sandbox Client ID（必填）', 'Sandbox Client Secret（必填）',
          'Sandbox 卖家 Merchant ID（稍后可补）', '商户别名（必填，例如 h5_us_sandbox）',
          'Sandbox Product ID（稍后可补）', '本 App Webhook ID（注册真实回调后补）')


def validate(values):
    if set(values) != set(FIELDS) or any(not isinstance(v, str) for v in values.values()):
        raise ValueError('FIELDS_INVALID')
    if any(v and re.fullmatch(r'[\x21-\x7e]{1,4096}', v) is None for v in values.values()):
        raise ValueError('CHARACTERS_INVALID')
    if not values['client_id'] or not values['client_secret']:
        raise ValueError('CLIENT_CREDENTIALS_REQUIRED')
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,63}', values['merchant_scope']):
        raise ValueError('MERCHANT_SCOPE_INVALID')
    if values['webhook_id'] == 'WEBHOOK_ID':
        raise ValueError('SIMULATOR_ID_FORBIDDEN')
    return values


def private_directory(path):
    path.mkdir(mode=0o700, exist_ok=True)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid():
        raise ValueError('PRIVATE_DIRECTORY_INVALID')
    path.chmod(0o700)


def save(root, values):
    validate(values)
    runtime = root / '.runtime'
    private_directory(runtime)
    directory = runtime / 'paypal-sandbox'
    private_directory(directory)
    target = directory / 'credentials.json'
    if target.is_symlink() or (target.exists() and not target.is_file()):
        raise ValueError('PRIVATE_FILE_INVALID')
    document = {'schema': 1, 'environment': 'sandbox', 'workspace_root': str(root),
                'validation': 'offline_shape_only', 'credentials': values}
    fd, name = tempfile.mkstemp(prefix='.credentials-', dir=directory)
    try:
        with os.fdopen(fd, 'w') as stream:
            os.fchmod(stream.fileno(), 0o600)
            json.dump(document, stream, ensure_ascii=True)
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, target)
    finally:
        if os.path.exists(name):
            os.unlink(name)


class Server(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = False

    def __init__(self, root, port):
        self.root = root
        self.csrf = secrets.token_urlsafe(32)
        super().__init__(('127.0.0.1', port), Handler)
        self.origin = f'http://127.0.0.1:{self.server_port}'

    def handle_error(self, request, client_address):
        # Never print request data, credentials or a traceback.
        pass


class Handler(BaseHTTPRequestHandler):
    server_version = 'LocalConfig'
    sys_version = ''

    def setup(self):
        super().setup()
        self.connection.settimeout(5)

    def log_message(self, *args):
        pass

    def reply(self, status, message):
        payload = message.encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self.send_header('Content-Length', str(len(payload)))
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Referrer-Policy', 'no-referrer')
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Content-Security-Policy', "default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'")
        self.send_header('Connection', 'close')
        self.end_headers()
        self.wfile.write(payload)
        self.close_connection = True

    def valid_host(self):
        return self.headers.get_all('Host') == [self.server.origin.removeprefix('http://')]

    def do_GET(self):
        if not self.valid_host():
            return self.reply(403, 'HOST_REJECTED')
        if self.path != '/':
            return self.reply(404, 'NOT_FOUND')
        inputs = ''.join(f'<p><label>{html.escape(label)}<br><input type="password" name="{key}" autocomplete="off" maxlength="4096" size="60" {"required" if key in ("client_id", "client_secret", "merchant_scope") else ""}></label></p>' for key, label in zip(FIELDS, LABELS))
        token = f'<input type="hidden" name="csrf" value="{self.server.csrf}">'
        self.reply(200, '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>H5 Sandbox 私密配置</title><h1>PayPal Sandbox 私密配置</h1><p>仅此 Mac 使用。请从 PayPal Developer 的 Sandbox 页复制，禁止填写 Live 凭据。</p><p>这里只做离线格式校验，不证明 Sandbox 身份、账号权限或付款成功；不联网、不启用付款、不改应用配置。</p><p>每次保存完整替换本工具的待应用文件；已有值永不回显。产品和回调 ID 尚未取得时可留空。保存后请清空剪贴板。</p><form method="post" action="/save">' + token + inputs + '<button type="submit">保存到本机私密文件</button></form><form method="post" action="/stop">' + token + '<p><button type="submit">关闭录入服务</button></p></form></html>')

    def do_POST(self):
        if not self.valid_host() or self.headers.get_all('Origin') != [self.server.origin]:
            return self.reply(403, 'ORIGIN_REJECTED')
        if self.path not in ('/save', '/stop'):
            return self.reply(404, 'NOT_FOUND')
        lengths = self.headers.get_all('Content-Length', [])
        if self.headers.get('Transfer-Encoding') or len(lengths) != 1 or not lengths[0].isdigit():
            return self.reply(400, 'BODY_REJECTED')
        length = int(lengths[0])
        if length > 32768:
            return self.reply(413, 'BODY_TOO_LARGE')
        if self.headers.get_all('Content-Type') != ['application/x-www-form-urlencoded']:
            return self.reply(415, 'CONTENT_TYPE_REJECTED')
        try:
            raw = self.rfile.read(length)
            if len(raw) != length:
                raise ValueError()
            form = parse_qs(raw.decode('ascii'), keep_blank_values=True, strict_parsing=True, max_num_fields=8)
            if any(len(v) != 1 for v in form.values()) or not secrets.compare_digest(form.pop('csrf', [''])[0].encode('utf-8'), self.server.csrf.encode('ascii')):
                return self.reply(403, 'CSRF_REJECTED')
            if self.path == '/stop':
                if form:
                    raise ValueError()
                self.reply(200, '录入服务已关闭。私密文件保留，未启用付款。')
                threading.Thread(target=self.server.shutdown, daemon=True).start()
                return
            save(self.server.root, {k: v[0] for k, v in form.items()})
        except (ValueError, OSError, UnicodeError):
            return self.reply(400, '保存失败。请核对字段、格式和本机私密目录；原文件保留。')
        self.reply(200, '已保存到本工作区私密文件，仅离线格式检查通过。未连接 PayPal、未启用付款。关闭此页后在运行窗口按 Control+C 停止服务。')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', type=int, default=8407)
    args = parser.parse_args()
    config = ROOT / '.runtime/workspace.json'
    if config.is_symlink() or not config.exists() or json.loads(config.read_text()).get('root') != str(ROOT):
        raise SystemExit('请先为本工作区运行 scripts/dev.py setup，拒绝使用其他工作区配置。')
    server = Server(ROOT, args.port)
    print(f'本机私密录入：{server.origin}/；按 Control+C 停止。无外网连接，不启用付款。', flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == '__main__':
    main()
