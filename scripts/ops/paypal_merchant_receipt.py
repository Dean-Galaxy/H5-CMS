#!/usr/bin/env python3
"""Private TTY-only merchant completion and explicit human Dashboard receipt.

Never authenticates PayPal, binds the database, enables API, or claims an
unconfirmed relationship. Run only after 00 accepts the matching candidate.
"""
import argparse
import datetime
import fcntl
import getpass
import hashlib
import json
import os
import re
import subprocess
import sys
import uuid
from paypal_apply_credentials import (ROOT, PREFIX, read_private, bound_environment,
                                      saved_document, atomic_private)
from paypal_complete_webhook_url import candidate_ready

CONFIRMATIONS = (
    ('merchant_app_business_link_confirmed', '我已在官方Sandbox Merchant App页面核对：此App关联的正是本次Business账号'),
    ('business_merchant_id_confirmed', '我已打开上述同一Business账号详情，并核对本次Merchant/Account ID'),
    ('saved_client_id_matches_app', '我已在本机核对：已保存Client ID与上述同一个Sandbox App一致（不要将Client ID发到聊天）'),
)


def offline_context(root):
    result=subprocess.run(['/opt/homebrew/opt/php@8.4/bin/php',str(root/'h5-platform/artisan'),
                           'billing:paypal-prepare','--binding-context'],cwd=root/'h5-platform',
                          capture_output=True,text=True,timeout=15)
    if result.returncode!=0:raise ValueError('OFFLINE_CONTEXT_UNAVAILABLE')
    output=json.loads(result.stdout)
    if output.get('network_requested') is not False or output.get('configuration_valid') is not True or output.get('diagnostic_code') is not None:
        raise ValueError('OFFLINE_CONTEXT_UNAVAILABLE')
    return output['binding_context']


def checked_state(root, candidate, autoload=None):
    candidate_ready(root,candidate)
    _,env_raw,existing,_,directory=bound_environment(root,autoload)
    if existing.get(PREFIX+'ENABLED','false') not in ('false','(false)',''):
        raise ValueError('DISABLE_API_BEFORE_RECEIPT')
    document=saved_document(root);values=document['credentials']
    for field in ('client_id','client_secret','merchant_scope'):
        if not values[field] or values[field]!=existing.get(PREFIX+field.upper()):
            raise ValueError('STAGED_AND_APPLIED_IDENTITY_MISMATCH')
    staged,applied=values['merchant_id'],existing.get(PREFIX+'MERCHANT_ID','')
    if staged and applied and staged!=applied:raise ValueError('MERCHANT_REBIND_REFUSED')
    context=offline_context(root)
    if (not isinstance(context,dict) or set(context)!={'environment','merchant_scope','merchant_id','client_id_sha256'}
        or context['environment']!='sandbox' or context['merchant_scope']!=values['merchant_scope']
        or context['client_id_sha256']!=hashlib.sha256(values['client_id'].encode()).hexdigest()
        or (context['merchant_id'] or '')!=applied):
        raise ValueError('OFFICIAL_MODULE_CONTEXT_MISMATCH')
    snapshot=(env_raw,read_private(directory/'credentials.json'))
    return document,existing,context,snapshot


