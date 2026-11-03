#!/usr/bin/env python3
"""Offline Live credential staging only. Never applies configuration or calls PayPal."""
import argparse
from datetime import datetime, timezone
import hashlib
import html
import json
import os
from pathlib import Path
import re
import tempfile

from paypal_sandbox_credentials import Handler as PrivateHandler
from paypal_sandbox_credentials import Server as PrivateServer, private_directory

ROOT = Path(__file__).resolve().parents[2]
MERCHANT_SCOPE = 'h5_sparklingshort_live'
FIELDS = ('client_id', 'client_secret', 'merchant_id', 'live_app_confirmed')
LABELS = ('Live Client ID', 'Live Client Secret', '同一正式商户的 Merchant ID')


def validate(values):
    if set(values) != set(FIELDS) or any(not isinstance(v, str) for v in values.values()):
        raise ValueError('FIELDS_INVALID')
    for key in ('client_id', 'client_secret'):
        if re.fullmatch(r'[\x21-\x7e]{1,4096}', values[key]) is None:
            raise ValueError('CLIENT_CREDENTIALS_INVALID')
    if re.fullmatch(r'[A-Z0-9]{1,64}', values['merchant_id']) is None:
        raise ValueError('MERCHANT_ID_INVALID')
    if values['live_app_confirmed'] != 'yes':
        raise ValueError('LIVE_DASHBOARD_CONFIRMATION_REQUIRED')
    return values


def save(root, values):
    validate(values)
    runtime = root / '.runtime'
    private_directory(runtime)
    directory = runtime / 'paypal-live'
    private_directory(directory)
    target = directory / 'credentials.json'
    if target.is_symlink() or (target.exists() and not target.is_file()):
        raise ValueError('PRIVATE_FILE_INVALID')
    document = {
        'schema': 1, 'environment': 'live', 'workspace_root': str(root),
        'validation': 'offline_shape_only',
        'credentials': {k: values[k] for k in ('client_id', 'client_secret', 'merchant_id')}
            | {'merchant_scope': MERCHANT_SCOPE, 'product_id': '', 'webhook_id': ''},
        # Operator attestation only; not the module's authenticated merchant-binding receipt.
        'operator_confirmation': {
            'source': 'operator_live_dashboard', 'same_app_and_merchant_confirmed': True,
            'client_id_sha256': hashlib.sha256(values['client_id'].encode('ascii')).hexdigest(),
            'confirmed_at': datetime.now(timezone.utc).isoformat(),
        },
    }
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


class Handler(PrivateHandler):
    def do_GET(self):
        if not self.valid_host():
            return self.reply(403, 'HOST_REJECTED')
        if self.path != '/':
            return self.reply(404, 'NOT_FOUND')
        inputs = ''.join(
            f'<p><label>{html.escape(label)}（必填）<br><input type="password" name="{key}" '
            'autocomplete="off" maxlength="4096" size="60" required></label></p>'
            for key, label in zip(FIELDS[:3], LABELS))
        token = f'<input type="hidden" name="csrf" value="{self.server.csrf}">'
        self.reply(200, '<!doctype html><html lang="zh-CN"><meta charset="utf-8">'
                   '<meta name="viewport" content="width=device-width,initial-scale=1">'
                   '<title>H5 Live 私密配置</title><h1>PayPal Live 私密配置</h1>'
                   '<p>仅此 Mac 使用。请在 PayPal Developer 的 Apps &amp; Credentials 切换到 Live，'
                   '打开本人正式商户的同一 App，复制 Client ID 和 Secret，并填写该正式商户的 Merchant ID。</p>'
                   '<p>这里只保存待核资料，不联网、不启用付款、不改应用配置。已有值永不回显，'
                   '每次保存只替换本工具的 Live 待应用文件。保存后请清空剪贴板。</p>'
                   '<form method="post" action="/save">' + token + inputs +
                   '<p><label><input type="checkbox" name="live_app_confirmed" value="yes" required>'
                   '我已在 Live Dashboard 核对：上述 Client ID 与 Secret 来自同一 App，'
                   '且该 App 属于上述 Merchant ID 的正式商户。</label></p>'
                   '<button type="submit">保存到本机私密文件</button></form>'
                   '<form method="post" action="/stop">' + token +
                   '<p><button type="submit">关闭录入服务</button></p></form></html>')


class Server(PrivateServer):
    def __init__(self, root, port):
        super().__init__(root, port, Handler)

    def save(self, values):
        save(self.root, values)


def check_workspace(root):
    runtime = root / '.runtime'
    config = runtime / 'workspace.json'
    if runtime.is_symlink() or config.is_symlink() or not config.is_file():
        raise ValueError('WORKSPACE_NOT_BOUND')
    document = json.loads(config.read_text())
    if not isinstance(document, dict) or document.get('root') != str(root):
        raise ValueError('WORKSPACE_NOT_BOUND')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', type=int, default=8408)
    args = parser.parse_args()
    try:
        check_workspace(ROOT)
    except (ValueError, OSError, UnicodeError):
        raise SystemExit('本工作区尚未正确绑定；请由工程准备，拒绝使用其他工作区配置。')
    try:
        server = Server(ROOT, args.port)
    except (OSError, OverflowError):
        raise SystemExit('本机端口不可用；保留占用它的服务，请由工程指定空闲端口。')
    print(f'本机 Live 私密录入：{server.origin}/；按 Control+C 停止。未连接 PayPal，未启用付款。', flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == '__main__':
    main()
