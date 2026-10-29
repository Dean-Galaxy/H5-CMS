#!/usr/bin/env python3
"""06 anonymous HTTP and real process/environment probe; no account access."""
from pathlib import Path
import importlib.util,json,subprocess,urllib.request,re
ROOT=Path(__file__).resolve().parents[2]
s=importlib.util.spec_from_file_location('dev',ROOT/'scripts/dev.py');d=importlib.util.module_from_spec(s);s.loader.exec_module(d)
c=d.config();assert c['port']==8377 and c['db_port']==5877
run=d.RUN/'06-rollout/qa-f09-01-20261022'
expected={'H5_LOCAL_MODULES':'acquisition','H5_LOCAL_US_TEST_REGION':'true','ACQUISITION_TRANSPORT':'disabled','ACQUISITION_EXTERNAL_ENABLED':'false'}
processes={}
for n in ['web','queue','scheduler']:
    pid=json.loads((d.RUN/'pids'/f'{n}.json').read_text())['pid']
    raw=subprocess.check_output(['ps','eww','-p',str(pid),'-o','command='],text=True)
    flags={k:bool(re.search(r'(?:^|\s)'+re.escape(k+'='+v)+r'(?:\s|$)',raw)) for k,v in expected.items()}
    processes[n]={'alive':d.process_alive(n),'expectedEnvironmentMatches':flags}
    assert all(flags.values()) and processes[n]['alive']
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
http={}
for endpoint in ['/health','/admin/login']:
    with opener.open('http://127.0.0.1:8377'+endpoint,timeout=20) as response:
        response.read()
        http[endpoint]={'status':response.status,'urlUnchanged':response.url=='http://127.0.0.1:8377'+endpoint}
        assert response.status==200 and http[endpoint]['urlUnchanged']
production=['h5-platform/app','h5-platform/bootstrap','h5-platform/config','h5-platform/database','h5-platform/routes','h5-platform/resources','h5-platform/public','h5-platform/composer.json','h5-platform/composer.lock','scripts/dev.py']
candidate='6b6e7d5149b2e4b599766e22d73ff99e65c159d5'
assert subprocess.run(['git','diff','--quiet',candidate,'--',*production],cwd=ROOT).returncode==0
result={'checkout':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),'applicationCandidate':candidate,'productionTreeMatches':True,'processes':processes,'http':http,'method':'Fresh processes after scheduled stop/start; anonymous HTTP only. Panel registration separately checked by same-checkout CLI bootstrap. No user login or MFA manipulation.'}
(run/'process-http.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