def locked(root):
    directory=root/'.runtime/paypal-sandbox'
    for parent in (root/'.runtime',directory):
        if parent.is_symlink() or not parent.is_dir() or parent.stat().st_uid!=os.getuid() or parent.stat().st_mode & 0o077:
            raise ValueError('DIRECTORY_INVALID')
    fd=os.open(directory/'.apply.lock',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
    stream=os.fdopen(fd,'w')
    try:fcntl.flock(stream,fcntl.LOCK_EX|fcntl.LOCK_NB)
    except BaseException:stream.close();raise
    return stream



def prompt_confirmations(ask=input):
    answers={}
    for key,label in CONFIRMATIONS:
        answer=ask(label+'。仅完成后输入 yes；留空/其他输入取消： ')
        if answer.strip().lower()!='yes':return None
        answers[key]=True
    return answers


class StagedButUnconfirmed(ValueError):
    pass


def complete(root,candidate,merchant,confirmed,expected_snapshot,expected_context,autoload=None):
    if not isinstance(merchant,str) or not re.fullmatch('[A-Z0-9]{1,64}',merchant):
        raise ValueError('MERCHANT_ID_INVALID')
    if set(confirmed)!=set(key for key,_ in CONFIRMATIONS) or any(value is not True for value in confirmed.values()):
        raise ValueError('EXPLICIT_CONFIRMATIONS_REQUIRED')
    with locked(root):
        doc,existing,context,current=checked_state(root,candidate,autoload)
        if current!=expected_snapshot or context!=expected_context:raise ValueError('IDENTITY_CHANGED_AFTER_CONFIRMATION')
        for old in (doc['credentials']['merchant_id'],existing.get(PREFIX+'MERCHANT_ID',''),context['merchant_id']):
            if old and old!=merchant:raise ValueError('MERCHANT_REBIND_REFUSED')
        evidence='dashboard-'+uuid.uuid4().hex
        receipt={**context,'merchant_id':merchant,'binding_source':'operator_dashboard',
                 'verified_at':datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
                 'evidence_ref':evidence,**confirmed}
        path=root/'.runtime/paypal-sandbox'/('merchant-'+evidence+'.json')
        staged=False
        try:
            doc['credentials']['merchant_id']=merchant
            staged=True  # Conservative: even an interrupted replace may have persisted.
            atomic_private(root/'.runtime/paypal-sandbox/credentials.json',json.dumps(doc)+'\n')
            latest,_,new_context,after=checked_state(root,candidate,autoload)
            if latest!=doc or after[0]!=expected_snapshot[0] or new_context!=expected_context:
                raise ValueError('IDENTITY_CHANGED_DURING_COMPLETION')
            fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            with os.fdopen(fd,'w') as stream:
                json.dump(receipt,stream,indent=2);stream.write('\n');stream.flush();os.fsync(stream.fileno())
            latest,_,new_context,after=checked_state(root,candidate,autoload)
            if latest!=doc or after[0]!=expected_snapshot[0] or new_context!=expected_context:
                raise ValueError('IDENTITY_CHANGED_DURING_RECEIPT')
        except BaseException:
            path.unlink(missing_ok=True)
            if staged:raise StagedButUnconfirmed('STAGED_WITHOUT_VALID_RECEIPT') from None
            raise
        return path


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--candidate',required=True)
    args=parser.parse_args()
    try:
        if not sys.stdin.isatty():raise ValueError('PRIVATE_TTY_REQUIRED')
        _,_,context,before=checked_state(ROOT,args.candidate)
        merchant=getpass.getpass('上述同一Sandbox Business的Merchant/Account ID（不回显；留空取消）：')
        if not merchant:
            print('已取消；未修改或生成核对回执。');return
        if not re.fullmatch('[A-Z0-9]{1,64}',merchant):raise ValueError('MERCHANT_ID_INVALID')
        answers=prompt_confirmations()
        if answers is None:
            print('已取消；未修改或生成核对回执。');return
        path=complete(ROOT,args.candidate,merchant,answers,before,context)
        print('Merchant ID与人工核对回执已私密保存：'+str(path))
        print('尚未应用env、调用官方API或绑定账务；由00安全应用后调用03绑定CLI。')
    except StagedButUnconfirmed:
        raise SystemExit('Merchant ID可能已暂存，但没有有效回执；尚未完成，请00检查后仅以相同ID重试，不重填或覆盖凭据。')
    except (KeyboardInterrupt,EOFError):
        print('\n已取消；未生成核对回执。')
    except (OSError,ValueError,KeyError,TypeError,subprocess.SubprocessError):
        raise SystemExit('未完成：请核对候选、停机/API关闭、源凭据与已应用身份及03离线上下文；没有生成绑定或启用付款。')


if __name__=='__main__':main()
