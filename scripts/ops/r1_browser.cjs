/* 06 actual Chrome observer. Only own 8412, private actors/artifacts; no resets or provider mocks. */
'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'../..'),out=path.join(root,'.runtime/06/r1'),base='http://127.0.0.1:8412';
const mode=process.argv[2],candidate=process.argv[3];
const modes=['forms','faults','management','security','observation','media','restore'];
if(!modes.includes(mode)||!/^[a-f0-9]{40}$/.test(candidate||'')||root!==fs.realpathSync(root)||path.basename(path.dirname(root))!=='h5-r1-registration-manual')throw Error('Own fixed R1 root/mode required');
process.umask(0o077);
function privateJSON(file){const s=fs.lstatSync(file);assert(s.isFile()&&!s.isSymbolicLink()&&s.nlink===1&&s.uid===process.getuid()&&!(s.mode&0o077));return JSON.parse(fs.readFileSync(file,'utf8'));}
function save(file,value){assert(!fs.existsSync(file),'No receipt overwrite');fs.mkdirSync(path.dirname(file),{recursive:true,mode:0o700});fs.writeFileSync(file,JSON.stringify(value,null,2),{mode:0o600,flag:'wx'});}
const manifest=privateJSON(path.join(out,'manifest.json'));assert.equal(manifest.candidate,candidate);
cp.execFileSync('python3',['-B','-c','import sys;sys.path.insert(0,"scripts/ops");import r1_window as r;r.manifest(sys.argv[1],future=True)',candidate],{cwd:root,stdio:'pipe'});
const processRecord=privateJSON(path.join(out,'process.json'));assert.equal(processRecord.candidate,candidate);assert.equal(processRecord.role,mode==='restore'?'restore':'manual');
const report=path.join(out,'browser-'+mode+'.json');assert(!fs.existsSync(report),'One final receipt per mode; failed run gets private diagnostic');
const {chromium}=require('/Users/hejunxiang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js');
const checks=[],faults=[],images=[];let browser,current='launch',lastRegistration=0;
async function paceRegistration(){const delay=12500-(Date.now()-lastRegistration);if(delay>0)await new Promise(r=>setTimeout(r,delay));lastRegistration=Date.now();}
function check(label,detail={}){checks.push({label,...detail});}
function control(action,label){return JSON.parse(cp.execFileSync('python3',['-B','scripts/ops/r1_window.py',action,'--candidate',candidate,'--label',label],{cwd:root,stdio:'pipe'}).toString());}
function actor(label){const file=path.join(out,'fixtures/actor-'+label+'.json');if(!fs.existsSync(file))control('actor',label);const value=privateJSON(file);assert.equal(value.kind,'automation-material');return value;}
async function context(locale='en',state){const c=await browser.newContext({viewport:{width:390,height:844},locale:locale==='zh'?'zh-CN':'en-US',...(state?{storageState:state}:{})});await c.route('**/*',route=>{const url=new URL(route.request().url());return url.origin===base?route.continue():route.abort('blockedbyclient');});return c;}
async function api(page,url,method='GET',body,extra={}){return page.evaluate(async({url,method,body,extra})=>{let token='';if(method!=='GET'){const r=await fetch('/api/v1/auth/csrf',{headers:{Accept:'application/json'},cache:'no-store'});token=(await r.json()).csrf_token;}const r=await fetch(url,{method,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json',...(body?{'Content-Type':'application/json'}:{}),...(method!=='GET'?{'X-CSRF-TOKEN':token}:{}),...extra},...(body?{body:JSON.stringify(body)}:{})});let data;try{data=await r.json();}catch{data=null;}return {status:r.status,data,cache:r.headers.get('cache-control'),retry:r.headers.get('retry-after')};},{url,method,body,extra});}
const purposeURL='/api/v3/measurement/purposes/';
const command=(purpose,choice,revision)=>({purpose,choice,notice_version:'c-purpose-choice-only-r1-v1',expected_revision:revision,operation_id:crypto.randomUUID()});
async function shot(page,label){const file=path.join(out,'screenshots',label+'.png');assert(!fs.existsSync(file));fs.mkdirSync(path.dirname(file),{recursive:true,mode:0o700});await page.screenshot({path:file,fullPage:true,mask:[page.locator('input[name="name"],input[name="email"],input[type="password"]')]});fs.chmodSync(file,0o600);images.push(label);}
async function gotoRegister(page,locale){const r=await page.goto(base+'/identity/register?lang='+locale);assert.equal(r.status(),200);assert.equal(r.headers()['x-06-r1-gateway'],'UnconfiguredGateway');await page.waitForFunction(()=>['not_requested','unconfirmed'].includes(document.querySelector('[data-purpose-state="account_link"]')?.dataset.status));}
async function fill(page,a,choices=[]){await page.locator('[name="name"]').fill(a.name);await page.locator('[name="email"]').fill(a.email);await page.locator('[name="password"]').fill(a.password);await page.locator('[name="password_confirmation"]').fill(a.password);await page.locator('[name="terms_accepted"]').check();for(const p of choices){await page.locator('[data-registration-purpose="'+p+'"]').check();}}
async function submit(page){await paceRegistration();await page.locator('form[data-registration-r1] button[type="submit"]').click();await page.waitForFunction(()=>document.querySelector('[data-registration-r1]')?.dataset.registrationComplete==='true'||document.querySelector('[data-registration-r1]')?.dataset.registrationUnconfirmed==='true',{},{timeout:25000});}
async function status(page,p,value){await page.waitForFunction(({p,value})=>document.querySelector('[data-purpose-state="'+p+'"]')?.dataset.status===value,{p,value},{timeout:12000});}
async function denySite(page){const c=await api(page,'/api/v2/measurement/context');assert.equal(c.status,200);const r=await api(page,'/api/v2/measurement/preferences','PATCH',{site_session:'deny',notice_version:'c-notice-c-core-1',expected_revision:c.data.revision});assert.equal(r.status,200);assert.equal(r.data.site_choice,'deny');return r.data;}
async function verify(page,a){const mailroot=path.join(root,'.runtime/mail');const files=fs.readdirSync(mailroot).filter(n=>fs.statSync(path.join(mailroot,n)).isFile());let links=[];for(const file of files){const s=fs.readFileSync(path.join(mailroot,file),'utf8');if(file.endsWith('.eml')&&s.includes(a.email)){const html=fs.readFileSync(path.join(mailroot,file.replace(/\.eml$/,'.html')),'utf8');links.push(...html.matchAll(/http:\/\/127\.0\.0\.1:8412\/api\/v1\/auth\/email\/verify\/[^\s<>"']+/g));}}const values=[...new Set(links.map(x=>x[0].replaceAll('&amp;','&')))];assert.equal(values.length,1,'Own one exact preview signature');const u=new URL(values[0]);assert.equal(u.origin,base);await page.goto(u.href);const me=await api(page,'/api/v1/auth/me');assert.equal(me.status,200);assert(me.data.viewer);return me.data;}
async function login(page,a){await page.goto(base+'/identity/login');await page.locator('[name="email"]').fill(a.email);await page.locator('[name="password"]').fill(a.password);await Promise.all([page.waitForURL('**/identity/account**'),page.locator('form[data-identity-form] button').click()]);}
async function forms(){
 for(const locale of ['en','zh']){
  current='forms-'+locale;const c=await context(locale),p=await c.newPage(),a=actor('auto-main-'+locale);let registrations=0,patches=0;
  p.on('request',r=>{if(r.method()==='POST'&&new URL(r.url()).pathname==='/api/v1/auth/register')registrations++;if(r.method()==='PATCH'&&r.url().includes(purposeURL+'preferences'))patches++;});
  await gotoRegister(p,locale);assert.equal(await p.locator('[name="terms_accepted"]').isChecked(),false);for(const purpose of ['account_link','advertising'])assert.equal(await p.locator('[data-registration-purpose="'+purpose+'"]').isChecked(),false);
  for(const target of ['test-terms','test-privacy']){const r=await c.request.get(base+'/identity/'+target+'?lang='+locale);assert.equal(r.status(),200);assert((await r.text()).length>500);}
  await shot(p,'registration-'+locale+'-initial');
  const frozen=await api(p,purposeURL+'context');assert.equal(frozen.status,200);assert.equal(frozen.data.purposes.account_link.choice,'unknown');assert.equal(frozen.data.purposes.advertising.choice,'unknown');assert.equal(frozen.cache,'no-store, private');
  // Real server validation, separate from native checkbox validation. No account created by invalid requests.
  for(const change of [{terms_accepted:false},{terms_accepted:true,terms_version:'wrong'}]){await paceRegistration();const r=await api(p,'/api/v1/auth/register','POST',{name:a.name,email:a.email,password:a.password,password_confirmation:a.password,terms_accepted:true,terms_version:'local-c-terms-r1-v1',privacy_notice_version:'local-c-privacy-r1-v1',...change});assert.equal(r.status,422);}
  assert.equal(control('actor-status','auto-main-'+locale).exists,false);
  await denySite(p);await fill(p,a);const beforeSubmit=registrations;await submit(p);await status(p,'account_link','not_requested');await status(p,'advertising','not_requested');assert.equal(registrations-beforeSubmit,1);assert.equal(patches,0);assert.equal(await p.locator('[name="password"]').inputValue(),'');
  await shot(p,'registration-'+locale+'-created');const receipt=control('actor-status','auto-main-'+locale);assert(receipt.exists&&receipt.receipt_exists&&receipt.receipt_versions_match);
  await verify(p,a);assert.equal(control('actor-status','auto-main-'+locale).verified,true);
  const before=(await api(p,'/api/v2/measurement/context')).data;assert.equal(before.site_choice,'deny');assert.equal((await api(p,'/api/v1/auth/logout','POST',{})).status,200);await login(p,a);const after=(await api(p,'/api/v2/measurement/context')).data;assert.equal(after.site_choice,'deny');assert.equal(after.revision,before.revision);
  const prefs=(await api(p,purposeURL+'context')).data;assert.equal(prefs.purposes.account_link.choice,'unknown');assert.equal(prefs.purposes.advertising.choice,'unknown');assert.equal(prefs.site_session.effective,'off');
  const state=path.join(out,'browser-state-'+locale+'.json');await c.storageState({path:state});fs.chmodSync(state,0o600);
  check('real form '+locale+': strict terms, one create/receipt, optional omission, own signed preview, logout/login, site deny preserved');await c.close();
 }
}
async function faultsPhase(){
 const cases=['partial-503','lost-registration-json','lost-purpose-json','network-before-purpose','identity-unavailable','status-401','status-403','status-419','status-422','status-429','actual-cas'];
 for(const which of cases){current=which;const c=await context(),p=await c.newPage(),a=actor('auto-'+which);let registrations=0,patches=[];
  p.on('request',r=>{if(r.method()==='POST'&&r.url().endsWith('/api/v1/auth/register'))registrations++;if(r.method()==='PATCH'&&r.url().endsWith(purposeURL+'preferences'))patches.push(JSON.parse(r.postData()));});
  await gotoRegister(p,'en');if(which==='actual-cas'){const r=await api(p,purposeURL+'preferences','PATCH',command('account_link','deny',0));assert.equal(r.status,200);await gotoRegister(p,'en');}
  let first=true,committedStatus=null;
  if(which==='lost-registration-json')await p.route('**/api/v1/auth/register',async r=>{const response=await r.fetch();committedStatus=response.status();await r.fulfill({response,body:'{malformed'});});
  if(which==='identity-unavailable')await p.route('**/api/v1/auth/me',r=>r.fulfill({status:503,contentType:'application/json',body:'{}'}));
  if(!['lost-registration-json','identity-unavailable'].includes(which))await p.route('**/api/v3/measurement/purposes/preferences',async route=>{
   const req=JSON.parse(route.request().postData());const target=which==='partial-503'?'advertising':'account_link';if(req.purpose!==target||!first)return route.continue();first=false;
   if(which==='actual-cas'){const competing={...req,choice:'deny',operation_id:crypto.randomUUID()};const r=await route.fetch({postData:JSON.stringify(competing)});assert.equal(r.status(),200);return route.continue();}
   if(which==='lost-purpose-json'){const r=await route.fetch();committedStatus=r.status();return route.fulfill({response:r,body:'{malformed'});}
   if(which==='network-before-purpose')return route.abort('internetdisconnected');
   const code=which==='partial-503'?503:Number(which.split('-')[1]);return route.fulfill({status:code,contentType:'application/json',headers:code===429?{'Retry-After':'2'}:{},body:JSON.stringify({error:{code:'CONTROLLED_CLIENT_FAULT'}})});
  });
  await fill(p,a,['account_link','advertising']);await submit(p);
  if(which==='lost-registration-json'){await p.waitForFunction(()=>document.querySelector('[data-registration-r1]').dataset.registrationComplete==='true');assert(committedStatus>=200&&committedStatus<300);await status(p,'account_link','saved');await status(p,'advertising','saved');assert.equal(registrations,1);}
  else if(['status-401','status-403'].includes(which)){await p.waitForFunction(()=>document.querySelector('[data-result]').textContent.length>0);assert.equal(patches.filter(x=>x.purpose==='advertising').length,0);assert.equal(await p.locator('[data-purpose-retry="account_link"]').isVisible(),false);}
  else{
   const purpose=which==='partial-503'?'advertising':'account_link';await status(p,purpose,which==='actual-cas'?'conflict':'unconfirmed');
   if(which==='identity-unavailable')assert.equal(patches.length,0);
   if(which==='partial-503')await status(p,'account_link','saved');
   await shot(p,'registration-fault-'+which);
   const before=patches.length;await p.waitForTimeout(350);assert.equal(patches.length,before,'No automatic replay');
   if(which==='status-429'){assert.equal(await p.locator('[data-purpose-retry="account_link"]').isDisabled(),true);await p.waitForTimeout(2100);}
   if(which==='identity-unavailable')await p.unroute('**/api/v1/auth/me');
   await p.locator('[data-purpose-retry="'+purpose+'"]').click();await status(p,purpose,'saved');
   if(which==='identity-unavailable'){await p.locator('[data-purpose-retry="advertising"]').click();await status(p,'advertising','saved');}
   if(which==='lost-purpose-json'){assert.equal(committedStatus,200);const attempts=patches.filter(x=>x.purpose==='account_link');assert.equal(attempts.length,2);assert.equal(attempts[0].operation_id,attempts[1].operation_id);}
   if(which==='partial-503')assert.equal(patches.filter(x=>x.purpose==='account_link').length,1);
   if(which==='actual-cas'){const attempts=patches.filter(x=>x.purpose==='account_link');assert.notEqual(attempts[0].operation_id,attempts[1].operation_id);assert.equal(attempts[1].expected_revision,attempts[0].expected_revision+1);}
  }
  assert.equal(registrations,1);const state=control('actor-status','auto-'+which);assert(state.exists&&state.receipt_exists);faults.push({case:which,kind:which==='actual-cas'?'real_server_revision_conflict':'explicit_playwright_client_fault',account_and_receipt_preserved:true,registration_posts:registrations});await c.close();
 }
 check('eleven real-page recovery flows; only CAS is real backend conflict, other injected faults named');
}
async function management(){
 for(const locale of ['en','zh']){
  current='management-'+locale;const c=await context(locale,path.join(out,'browser-state-'+locale+'.json')),p=await c.newPage();await p.goto(base+'/?lang='+locale);await p.locator('[data-c-open]').first().click();await p.waitForFunction(()=>document.querySelector('[data-c-purpose-manager] [data-purpose="account_link"] [data-purpose-capability]').textContent.length>0);await p.waitForTimeout(500);
  for(const purpose of ['account_link','advertising']){const item=p.locator('[data-c-purpose-manager] [data-purpose="'+purpose+'"]');await item.locator('input[value="allow"]').check();await item.locator('[data-purpose-save]').click();await p.waitForFunction(p=>document.querySelector('[data-c-purpose-manager] [data-purpose="'+p+'"]').dataset.purposeStatus==='saved',purpose);}
  const state=(await api(p,purposeURL+'context')).data;for(const purpose of ['account_link','advertising']){assert.equal(state.purposes[purpose].choice,'allow');assert.equal(state.purposes[purpose].effective,false);}
  assert.equal(state.site_session.choice,'deny');await shot(p,'management-'+locale+'-saved-not-running');
  // Genuine computed font enlargement, not device pixel ratio. Compare measured before/after.
  for(const enlarged of [false,true]){await p.setViewportSize({width:320,height:500});let ratio=1;if(enlarged){ratio=await p.evaluate(()=>{const body=document.body,old=parseFloat(getComputedStyle(body).fontSize);const nodes=[...document.querySelectorAll('body,button,input,legend,p,dd,dt,summary,a')];const sizes=nodes.map(n=>[n,parseFloat(getComputedStyle(n).fontSize)]);for(const [n,size] of sizes)n.style.setProperty('font-size',(size*2)+'px','important');return parseFloat(getComputedStyle(body).fontSize)/old;});assert.equal(ratio,2);}
   const overflow=await p.evaluate(()=>({page:document.documentElement.scrollWidth>innerWidth,dialog:document.querySelector('[data-c-dialog]').scrollWidth>document.querySelector('[data-c-dialog]').clientWidth+2}));assert(!overflow.page&&!overflow.dialog);await shot(p,'management-'+locale+'-320x500'+(enlarged?'-font200':''));
   await p.locator('[data-c-close]').click();assert.equal(await p.locator('[data-c-dialog]').evaluate(n=>n.open),false);await p.locator('[data-c-open]').first().click();await p.waitForTimeout(300);
  }
  await p.keyboard.press('Escape');assert.equal(await p.locator('[data-c-dialog]').evaluate(n=>n.open),false);
  const itemState=(await api(p,purposeURL+'context')).data;const logout=await api(p,'/api/v1/auth/logout','POST',{});assert.equal(logout.status,200);const anonymous=(await api(p,purposeURL+'context')).data;assert.equal(anonymous.purposes.account_link.choice,'unknown');assert.equal(anonymous.purposes.advertising.choice,'unknown');assert.equal(anonymous.site_session.choice,'deny');
  await login(p,actor('auto-main-'+locale));const restoredState=path.join(out,'browser-restore-state-'+locale+'.json');await c.storageState({path:restoredState});fs.chmodSync(restoredState,0o600);
  check('management '+locale+': real two independent allow receipts/effective false, logout scope, 320x500 and measured font200/close/Escape');await c.close();
 }
}
async function security(){
 current='security';const c=await context(),p=await c.newPage();await gotoRegister(p,'en');const initial=(await api(p,purposeURL+'context')).data;
 assert.equal((await api(p,purposeURL+'preferences','PATCH',command('account_link','allow',0))).status,401);
 for(const extra of [{viewer_id:crypto.randomUUID()},{actor_id:crypto.randomUUID()},{basis:'consent'},{country:'US'},{source_id:crypto.randomUUID()}])assert.equal((await api(p,purposeURL+'preferences','PATCH',{...command('account_link','deny',0),...extra})).status,422);
 const missing=await p.evaluate(async()=>{const r=await fetch('/api/v3/measurement/purposes/preferences',{method:'PATCH',headers:{Accept:'application/json','Content-Type':'application/json'},body:'{}'});return r.status;});assert.equal(missing,419);
 const tooLarge=await api(p,purposeURL+'preferences','PATCH',{...command('account_link','deny',0),padding:'x'.repeat(5000)});assert.equal(tooLarge.status,413);
 for(const [url,method] of [['/admin','GET'],['/membership','GET'],['/api/v1/admin/staff','POST'],['/api/v1/billing/checkout','POST'],['/webhooks/paypal','POST'],['/webhooks/stripe','POST'],['/api/v1/tracking/consent','POST']])assert.equal((await api(p,url,method,method==='GET'?undefined:{})).status,403);
 const a=command('account_link','deny',0),first=await api(p,purposeURL+'preferences','PATCH',a);assert.equal(first.status,200);const second=await api(p,purposeURL+'preferences','PATCH',a);assert.equal(second.status,200);assert.deepEqual(second.data.state,first.data.state);assert.equal((await api(p,purposeURL+'preferences','PATCH',{...a,choice:'allow'})).status,409);
 const independent=await api(p,purposeURL+'preferences','PATCH',command('advertising','deny',0));assert.equal(independent.status,200);assert.equal(independent.data.state.revision,1);
 const conflict=await api(p,purposeURL+'preferences','PATCH',command('account_link','deny',0));assert.equal(conflict.status,409);assert.equal(conflict.data.state.revision,1);
 const staleNotice=await api(p,purposeURL+'preferences','PATCH',{...command('account_link','deny',1),notice_version:'old'});assert.equal(staleNotice.status,409);
 // Read budget pressure must not consume v2 necessary exit budget; no cache reset.
 let limited=false;for(let i=0;i<125;i++){const r=await api(p,purposeURL+'context');if(r.status===429){limited=true;break;}}assert(limited,'Actual read limiter reached');await denySite(p);
 check('real HTTP 401/403/413/419/422/409/429, no-store, scoped whitelist, replay/CAS and independent v2 exit budget');await c.close();
}
async function observation(){
 current='actual-notice-and-result';const c=await context(),p=await c.newPage(),a=actor('auto-observation');await gotoRegister(p,'en');
 await p.locator('[data-c-delivery]').scrollIntoViewIfNeeded();await p.waitForFunction(async()=>{const r=await fetch('/api/v2/measurement/context',{headers:{Accept:'application/json'}});return (await r.json()).site_effective==='on';},{},{timeout:15000});
 await fill(p,a);await submit(p);assert(control('actor-status','auto-observation').receipt_exists);const state=(await api(p,'/api/v2/measurement/context')).data;assert.notEqual(state.site_effective,'on');check('actual visible notice/start and one account committed before automatic Login ends C; result payload checked in private SQL snapshot');await c.close();
}
async function media(){
 current='real-hls';const c=await context('en',path.join(out,'browser-restore-state-en.json')),p=await c.newPage();const fixture=privateJSON(path.join(out,'fixtures/content.json'));const requests=[];p.on('response',r=>{const u=new URL(r.url());if(/\.(m3u8|ts)$/.test(u.pathname))requests.push({path_type:u.pathname.endsWith('.ts')?'segment':'manifest',status:r.status()});});
 await p.goto(base+'/dramas/'+fixture.drama_id+'/episodes/'+fixture.episodes[0]);await p.locator('[data-play]').click();await p.waitForFunction(()=>{const v=document.querySelector('video');return v&&v.readyState>=3&&v.currentTime>0&&!v.error;},{},{timeout:20000});const video=await p.locator('video').evaluate(v=>({readyState:v.readyState,time:v.currentTime,error:v.error?.code||null,mse:MediaSource.isTypeSupported('video/mp4; codecs="avc1.42E01E"')}));assert(video.mse&&requests.some(r=>r.path_type==='segment'&&r.status===200));
 const unsigned=await c.request.get(base+'/api/v1/content/playback/'+fixture.episodes[0]+'/index.m3u8');assert.equal(unsigned.status(),403);const sixth=await api(p,'/audience/dramas/'+fixture.drama_id+'/episodes/'+fixture.episodes[5]+'/playback','POST',{});assert.equal(sixth.status,200);assert.equal(sixth.data.allowed,false);check('real Chrome MSE/hls.js manifest and TS decode, unsigned denied and member episode blocked',{video,media_responses:requests});await c.close();
}
async function restore(){
 current='restore';const c=await context('en',path.join(out,'browser-restore-state-en.json')),p=await c.newPage();await p.goto(base+'/health');const me=await api(p,'/api/v1/auth/me');assert.equal(me.status,200);assert(me.data.viewer);const state=(await api(p,purposeURL+'context')).data;assert.equal(state.site_session.choice,'deny');for(const purpose of ['account_link','advertising'])assert.equal(state.purposes[purpose].effective,false);assert.equal((await api(p,purposeURL+'preferences','PATCH',command('account_link','deny',0))).status,403);check('independent restored server: original signed-in session/deny context readable, both purposes effective false, mutation blocked');await c.close();
}
(async()=>{try{browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true,args:['--no-first-run','--autoplay-policy=no-user-gesture-required']});if(mode==='forms')await forms();if(mode==='faults')await faultsPhase();if(mode==='management')await management();if(mode==='security')await security();if(mode==='observation')await observation();if(mode==='media')await media();if(mode==='restore')await restore();save(report,{candidate,product_base:manifest.product_base,mode,actual_browser:await browser.version(),checks,faults,images,passed:true,at:new Date().toISOString(),true_phone:false});console.log(JSON.stringify({mode,passed:true,checks:checks.length,fault_cases:faults.length,private_images:images.length}));}catch(error){const file=path.join(out,'browser-'+mode+'-error-'+Date.now()+'.json');save(file,{candidate,current,checks,faults,error:String(error.stack)});console.error('R1 browser failed at '+current+'; inspect private diagnostic.');process.exitCode=1;}finally{if(browser)await browser.close();}})();
