#!/usr/bin/env python3
"""Real CLI/TTY compatibility in the explicitly synthetic temporary checkout only."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import pty
import select
import stat
import subprocess
import time

CANDIDATE='518c425c3001ee04f24d5b5f79fafabdeb6e61b5'


def tty_run(root,answers):
    master,slave=pty.openpty()
    proc=subprocess.Popen(['/usr/bin/python3',str(root/'scripts/ops/paypal_merchant_receipt.py'),'--candidate',CANDIDATE],
                          cwd=root,stdin=slave,stdout=slave,stderr=slave,start_new_session=True)
    os.close(slave);pending=b'';transcript=b''
    try:
        for prompt,answer in answers:
            deadline=time.monotonic()+30
            marker=prompt.encode()
            while marker not in pending:
                if time.monotonic()>deadline:raise RuntimeError('TTY_PROMPT_TIMEOUT')
                if select.select([master],[],[],0.2)[0]:
                    try:chunk=os.read(master,65536)
                    except OSError:raise RuntimeError('TTY_ENDED_BEFORE_PROMPT') from None
                    if not chunk:raise RuntimeError('TTY_EOF_BEFORE_PROMPT')
                    pending+=chunk;transcript+=chunk
            pending=pending.split(marker,1)[1]
            os.write(master,(answer+'\n').encode())
        deadline=time.monotonic()+30
        while time.monotonic()<deadline:
            if select.select([master],[],[],0.2)[0]:
                try:chunk=os.read(master,65536)
                except OSError:break
                if not chunk:break
                transcript+=chunk
            if proc.poll() is not None:break
        code=proc.wait(timeout=5)
        return code,transcript.decode(errors='replace')
    finally:
        os.close(master)
        if proc.poll() is None:proc.terminate();proc.wait(timeout=5)


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--root',required=True);args=parser.parse_args()
    root=Path(args.root).resolve()
    assert str(root).startswith('/private/tmp/h5-06-binding-compat-')
    actual=subprocess.check_output(['git','-C',str(root),'rev-parse','HEAD'],text=True).strip();assert actual==CANDIDATE
    d=root/'.runtime/paypal-sandbox';cred=d/'credentials.json';env=root/'h5-platform/.env'
    before=cred.read_bytes();env_before=env.read_bytes();values=json.loads(before)['credentials']
    assert values['client_id']=='SYNTHETIC06CLIENT' and values['client_secret']=='SYNTHETIC06NOTAREALSECRET' and values['merchant_id']==''
    assert b'H5_BILLING_PAYPAL_SANDBOX_ENABLED="false"' in env_before
    assert not list(d.glob('merchant-dashboard-*.json'))
    raw=subprocess.check_output(['/opt/homebrew/opt/php@8.4/bin/php',str(root/'h5-platform/artisan'),'billing:paypal-prepare','--binding-context'],cwd=root/'h5-platform',text=True)
    context=json.loads(raw)
    assert context['network_requested'] is False and context['configuration_valid'] is True
    assert context['binding_context']['merchant_id'] in ('',None)
    assert context['binding_context']['client_id_sha256']==hashlib.sha256(values['client_id'].encode()).hexdigest()
    first=('留空取消）：','SYNTHETIC06MERCHANT')
    client_prompt='不要粘贴Client Secret）：'
    confirm='取消： '
    tests=[]
    for name,client,confirmation,expected in (
        ('client_cancel','',[],0),('client_mismatch','SYNTHETICWRONG',[],1),
        ('third_confirmation_cancel',values['client_id'],['yes','yes',''],0),
        ('explicit_all_confirmed',values['client_id'],['yes','yes','yes'],0)):
        code,transcript=tty_run(root,[first,(client_prompt,client)]+[(confirm,v) for v in confirmation])
        hidden=all(value not in transcript for value in (values['client_id'],values['client_secret'],'SYNTHETIC06MERCHANT','SYNTHETICWRONG'))
        assert code==expected and hidden
        if name!='explicit_all_confirmed':
            assert cred.read_bytes()==before and env.read_bytes()==env_before and not list(d.glob('merchant-dashboard-*.json'))
        tests.append({'check':name,'exit_code':code,'inputs_not_echoed':hidden,'passed':True})
    receipts=list(d.glob('merchant-dashboard-*.json'));assert len(receipts)==1
    receipt=json.loads(receipts[0].read_text());staged=json.loads(cred.read_text())['credentials']
    assert receipt['merchant_id']=='SYNTHETIC06MERCHANT' and receipt['binding_source']=='operator_dashboard'
    assert receipt['client_id_sha256']==context['binding_context']['client_id_sha256']
    assert stat.S_IMODE(receipts[0].stat().st_mode)==0o600
    assert staged=={**values,'merchant_id':'SYNTHETIC06MERCHANT'} and env.read_bytes()==env_before
    assert all(receipt[key] is True for key in ('merchant_app_business_link_confirmed','business_merchant_id_confirmed','saved_client_id_matches_app'))
    result={'application_candidate':CANDIDATE,'context_real_cli':True,'context_api_off_empty_merchant':True,
            'context_network_requested':False,'tty_cases':tests,'synthetic_receipt_0600':True,'original_client_and_secret_preserved':True,
            'env_byte_identical':True,'database_started':False,'official_binding_or_authentication':False,
            'real_user_configuration_accessed':False,'scope':'synthetic inputs and confirmations only; not user evidence','passed':True}
    (d/'cli-compat-result.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result))


if __name__=='__main__':main()
