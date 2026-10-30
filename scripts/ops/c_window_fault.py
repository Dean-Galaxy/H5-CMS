#!/usr/bin/env python3
"""Arm/clear a token-scoped 180s PG write-path fault in the exact live synthetic C window."""
import argparse
import json
import os
from pathlib import Path
import re
import secrets
import time
from analytics_acceptance import private_json
import c_window as window
from c_window_preflight import root_guard, private_document, validate_manifest, workspace_binding

ROOT=Path(__file__).resolve().parents[2]


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action',choices=['arm','clear'])
    parser.add_argument('--candidate',required=True)
    parser.add_argument('--nonce')
    args=parser.parse_args()
    root_guard(ROOT)
    if not re.fullmatch('[a-f0-9]{40}',args.candidate): raise ValueError('FULL_CANDIDATE_REQUIRED')
    directory=ROOT/'.runtime/06/analytics-browser'
    file=directory/'c-store-fault.json'
    if args.action=='clear':
        if not args.nonce or not re.fullmatch('[a-f0-9]{64}',args.nonce): raise ValueError('EXACT_FAULT_TOKEN_REQUIRED')
        record=private_json(file)
        if record.get('candidate')!=args.candidate or not secrets.compare_digest(record.get('nonce',''),args.nonce): raise ValueError('FOREIGN_FAULT_RECORD')
        file.unlink();print(json.dumps({'cleared':True}));return
    doc=private_document(ROOT/'.runtime/06/c-window/manifest.json',ROOT)
    validate_manifest(ROOT,args.candidate,doc)
    cfg=workspace_binding(ROOT,private_json(ROOT/'.runtime/workspace.json'))
    record=private_json(ROOT/'.runtime/06/c-window/process.json')
    if cfg!=doc['workspace'] or record.get('candidate')!=args.candidate or not window.matching(record,cfg): raise ValueError('OWN_LIVE_C_PROCESS_REQUIRED')
    window.private_dir(directory)
    os.umask(0o077)
    nonce=secrets.token_hex(32)
    fd=os.open(file,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as stream:json.dump({'candidate':args.candidate,'nonce':nonce,'expires_at':int(time.time())+180},stream)
    print(json.dumps({'nonce':nonce,'expires_in_seconds':180})) # consumed privately by the browser driver; never public evidence


if __name__=='__main__':
    try:main()
    except (OSError,ValueError,KeyError):raise SystemExit('06 C fault refused. Preserve own private fault record for review.') from None
