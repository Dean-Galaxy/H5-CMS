#!/usr/bin/env python3
"""Guarded Sandbox purchase switch. Enable requires current official readiness.

Disable is offline and permits this workspace's PayPal selection, never Stripe.
No resource creation, payment, cancellation, refund, or service startup occurs.
"""
import argparse
import fcntl
import hashlib
import json
import os
import subprocess
import tempfile
from paypal_apply_credentials import (ROOT, PREFIX, PHP, read_private, bound_environment,
                                      render_updates, atomic_private)
from paypal_complete_webhook_url import candidate_ready, active_window_url

PROVIDER='H5_BILLING_NEW_PURCHASE_PROVIDER'


class WrittenButUnverified(ValueError):
    pass


def state(root,candidate,enable,autoload=None,after_write=False):
    candidate_ready(root,candidate)
    dirty=subprocess.run(['git','-C',str(root),'diff','--quiet','HEAD','--','scripts/ops']).returncode
    extra=subprocess.check_output(['git','-C',str(root),'ls-files','--others','--exclude-standard','--','scripts/ops'],text=True)
    if dirty or extra:raise ValueError('OPERATION_SOURCE_DIRTY')
    path,raw,values,loader,directory=bound_environment(root,autoload,allowed_purchase_providers=('unconfigured','','paypal'))
    window=None
    if enable:
        if values.get(PREFIX+'ENABLED') not in ('true','(true)'):
            raise ValueError('EXPLICIT_API_ONLY_REQUIRED')
        expected='paypal' if after_write else 'unconfigured'
        if values.get(PROVIDER,'unconfigured')!=expected:
            raise ValueError('UNEXPECTED_PURCHASE_STATE')
        url=active_window_url(root)
        if values.get(PREFIX+'WEBHOOK_URL')!=url:
            raise ValueError('ACTIVE_CALLBACK_URL_MISMATCH')
        records=b''.join(read_private(directory/name).encode() for name in ('external-url.json','external-processes.json','external-probe-marker.json'))
        window=hashlib.sha256(records).hexdigest()
    return path,raw,values,loader,directory,window


def official_readiness(root,values):
    result=subprocess.run([PHP,str(root/'h5-platform/artisan'),'billing:paypal-prepare','--check-readiness'],
                          cwd=root/'h5-platform',capture_output=True,text=True,timeout=120)
    if result.returncode!=0:raise ValueError('OFFICIAL_READINESS_FAILED')
    report=json.loads(result.stdout)
    required=('configuration_valid','network_requested','merchant_binding_valid','app_authenticated',
              'webhook_registration_valid','product_valid','purchase_readiness_valid')
    if (not isinstance(report,dict) or any(report.get(key) is not True for key in required)
        or report.get('binding_source')!='operator_dashboard' or report.get('diagnostic_code') is not None
        or report.get('missing')!=[] or report.get('api_merchant_identity_verified') is not False
        or report.get('merchant_verified') is not False
        or report.get('official_payment_verification')!='not_performed'):
        raise ValueError('OFFICIAL_READINESS_INCOMPLETE')
    # All resource ownership/amount/event judgments stay in 03, not this tool.


def switch(root,candidate,enable,autoload=None):
    directory=root/'.runtime/paypal-sandbox'
    for parent in (root/'.runtime',directory):
        if parent.is_symlink() or not parent.is_dir() or parent.stat().st_uid!=os.getuid() or parent.stat().st_mode & 0o077:
            raise ValueError('DIRECTORY_INVALID')
    before=state(root,candidate,enable,autoload)
    fd=os.open(directory/'.apply.lock',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        path,old,values,loader,directory,window=state(root,candidate,enable,autoload)
        if old!=before[1] or window!=before[5]:raise ValueError('STATE_CHANGED_BEFORE_LOCK')
        if enable:official_readiness(root,values)
        # A slow official response never authorizes a changed candidate, identity or window.
        again=state(root,candidate,enable,autoload)
        if again[1]!=old or again[5]!=window:raise ValueError('STATE_CHANGED_DURING_READINESS')
        updates={PROVIDER:'paypal'} if enable else {PROVIDER:'unconfigured',PREFIX+'ENABLED':'false'}
        proposed=render_updates(old,values,updates,loader)
        backup_fd,_=tempfile.mkstemp(prefix='env-before-purchase-switch-',dir=directory)
        with os.fdopen(backup_fd,'w') as backup:
            os.fchmod(backup.fileno(),0o600);backup.write(old);backup.flush();os.fsync(backup.fileno())
        final=state(root,candidate,enable,autoload)
        if final[1]!=old or final[5]!=window:raise ValueError('STATE_CHANGED_BEFORE_WRITE')
        atomic_private(path,proposed)
        try:
            final=state(root,candidate,enable,autoload,after_write=True)
            if final[1]!=proposed or final[5]!=window:raise ValueError('STATE_CHANGED_AFTER_WRITE')
        except (OSError,ValueError,KeyError,TypeError,subprocess.SubprocessError):
            raise WrittenButUnverified('PURCHASE_SWITCH_WRITTEN_FINAL_GUARD_FAILED') from None


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--candidate',required=True)
    modes=parser.add_mutually_exclusive_group(required=True)
    modes.add_argument('--enable-purchase',action='store_true')
    modes.add_argument('--disable',action='store_true')
    args=parser.parse_args()
    try:switch(ROOT,args.candidate,args.enable_purchase)
    except WrittenButUnverified:
        raise SystemExit('开关已写但最终核验未完成；保持应用停止，交00使用--disable安全停用并复核，不盲目恢复旧env。')
    except (OSError,ValueError,KeyError,TypeError,subprocess.SubprocessError):
        raise SystemExit('未切换：请核对固定候选、隔离/停机/缓存、当前窗口及03官方就绪检查；不输出凭据或官方响应。')
    print('Sandbox新购已开启；未创建资源或发起付款，Live未开启。' if args.enable_purchase else
          '本工作区Sandbox新购及API已关闭；无网络调用，原身份和配置保留。')


if __name__=='__main__':main()
