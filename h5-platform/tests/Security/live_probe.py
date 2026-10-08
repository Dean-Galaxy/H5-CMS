#!/usr/bin/env python3
"""Actual loopback HTTP boundaries, explicit checkout config, no external targets."""
import subprocess
import http.cookiejar
import json
import re
import secrets
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[3]
c=json.loads((ROOT/'.runtime/workspace.json').read_text())
assert c['root']==str(ROOT)
s=(ROOT/'h5-platform/.env').read_text()
assert re.search(r'^APP_ENV="?local"?$',s,re.M) and re.search(r'^MAIL_MAILER="?preview"?$',s,re.M)
base=f'http://127.0.0.1:{c["port"]}'
checks=[]
def client():
 return urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
a=client();b=client()
def request(path, method='GET', payload=None, headers=None, using=a):
 if path.startswith(base):path=path[len(base):]
 assert path.startswith('/') and not path.startswith('//')
 h={'Accept':'application/json',**(headers or {})}
 data=None
 if payload is not None:data=json.dumps(payload).encode();h['Content-Type']='application/json'
 try:r=using.open(urllib.request.Request(base+path,data=data,method=method,headers=h),timeout=20)
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read()
def check(name,result):
 checks.append({'name':name,'passed':bool(result)})
 if not result:raise AssertionError(name)
code,h,raw=request('/health');health=json.loads(raw)
check('default modules reflect accepted stage',code==200 and health['modules']['audience']=='ready' and health['modules']['acquisition']=='not_ready')
for path in ('/.env','/.git/config','/storage/logs/laravel.log','/vendor/autoload.php'):
 code,_,_=request(path);check('private file denied '+path,code in (403,404))
code,_,_=request('/api/v1/auth/csrf',headers={'Host':'untrusted.invalid'});checks.append({'name':'untrusted Host in local runtime','observed_status':code,'external_status':'not_verified; local TrustHosts bypass must not be deployed'})
code,h,raw=request('/api/v1/auth/csrf',headers={'X-Request-ID':'attacker-supplied'})
token=json.loads(raw)['csrf_token']
check('server request id replaces client value',bool(h.get('X-Request-ID')) and h.get('X-Request-ID')!='attacker-supplied')
check('security response headers',h.get('X-Content-Type-Options')=='nosniff' and h.get('X-Frame-Options')=='DENY')
cookies=h.get_all('Set-Cookie',[])
check('local viewer cookie httponly samesite',any('_viewer=' in x and 'httponly' in x.lower() and 'samesite=lax' in x.lower() for x in cookies))
drama=json.loads(request('/api/v1/catalog')[2])['items'][0]
first=drama['episodes'][0];sixth=drama['episodes'][5]
for path,method in (('/api/v1/auth/register','POST'),('/membership/checkout','POST'),('/audience/dramas/'+drama['id']+'/episodes/'+first['id']+'/playback','POST'),('/api/v1/me/progress/'+drama['id'],'PUT')):
 code,_,_=request(path,method,{});check('real csrf rejects '+path.split('/')[1]+' '+method,code==419)
code,_,raw=request('/api/v1/episodes/'+first['id']+'/playback-grants','POST',{'play_session_id':None},{'X-CSRF-TOKEN':token})
grant=json.loads(raw);check('free grant real provider',code==200 and grant['allowed'])
url=grant['short_lived_url']
code,_,_=request(url,using=b);check('copied signed media denied in different cookie jar',code in (403,404))
code,_,_=request(urllib.parse.urlsplit(url).path);check('media without signature denied',code in (403,404))
code,_,raw=request('/api/v1/episodes/'+sixth['id']+'/playback-grants','POST',{'play_session_id':None},{'X-CSRF-TOKEN':token})
deny=json.loads(raw);check('member denial leaks no media grant',code==200 and not deny['allowed'] and all(deny.get(k) is None for k in ('short_lived_url','grant_id','play_session_id','expires_at')))
code,_,raw=request('/webhooks/stripe','POST',{}, {'Stripe-Signature':'invalid'});check('unconfigured stripe webhook cannot accept false payment',code==503)
code,_,raw=request('/api/v1/not-a-route');check('error no debug stack',code==404 and not any(x in raw for x in (b'"trace"',b'"file"',b'"exception"')))
report={'result':'passed','checks':checks,'scope':'real loopback HTTP; no TLS or external mail/Stripe claims','checkout_commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()}
(ROOT/'.runtime/06/security-http.json').write_text(json.dumps(report,indent=2))
print(json.dumps(report,indent=2))
