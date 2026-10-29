import hashlib,http.cookiejar,json,os,re,subprocess,sys,urllib.request,urllib.error,uuid
from pathlib import Path
ROOT=Path(os.environ['H5_ANALYTICS_ACCEPTANCE_ROOT']).resolve()
sys.path.insert(0,str(ROOT/'scripts/ops'))
import analytics_browser_fixture as fixture
from analytics_acceptance import candidate_guard,private_json,validate_workspace
sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip();candidate_guard(ROOT,sha)
c=validate_workspace(ROOT,private_json(ROOT/'.runtime/workspace.json'))
record=private_json(fixture.DIRECTORY/'process.json');assert record['candidate']==sha and fixture.matching(record,c)
base='http://127.0.0.1:'+str(c['port']);jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(jar));checks=[]
def request(path,body=None,headers=None):
 assert path.startswith('/') and not path.startswith('//')
 h={'Accept':'application/json','User-Agent':'06 actual HTTP acceptance'};h.update(headers or {})
 if body is not None:h['Content-Type']='application/json'
 req=urllib.request.Request(base+path,data=None if body is None else json.dumps(body).encode(),headers=h)
 try:r=opener.open(req,timeout=20)
 except urllib.error.HTTPError as e:r=e
 with r:return r.status,r.read().decode(),dict(r.headers)
def counts():
 env=fixture.environment(c,sha);env.update(PGHOST='127.0.0.1',PGPORT=str(c['db_port']),PGUSER=c['user'],PGPASSWORD=env['DB_PASSWORD'],PGDATABASE=c['db']+'_test')
 sql="SELECT json_build_object('subjects',(select count(*) from acquisition_subjects),'sources',(select count(*) from acquisition_sources),'behaviors',(select count(*) from acquisition_behaviors),'keys',(select count(*) from acquisition_event_keys))"
 return json.loads(subprocess.check_output(['/opt/homebrew/opt/postgresql@17/bin/psql','-XAt','-v','ON_ERROR_STOP=1','-c',sql],env=env,text=True))
def check(name,condition,**kw):
 checks.append({'name':name,'passed':bool(condition),**kw});assert condition,name
status,html,_=request('/',headers={'Accept':'text/html'});assert status==200
token=re.search(r'<meta name="csrf-token" content="([^"]+)"',html).group(1);headers={'Origin':base,'X-CSRF-TOKEN':token}
f=private_json(fixture.DIRECTORY/'fixture.json');event={'event_id':str(uuid.uuid4()),'event_name':'page_view','properties':{'page_instance_id':str(uuid.uuid4()),'path':'/catalog'}}
bodies={'consent':{'analytics':True,'advertising':False,'policy_version':'analytics-v1'},'events':{'events':[event]},'landing':{'page_instance_id':str(uuid.uuid4()),'drama_id':f['drama_id'],'path':'/dramas/'+f['drama_id']}}
before=counts()
for endpoint,body in bodies.items():
 for mode,head,expected in [('missing_token',{},419),('wrong_token',{'X-CSRF-TOKEN':'invalid'},419),('foreign_origin',{'Origin':'https://foreign.invalid','X-CSRF-TOKEN':token},403)]:
  status,_,_=request('/api/v1/tracking/'+endpoint,body,head);check(endpoint+'_'+mode,status==expected,status=status)
check('rejected_mutations_do_not_create_sources_subjects_events_keys',counts()==before)
status,_,_=request('/api/v1/tracking/consent',bodies['consent'],headers);check('normal_same_origin_consent',status==200,status=status)
start=counts()
for _ in range(2):
 status,body,_=request('/api/v1/tracking/events',bodies['events'],headers);check('same_uuid_accepted',status==200 and json.loads(body).get('accepted')==1,status=status)
check('same_uuid_durable_once',counts()['behaviors']==start['behaviors']+1)
changed=json.loads(json.dumps(bodies['events']));changed['events'][0]['properties']['path']='/'
status,body,_=request('/api/v1/tracking/events',changed,headers);check('changed_uuid_rejected',status==200 and json.loads(body).get('rejected')==1,status=status)
for name,body,expected in [('over_20_events',{'events':[event]*21},422),('over_32k_body',{'events':[event],'padding':'x'*33000},413),('unknown_envelope',{'events':[event],'viewer_id':str(uuid.uuid4())},422)]:
 status,_,_=request('/api/v1/tracking/events',body,headers);check(name,status==expected,status=status)
status,_,_=request('/api/v1/tracking/consent',{'analytics':False,'advertising':False,'policy_version':'analytics-v1'},headers);check('withdrawal',status==200)
event['event_id']=str(uuid.uuid4());start=counts();status,body,_=request('/api/v1/tracking/events',{'events':[event]},headers);check('withdrawn_no_capture',status==200 and json.loads(body).get('accepted')==0 and counts()['behaviors']==start['behaviors'])
for _ in range(125):
 status,_,response_headers=request('/api/v1/tracking/context')
 if status==429:break
check('actual_http_rate_limit',status==429 and any(k.lower()=='retry-after' for k in response_headers),status=status)
os.umask(0o077);report={'candidate':sha,'script_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),'checks':checks,'passed':all(x['passed'] for x in checks),'real_http':True,'csrf_disabled':False,'proxy_bypassed':True,'provider_calls':False};(fixture.DIRECTORY/'http-probe-safe.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps({'checks':len(checks),'passed':report['passed'],'candidate':sha}))
