#!/usr/bin/env python3
"""Static-only secretless AWS candidate check/render. No host inspection or network actions."""
import json
import os
from pathlib import Path
import re
import stat

from render_nginx import ROOT, InputRefused, OfflineArgumentParser, domain_name, render, safe_parent, write_candidate

EXAMPLE = ROOT / 'deploy/aws/runtime.env.example'
# Fixed parser schema: never obtain policy or allowed keys from an untrusted input file.
LOCKED = {
    'APP_NAME': 'H5', 'APP_ENV': 'staging', 'APP_DEBUG': 'false',
    'H5_WORKSPACE': 'h5_staging', 'H5_CONTROLLED_STAGING': 'true',
    'H5_ACQUISITION_STAGING_TEST': 'false',
    'H5_SIMULATION': 'false', 'H5_LOCAL_US_TEST_REGION': 'false', 'H5_LOCAL_MODULES': '',
    'H5_C_EXPERIENCE_ENABLED': 'false', 'H5_C_REGISTRATION_ENABLED': 'false',
    'GEOIP_DRIVER': 'disabled', 'GEOIP_DATABASE': '', 'GEOIP_MAX_AGE_DAYS': '45',
    'H5_MEDIA_ROOT': '/srv/h5/shared/media', 'CONTENT_FFMPEG': '/usr/bin/ffmpeg',
    'CONTENT_FFPROBE': '/usr/bin/ffprobe', 'DB_CONNECTION': 'pgsql', 'DB_HOST': '127.0.0.1',
    'DB_PORT': '5432', 'DB_DATABASE': 'h5_staging', 'DB_USERNAME': 'h5_staging',
    'SESSION_COOKIE': 'h5_staging', 'SESSION_DRIVER': 'database',
    'SESSION_SECURE_COOKIE': 'true', 'SESSION_HTTP_ONLY': 'true', 'SESSION_ENCRYPT': 'true',
    'SESSION_SAME_SITE': 'lax', 'CACHE_STORE': 'database', 'CACHE_PREFIX': 'h5_staging',
    'QUEUE_CONNECTION': 'database', 'DB_QUEUE': 'h5_staging',
    'MAIL_MAILER': 'smtp', 'MAIL_REQUIRE_TLS': 'true', 'MAIL_TIMEOUT': '10',
    'MAIL_FROM_NAME': 'H5', 'LOG_CHANNEL': 'daily', 'LOG_DAILY_DAYS': '14', 'LOG_LEVEL': 'warning',
    'H5_BILLING_DRIVER': 'unconfigured', 'H5_BILLING_NEW_PURCHASE_PROVIDER': 'unconfigured',
    'H5_BILLING_PAYPAL_SANDBOX_ENABLED': 'false', 'ACQUISITION_TRANSPORT': 'disabled',
    'ACQUISITION_EXTERNAL_ENABLED': 'false', 'ACQUISITION_PLATFORM_REVIEWED': 'false',
    'ACQUISITION_CREDENTIALS_FILE': '',
}
SECRETS = {'APP_KEY', 'DB_PASSWORD', 'MAIL_USERNAME', 'MAIL_PASSWORD'}
VARIABLE = {'AWS_REGION', 'APP_URL', 'MAIL_HOST', 'MAIL_FROM_ADDRESS', 'MAIL_SCHEME', 'MAIL_PORT'}
REQUIRED = set(LOCKED) | SECRETS | VARIABLE
PENDING = [
    ('BUDGET_NOT_APPROVED', '本人尚未确认商业运营预算；8GB档只作报价参考，不采购或保证容量'),
    ('RESOURCE_PLAN_NOT_APPROVED', '00/本人确认区域、镜像、规格、费用和运维身份后再建资源'),
    ('GEOIP_DATA_AND_HOST_UNVERIFIED', 'geoip-ready-1本地文件查询待组合接收及实际库许可/版本/主机验证；不能因加reader或选AWS区视为已验证'),
    ('ACQUISITION_CONTROLLED_TEST_PENDING', 'direct-test-1受控test-only源码待组合接收，production未放行；测试位及外发仍默认关闭'),
    ('PUBLIC_REGISTRATION_NOTICE_NOT_RELEASED', '01/08/00接收正式注册告知和版本收据'),
    ('HOST_AND_NETWORK_UNVERIFIED', '获授权后才核Linux依赖、TLS、DNS和实际进程；本工具不执行'),
    ('BACKUP_RESTORE_NOT_VERIFIED', '具体桶/身份/加密授权后另接云备份与独立恢复'),
]


