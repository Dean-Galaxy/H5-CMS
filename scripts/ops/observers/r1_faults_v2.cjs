/* Candidate-external observer. One existing fault scenario per invocation; never re-register an existing viewer. */
'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const core=require('./r1_faults_core.cjs');
const SOURCE=path.resolve(__dirname,'../../..'),OUT=path.join(core.ROOT,'.runtime/06/r1'),PURPOSE='/api/v3/measurement/purposes/';
process.umask(0o077);
function digest(bytes){return crypto.createHash('sha256').update(bytes).digest('hex');}
function privateJSON(file){const s=fs.lstatSync(file);assert(s.isFile()&&!s.isSymbolicLink()&&s.uid===process.getuid()&&s.nlink===1&&!(s.mode&0o077));assert.equal(fs.realpathSync(file),file);return JSON.parse(fs.readFileSync(file,'utf8'));}
function save(file,value){assert.equal(path.resolve(file),file);assert(file.startsWith(OUT+'/'));fs.mkdirSync(path.dirname(file),{recursive:true,mode:0o700});fs.writeFileSync(file,JSON.stringify(value,null,2),{mode:0o600,flag:'wx'});}
function control(action,label){return JSON.parse(cp.execFileSync('python3',['-B','scripts/ops/r1_window.py',action,'--candidate',core.RUNTIME,...(label?['--label',label]:[])],{cwd:core.ROOT,stdio:'pipe'}).toString());}
function actor(label){const file=path.join(OUT,'fixtures','actor-'+label+'.json');if(!fs.existsSync(file))control('actor',label);const a=privateJSON(file);assert.equal(a.kind,'automation-material');assert.equal(control('actor-status',label).exists,false,'Existing viewer: no repeated registration');return a;}
async function api(page,url,method='GET',body){return page.evaluate(async({url,method,body})=>{let csrf='';if(method!=='GET'){const r=await fetch('/api/v1/auth/csrf',{headers:{Accept:'application/json'},cache:'no-store'});csrf=(await r.json()).csrf_token;}const r=await fetch(url,{method,credentials:'same-origin',headers:{Accept:'application/json',...(method!=='GET'?{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}:{})},...(body?{body:JSON.stringify(body)}:{})});let data;try{data=await r.json();}catch{data=null;}return {status:r.status,data,retry_after:r.headers.get('retry-after')};},{url,method,body});}
const command=(purpose,choice,revision)=>({purpose,choice,notice_version:'c-purpose-choice-only-r1-v1',expected_revision:revision,operation_id:crypto.randomUUID()});
async function waitStatus(p,purpose,values){await p.waitForFunction(({purpose,values})=>values.includes(document.querySelector('[data-purpose-state="'+purpose+'"]')?.dataset.status),{purpose,values},{timeout:15000});}
async function screenshot(p,file){await p.screenshot({path:file,fullPage:true,mask:[p.locator('input[name="name"],input[name="email"],input[type="password"]')]});fs.chmodSync(file,0o600);}
function parse(argv){assert.equal(argv.length,3,'Usage: CASE en|zh_CN OBSERVER_FULL_SHA');const input=core.caseInput(argv[0],argv[1]);assert(/^[a-f0-9]{40}$/.test(argv[2]));return {...input,observer:argv[2]};}
function preflight(input){
 assert.equal(fs.realpathSync(core.ROOT),core.ROOT);assert.equal(fs.realpathSync(SOURCE),SOURCE);assert.notEqual(SOURCE,core.ROOT);assert(!fs.existsSync(path.join(SOURCE,'.runtime/workspace.json')),'Source-only observer checkout required');
 assert.equal(cp.execFileSync('git',['rev-parse','HEAD'],{cwd:SOURCE,encoding:'utf8'}).trim(),input.observer);
 const files=['scripts/ops/observers/r1_faults_v2.cjs','scripts/ops/observers/r1_faults_core.cjs'],hashes={};
 for(const file of files){const bytes=fs.readFileSync(path.join(SOURCE,file));assert.deepEqual(cp.execFileSync('git',['show',input.observer+':'+file],{cwd:SOURCE}),bytes);hashes[file]=digest(bytes);}
 const manifest=privateJSON(path.join(OUT,'manifest.json')),processRecord=privateJSON(path.join(OUT,'process.json'));core.validateTarget(core.ROOT,manifest,processRecord);
 cp.execFileSync('python3',['-B','-c','import sys,hashlib;sys.path.insert(0,"scripts/ops");import r1_window as r;c,d=r.manifest(sys.argv[1],future=True);p=r.private_json(r.OUT/"process.json");assert p["role"]=="manual" and r.matching(p,c);assert p["manifest_sha256"]==hashlib.sha256((r.OUT/"manifest.json").read_bytes()).hexdigest()',core.RUNTIME],{cwd:core.ROOT,stdio:'pipe'});
 return {runtime_sha:core.RUNTIME,observer_sha:input.observer,source_sha256:hashes,manifest_sha256:digest(fs.readFileSync(path.join(OUT,'manifest.json'))),policy_from:manifest.policy_from,policy_until:manifest.policy_until,source_root:SOURCE,runtime_root:core.ROOT};
}
async function main(){
 const input=parse(process.argv.slice(2)),binding=preflight(input),directory=path.join(OUT,'external-faults-v2',input.name,input.locale);assert(!fs.existsSync(directory),'Existing case attempt: inspect it; no automatic replay');
 const lock=path.join(OUT,'external-faults-v2','active-case-private.json'),token=crypto.randomUUID();fs.mkdirSync(path.dirname(lock),{recursive:true,mode:0o700});fs.writeFileSync(lock,JSON.stringify({pid:process.pid,token,case:input.name,locale:input.locale}),{mode:0o600,flag:'wx'});
 try {
 const label='auto-fv2-'+input.name+'-'+(input.locale==='en'?'en':'zh'),a=actor(label);assert(label.length<=48);
 fs.mkdirSync(directory,{recursive:true,mode:0o700});save(path.join(directory,'started.json'),{...binding,...input,actor_label:label,at:new Date().toISOString()});
 const {chromium}=require('/Users/hejunxiang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js');
 let browser,page,phase='launch',registrations=0,patches=[],responses=[],injected=[],cas={},snapshotBefore,registrationStatus,routeError=null;
 const safeRoute=handler=>async route=>{try{await handler(route);}catch(error){routeError=String(error.stack);try{await route.abort();}catch{}}};
 try{
  browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});const context=await browser.newContext({viewport:{width:390,height:844},locale:input.locale==='en'?'en-US':'zh-CN'});await context.route('**/*',r=>core.allowRequest(r.request().url())?r.continue():r.abort());page=await context.newPage();
  page.on('request',r=>{if(r.method()==='POST'&&new URL(r.url()).pathname==='/api/v1/auth/register')registrations++;if(r.method()==='PATCH'&&r.url().endsWith(PURPOSE+'preferences'))patches.push(JSON.parse(r.postData()));});
  page.on('response',r=>{if(r.request().method()==='PATCH'&&r.url().endsWith(PURPOSE+'preferences'))responses.push({status:r.status(),purpose:JSON.parse(r.request().postData()).purpose});});
  phase='initial-context';await page.goto(core.ORIGIN+'/identity/register?lang='+input.locale);await waitStatus(page,'account_link',['not_requested']);
  const initial=await api(page,PURPOSE+'context');assert.equal(initial.status,200);assert.equal(initial.data.purposes.account_link.effective,false);assert.equal(initial.data.purposes.advertising.effective,false);
  if(input.name==='actual-cas'){const first=await api(page,PURPOSE+'preferences','PATCH',command('account_link','deny',0));assert.equal(first.status,200);assert.equal(first.data.state.revision,1);snapshotBefore=control('fingerprint').tables.acquisition_r1_proofs;await page.reload();await waitStatus(page,'account_link',['not_requested']);}
  let first=true;
  if(input.name==='lost-registration-json')await page.route('**/api/v1/auth/register',safeRoute(async route=>{const response=await route.fetch();registrationStatus=response.status();if(!response.ok())return route.fulfill({response});injected.push({phase:'registration_response',kind:'malformed_json_after_actual_commit',server_status:response.status()});await route.fulfill({response,body:'{malformed'});}));
  if(input.name==='identity-unavailable')await page.route('**/api/v1/auth/me',route=>{injected.push({phase:'identity_read',status:503});return route.fulfill({status:503,contentType:'application/json',body:'{}'});});
  if(!['lost-registration-json','identity-unavailable'].includes(input.name))await page.route('**/api/v3/measurement/purposes/preferences',safeRoute(async route=>{
   const request=JSON.parse(route.request().postData()),target=input.name==='partial-503'?'advertising':'account_link';if(request.purpose!==target||!first)return route.continue();first=false;
   if(input.name==='actual-cas'){const competing=core.competingAllow(request),response=await route.fetch({postData:JSON.stringify(competing)}),body=await response.json();assert.equal(response.status(),200);assert.equal(body.status,'saved');assert.equal(body.state.choice,'allow');assert.equal(body.state.revision,request.expected_revision+1);assert.equal(body.state.effective,false);cas={original:request,competing,competition_state:body.state};return route.continue();}
   if(input.name==='lost-purpose-json'){const response=await route.fetch();assert.equal(response.status(),200);injected.push({phase:'purpose_response',kind:'malformed_json_after_actual_save',server_status:200});return route.fulfill({response,body:'{malformed'});}
   if(input.name==='network-before-purpose'){injected.push({phase:'before_purpose_request',kind:'client_abort_no_server_call'});return route.abort('internetdisconnected');}
   const status=input.name==='partial-503'?503:Number(input.name.split('-')[1]);injected.push({phase:'purpose_response',kind:'explicit_client_fixture',status});return route.fulfill({status,contentType:'application/json',headers:status===429?{'Retry-After':'2'}:{},body:JSON.stringify({error:{code:'CONTROLLED_CLIENT_FAULT'}})});
  }));
  phase='registration-form';for(const [field,value] of Object.entries({name:a.name,email:a.email,password:a.password,password_confirmation:a.password}))await page.locator('[name="'+field+'"]').fill(value);await page.locator('[name="terms_accepted"]').check();for(const purpose of ['account_link','advertising'])await page.locator('[data-registration-purpose="'+purpose+'"]').check();
  // Global observer pacing preserves scope/IP. It does not grant extra budget; real 429 stops this case.
  const ledger=path.join(OUT,'external-faults-v2','last-registration-private.json');let delay=13000;if(fs.existsSync(ledger))delay=Math.max(0,12500-(Date.now()-privateJSON(ledger).at));if(delay)await new Promise(r=>setTimeout(r,delay));
  const temp=ledger+'.'+crypto.randomUUID();fs.writeFileSync(temp,JSON.stringify({at:Date.now(),case:input.name,locale:input.locale}),{mode:0o600,flag:'wx'});fs.renameSync(temp,ledger);
  const requestResponse=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/v1/auth/register'&&r.request().method()==='POST');await page.locator('form[data-registration-r1] button[type="submit"]').click();const registrationResponse=await requestResponse;
  if(registrationResponse.status()===429){save(path.join(directory,'registration-limited.json'),{...binding,retry_after:registrationResponse.headers()['retry-after'],no_automatic_retry:true});throw Error('Actual registration budget limited: wait Retry-After; no account replay');}
  assert(registrationResponse.ok());await page.waitForFunction(()=>document.querySelector('[data-registration-r1]')?.dataset.registrationComplete==='true',{},{timeout:20000});assert.equal(registrations,1);
  phase='fault-completion';
  if(input.name==='lost-registration-json'){await waitStatus(page,'account_link',['saved']);await waitStatus(page,'advertising',['saved']);assert(registrationStatus>=200&&registrationStatus<300);const text=await page.locator('[data-result]').textContent(),words=await page.locator('[data-purpose-messages]').textContent();assert.equal(text,JSON.parse(words).current_account);}
  else if(['status-401','status-403'].includes(input.name)){
   await page.waitForFunction(()=>{const f=document.querySelector('[data-registration-r1]'),w=JSON.parse(f.querySelector('[data-purpose-messages]').textContent);return f.querySelector('[data-result]').textContent===w.account_unknown&&[...f.querySelectorAll('[data-registration-purpose]')].every(n=>!n.checked)&&[...f.querySelectorAll('[data-purpose-retry]')].every(n=>n.hidden);},{},{timeout:15000});
   assert.equal(responses.filter(r=>r.purpose==='account_link'&&r.status===Number(input.name.split('-')[1])).length,1);const state=await page.evaluate(()=>{const f=document.querySelector('[data-registration-r1]');return {result:f.querySelector('[data-result]').textContent,expected:JSON.parse(f.querySelector('[data-purpose-messages]').textContent).account_unknown,unchecked:[...f.querySelectorAll('[data-registration-purpose]')].every(n=>!n.checked),hiddenRetries:[...f.querySelectorAll('[data-purpose-retry]')].every(n=>n.hidden)};});
   const count=patches.length;await page.waitForTimeout(500);assert.equal(patches.length,count);assert(core.identityFailureSettled({...state,purposeResponses:responses.filter(r=>r.purpose==='account_link').length,advertisingRequests:patches.filter(p=>p.purpose==='advertising').length}));
  }else{
   const target=input.name==='partial-503'?'advertising':'account_link';await waitStatus(page,target,[input.name==='actual-cas'?'conflict':'unconfirmed']);
   if(input.name==='actual-cas'){const conflict=responses.filter(r=>r.purpose==='account_link').at(-1);assert.equal(conflict.status,409);assert.equal(cas.competition_state.revision,2);}
   if(input.name==='partial-503')await waitStatus(page,'account_link',['saved']);if(input.name==='identity-unavailable')assert.equal(patches.length,0);
   if(input.name==='status-429')assert.equal(await page.locator('[data-purpose-retry="account_link"]').isDisabled(),true);
   const before=patches.length;await screenshot(page,path.join(directory,'before-explicit-retry.png'));await page.waitForTimeout(400);assert.equal(patches.length,before,'No automatic replay');
   if(input.name==='status-429')await page.locator('[data-purpose-retry="account_link"]').waitFor({state:'visible'}).then(()=>page.waitForFunction(()=>!document.querySelector('[data-purpose-retry="account_link"]').disabled,{},{timeout:5000}));
   if(input.name==='identity-unavailable')await page.unroute('**/api/v1/auth/me');phase='explicit-one-purpose-retry';const retried=page.waitForResponse(r=>r.request().method()==='PATCH'&&r.url().endsWith(PURPOSE+'preferences')&&JSON.parse(r.request().postData()).purpose===target);await page.locator('[data-purpose-retry="'+target+'"]').click();const response=await retried;assert.equal(response.status(),200);const result=await response.json();
   if(input.name==='actual-cas'){assert(core.acceptedRetry(result,2));await waitStatus(page,target,['unchanged']);const commands=patches.filter(p=>p.purpose==='account_link');assert.equal(commands.length,3);assert.notEqual(commands[1].operation_id,commands[2].operation_id);assert.equal(commands[2].expected_revision,2);cas.retry=commands[2];cas.retry_result=result;const snapshotAfter=control('fingerprint').tables.acquisition_r1_proofs;assert.deepEqual(snapshotBefore,snapshotAfter);cas.proof_deadline_rows_unchanged=true;}
   else await waitStatus(page,target,['saved']);
   if(input.name==='identity-unavailable'){await page.locator('[data-purpose-retry="advertising"]').click();await waitStatus(page,'advertising',['saved']);}
   if(input.name==='lost-purpose-json'){const commands=patches.filter(p=>p.purpose==='account_link');assert.equal(commands.length,2);assert.equal(commands[0].operation_id,commands[1].operation_id);assert.equal(result.status,'saved');}
   if(input.name==='partial-503')assert.equal(patches.filter(p=>p.purpose==='account_link').length,1);
  }
  phase='account-and-final-context';assert.equal(registrations,1);const account=control('actor-status',label);assert(account.exists&&account.receipt_exists&&account.receipt_versions_match);assert.equal(await page.locator('[name="password"]').inputValue(),'');
  const final=await api(page,PURPOSE+'context');assert.equal(final.status,200);for(const purpose of ['account_link','advertising'])assert.equal(final.data.purposes[purpose].effective,false);await screenshot(page,path.join(directory,'completed.png'));
  assert.equal(preflight(input).manifest_sha256,binding.manifest_sha256);assert.equal(routeError,null);
  save(path.join(directory,'passed-private.json'),{...binding,...input,passed:true,registration_posts:registrations,account_and_required_receipt:account,patches,responses,explicit_client_faults:injected,real_cas:cas,final_context:final.data,actual_browser:await browser.version(),scope:'one existing scenario; real account/purpose backend; injected faults explicitly listed; not whole page/device acceptance',at:new Date().toISOString()});console.log(JSON.stringify({case:input.name,locale:input.locale,runtime:core.RUNTIME,observer:input.observer,passed:true,registration_posts:1}));
 }catch(error){save(path.join(directory,'failed-private.json'),{...binding,...input,passed:false,phase,error_private:String(error.stack),route_error_private:routeError,registration_posts:registrations,patches,responses,explicit_client_faults:injected,real_cas:cas,no_automatic_replay:true,at:new Date().toISOString()});console.error('External fault case failed at '+phase+'; inspect its private receipt.');process.exitCode=1;}finally{if(browser)await browser.close();}
 }finally{if(fs.existsSync(lock)&&privateJSON(lock).token===token)fs.unlinkSync(lock);}
}
if(require.main===module)main().catch(error=>{console.error('External observer preflight refused: '+String(error.message));process.exitCode=1;});
module.exports={parse,digest};
