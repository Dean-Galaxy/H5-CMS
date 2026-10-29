#!/usr/bin/env python3
"""06 own workspace restart evidence; hashes content state, never exports credentials."""
import argparse, hashlib, importlib.util, json, subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser(description=__doc__);p.add_argument('phase',choices=['before','after']);a=p.parse_args()
spec=importlib.util.spec_from_file_location('f09dev',ROOT/'scripts/dev.py');dev=importlib.util.module_from_spec(spec);spec.loader.exec_module(dev)
c=dev.config();assert Path(c['root']).resolve()==ROOT
run=ROOT/'.runtime/06-f09';run.mkdir(exist_ok=True)
def query(sql):return subprocess.check_output([str(dev.PG/'psql'),'-X','-At','-v','ON_ERROR_STOP=1','-c',sql],env=dev.pg_env(),text=True).strip()
state={}
for table in ['content_dramas','content_episodes','content_plans','content_publications']:
 raw=query(f'SELECT row_to_json(t)::text FROM "{table}" t ORDER BY row_to_json(t)::text')
 state[table]={'rows':int(query(f'SELECT count(*) FROM "{table}"')),'sha256':hashlib.sha256(raw.encode()).hexdigest()}
r={'candidate':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),'database':c['db'],'port':c['port'],'dbPort':c['db_port'],'content':state,'pids':{},'alive':{}}
for name in ['web','queue','scheduler']:
 r['pids'][name]=json.loads((ROOT/'.runtime/pids'/f'{name}.json').read_text())['pid'];r['alive'][name]=dev.process_alive(name)
r['postgresPid']=int((ROOT/'.runtime/postgres/postmaster.pid').read_text().splitlines()[0])
if a.phase=='after':
 before=json.loads((run/'restart-before.json').read_text());r['contentUnchanged']=before['content']==state;r['allProcessesReplaced']=all(r['pids'][n]!=before['pids'][n] for n in r['pids']) and r['postgresPid']!=before['postgresPid'];r['beforePids']=before['pids'];r['passed']=r['contentUnchanged'] and r['allProcessesReplaced'] and all(r['alive'].values());assert r['passed']
(run/('restart-before.json' if a.phase=='before' else 'restart.json')).write_text(json.dumps(r,indent=2)+'\n');print(json.dumps({'phase':a.phase,'passed':r.get('passed'),'contentRows':{k:v['rows'] for k,v in state.items()},'alive':r['alive']}))