def read_example(path):
    path = Path(path)
    if not path.name.endswith('.env.example'):
        raise InputRefused('SECRETLESS_EXAMPLE_REQUIRED')
    path, parent = safe_parent(path)
    try:
        fd = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        with os.fdopen(fd, 'rb') as stream:
            info = os.fstat(stream.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_size > 32768:
                raise InputRefused('EXAMPLE_FILE_INVALID')
            raw = stream.read(32769)
    finally:
        os.close(parent)
    if len(raw) > 32768:
        raise InputRefused('EXAMPLE_FILE_INVALID')
    return raw.decode('utf-8')


def parse_example(text):
    result = {}
    for line in text.splitlines():
        if not line or line.startswith('#'):
            continue
        match = re.fullmatch(r'([A-Z][A-Z0-9_]*)=([A-Za-z0-9_./:@+-]*)', line)
        if not match or len(line) > 512:
            raise InputRefused('EXAMPLE_SYNTAX_INVALID')
        key, value = match.groups()
        if key not in REQUIRED or key in result:
            raise InputRefused('EXAMPLE_KEYS_INVALID')
        result[key] = value
    if set(result) != REQUIRED:
        raise InputRefused('EXAMPLE_KEYS_INVALID')
    return result


def inspect(values, requested_domain=None):
    errors = []
    blockers = [{'code': code, 'action': action} for code, action in PENDING]
    if any(values[key] != expected for key, expected in LOCKED.items()):
        errors.append('DEFAULT_GATES_OR_ISOLATION_UNSAFE')
    # This step checks only secretless templates, never validates or renders real credentials.
    if any(values[key] for key in SECRETS):
        errors.append('SECRET_VALUES_FORBIDDEN_IN_EXAMPLE')
    blockers.append({'code': 'SECRETS_NOT_PROVISIONED',
                     'action': '后续安全录入APP_KEY、DB与SMTP身份；不向本工具提供真实值'})
    region = values['AWS_REGION']
    if not region:
        blockers.append({'code': 'REGION_REQUIRED', 'action': '由00/本人确认单区域'})
    elif not re.fullmatch(r'[a-z]{2}(?:-[a-z]+)+-[1-9]', region):
        errors.append('REGION_SHAPE_INVALID')
    domain = None
    url = values['APP_URL']
    if url:
        if not url.startswith('https://'):
            errors.append('ORIGIN_INVALID')
        else:
            try:
                domain = domain_name(url.removeprefix('https://'))
            except InputRefused:
                errors.append('ORIGIN_INVALID')
    else:
        blockers.append({'code': 'ORIGIN_REQUIRED', 'action': '确认实际HTTPS主机后填APP_URL'})
    if requested_domain is not None:
        candidate = domain_name(requested_domain)
        if domain is not None and candidate != domain:
            errors.append('ORIGIN_DOMAIN_MISMATCH')
        domain = candidate
    host = values['MAIL_HOST']
    if not host:
        blockers.append({'code': 'SMTP_ENDPOINT_REQUIRED', 'action': '确认区域SES SMTP端点，勿填API端点'})
    elif not region or host != 'email-smtp.' + region + '.amazonaws.com':
        errors.append('SES_REGION_ENDPOINT_MISMATCH')
    else:
        blockers.append({'code': 'SES_ACCOUNT_AND_ENDPOINT_UNVERIFIED',
                         'action': '07回读该区实际SMTP支持、身份/DKIM、sandbox与额度；语法匹配不证明可用'})
    if (values['MAIL_SCHEME'], values['MAIL_PORT']) not in {('smtps', '465'), ('smtp', '587')}:
        errors.append('SMTP_TLS_PORT_INVALID')
    sender = values['MAIL_FROM_ADDRESS']
    if not sender:
        blockers.append({'code': 'MAIL_IDENTITY_REQUIRED', 'action': '07准备已验证发件身份及客服/退信收件邮箱'})
    elif not re.fullmatch(r'[a-zA-Z0-9_.+-]+@[a-z0-9.-]+', sender):
        errors.append('MAIL_IDENTITY_INVALID')
    else:
        try:
            domain_name(sender.split('@')[1])
        except InputRefused:
            errors.append('MAIL_IDENTITY_INVALID')
    return errors, blockers, domain


def main(argv=None):
    parser = OfflineArgumentParser(description=__doc__)
    parser.add_argument('--env-file', type=Path, default=EXAMPLE,
                        help='Only a secretless *.env.example; never a live .env')
    parser.add_argument('--domain', help='Optional offline-render hostname; no DNS/ACME request')
    parser.add_argument('--output', type=Path, help='New .conf artifact under .runtime or a real temp directory')
    parser.add_argument('--phase', choices=['tls', 'acme-bootstrap'], default='tls')
    args = parser.parse_args(argv)
    report = {'mode': 'static-only', 'deployment_ready': False, 'host_checks': 'not-run',
              'network_actions': False, 'secrets_readiness': 'not-provisioned', 'rendered': False}
    try:
        values = parse_example(read_example(args.env_file))
        errors, blockers, domain = inspect(values, args.domain)
        report.update(template_valid=not errors, errors=errors, blockers=blockers)
        if args.output is not None and not errors:
            if domain is None:
                raise InputRefused('RENDER_DOMAIN_REQUIRED')
            write_candidate(args.output, render(domain, args.phase))
            report.update(rendered=True, phase=args.phase)
    except InputRefused as error:
        report.update(template_valid=False, errors=[str(error)])
    except (OSError, UnicodeError):
        report.update(template_valid=False, errors=['CANDIDATE_FILE_REFUSED'])
    print(json.dumps(report, ensure_ascii=False))
    # A static candidate can never pass deployment admission, even after successful rendering.
    return 2


if __name__ == '__main__':
    raise SystemExit(main())
