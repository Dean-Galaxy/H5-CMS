// Actual local Laravel/05/Identity HTTP in an Audience-owned synthetic DB. Fault cases labelled explicitly.
const {chromium}=require('playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const base=process.env.H5_C_UI_BASE,out=process.env.H5_C_UI_OUT;
assert(process.env.H5_C_UI_R1==='1'&&process.env.DB_DATABASE.endsWith('_r1_audience_http_test'));
const fixture=JSON.parse(fs.readFileSync(path.join(out,'fixture.json'))),results=[],errors=[],external=[];
const sql=query=>execFileSync('/opt/homebrew/opt/postgresql@17/bin/psql',['-At','-v','ON_ERROR_STOP=1','-c',query],{encoding:'utf8',env:{...process.env,PGHOST:'127.0.0.1',PGPORT:process.env.DB_PORT,PGDATABASE:process.env.DB_DATABASE,PGUSER:process.env.DB_USERNAME,PGPASSWORD:process.env.DB_PASSWORD}}).trim();
const item=(page,name)=>page.locator('[data-purpose="'+name+'"]');
const until=(page,name,result)=>page.waitForFunction(({name,result})=>document.querySelector('[data-purpose="'+name+'"]').dataset.purposeStatus===result,{name,result});
let browser,last;
const check=async(name,run)=>{
  const owned=JSON.parse(fs.readFileSync(path.join(out,'../r1-audience-owned.json')));assert.equal(owned.database,process.env.DB_DATABASE);
  execFileSync('/opt/homebrew/opt/php@8.4/bin/php',['artisan','cache:clear'],{cwd:path.join(owned.root,'h5-platform'),env:process.env});
  await run();results.push({name,passed:true});console.log('PASS '+name);
};
async function session(locale='en') {
  const context=await browser.newContext({viewport:{width:320,height:500},locale}),page=await context.newPage();last=page;
  const posts=[];
  await context.route('**/*',route=>{if(!route.request().url().startsWith(base+'/')){external.push(route.request().url().split('/')[2]);return route.abort();}return route.continue();});
  page.on('pageerror',e=>errors.push(e.message));
  page.on('request',r=>{if(r.url().endsWith('/api/v3/measurement/purposes/preferences')){
    const body=r.postDataJSON();assert.deepEqual(Object.keys(body).sort(),['choice','expected_revision','notice_version','operation_id','purpose']);assert(!r.headers().referer);posts.push(body);
  }});
  await page.goto(base+'/?lang='+(locale==='en'?'en':'zh_CN'),{waitUntil:'domcontentloaded'});
  return {context,page,posts};
}
async function open(page) {
  if(await page.locator('[data-c-dialog]').evaluate(n=>n.open))await page.locator('[data-c-close]').click();
  await page.locator('[data-c-open]').first().click();
  await page.waitForFunction(()=>{const n=document.querySelector('[data-purpose-reading]');return n&&!n.textContent;});
}
async function auth(context,page,index=0,logout=false) {
  const token=(await (await context.request.get(base+'/api/v1/auth/csrf')).json()).csrf_token;
  const response=await context.request.post(base+'/api/v1/auth/'+(logout?'logout':'login'),{headers:{'X-CSRF-TOKEN':token,'Origin':base,'Accept':'application/json'},data:logout?{}:fixture.accounts[index]});
  assert([200,204].includes(response.status()),'Normal identity flow status '+response.status());
  await page.reload({waitUntil:'domcontentloaded'});
}
async function choose(page,name,choice) {await item(page,name).locator('input[value="'+choice+'"]').check();await item(page,name).locator('[data-purpose-save]').click();}
async function remote(context,page,purpose,choice,revision) {
  const token=await page.locator('meta[name="csrf-token"]').getAttribute('content');
  return context.request.patch(base+'/api/v3/measurement/purposes/preferences',{headers:{'X-CSRF-TOKEN':token,'Origin':base},data:{purpose,choice,expected_revision:revision,notice_version:'c-purpose-choice-only-r1-v1',operation_id:crypto.randomUUID()}});
}
(async()=>{
  browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
  for(const locale of ['en','zh-CN'])await check(locale+' 320x500 unknown/read-only legacy, large text and keyboard focus',async()=>{
    const {context,page,posts}=await session(locale);
    const proof=Number(sql('SELECT count(*) FROM acquisition_r1_proofs'));
    assert.equal(await page.locator('dialog[open]').count(),0);
    await open(page);assert.equal(Number(sql('SELECT count(*) FROM acquisition_r1_proofs')),proof);
    assert.equal(await page.locator('[data-c-purpose-manager] input:checked').count(),0);assert.equal(posts.length,0);
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    for(const purpose of ['account_link','advertising']) {
      await item(page,purpose).locator('legend').evaluate(n=>n.scrollIntoView({block:'start'}));
      await page.screenshot({path:path.join(out,locale+'-'+purpose+'-state-320.png')});
      await item(page,purpose).locator('dl').evaluate(n=>n.scrollIntoView({block:'start'}));
      await page.screenshot({path:path.join(out,locale+'-'+purpose+'-values-320.png')});
      await item(page,purpose).scrollIntoViewIfNeeded();
      assert(await item(page,purpose).locator('[data-purpose-effective]').innerText().then(t=>t=== (locale==='en'?'Not running':'尚未运行')));
      const button=item(page,purpose).locator('[data-purpose-deny]');await button.scrollIntoViewIfNeeded();assert((await button.boundingBox()).height>=44);
      await page.screenshot({path:path.join(out,locale+'-'+purpose+'-320.png')});
    }
    const close=page.locator('[data-c-close]');await close.focus();await page.keyboard.press('Tab');
    assert(await page.locator('[data-c-dialog]').evaluate(n=>n.contains(document.activeElement)));
    const zoom=await page.addStyleTag({content:'.measurement-c-body,.measurement-c-body p,.measurement-c-body dd,.purpose-manager label,.purpose-manager dt,.purpose-manager button{font-size:28px!important}'});
    await item(page,'account_link').locator('dl').evaluate(n=>n.scrollIntoView({block:'start'}));assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    assert(await page.locator('.measurement-c-body').evaluate(n=>n.scrollWidth<=n.clientWidth+1));
    assert((await item(page,'account_link').locator('dd').evaluateAll(nodes=>nodes.map(n=>n.getBoundingClientRect().width))).every(width=>width>=140));
    await page.screenshot({path:path.join(out,locale+'-large-text.png')});await zoom.evaluate(n=>n.remove());
    await page.keyboard.press('Escape');assert.equal(await page.locator('dialog[open]').count(),0);assert.equal(posts.length,0);
    await context.close();
  });
  await check('Guest allow guides normal login without POST; guest deny is confirmed',async()=>{
    const {context,page,posts}=await session();await open(page);await choose(page,'account_link','allow');await until(page,'account_link','login_required');assert.equal(posts.length,0);
    assert(await item(page,'account_link').locator('[data-purpose-login]').isVisible());
    await item(page,'account_link').locator('[data-purpose-deny]').click();await until(page,'account_link','saved');assert.equal(posts.length,1);assert.equal(posts[0].choice,'deny');
    await auth(context,page);await open(page);assert.equal(await item(page,'account_link').locator('[data-purpose-choice]').innerText(),'Off choice saved');
    await context.close();
  });
  for(const good of ['account_link','advertising'])await check('Actual '+good+' saved; other 503 retry is independent and idempotent',async()=>{
    const {context,page,posts}=await session();await auth(context,page);await open(page);const bad=good==='account_link'?'advertising':'account_link';
    await choose(page,good,'allow');await until(page,good,'saved');
    let fault=true;
    await page.route('**/api/v3/measurement/purposes/preferences',route=>route.request().postDataJSON().purpose===bad&&fault?route.fulfill({status:503,contentType:'application/json',body:'{}'}):route.continue());
    await choose(page,bad,'allow');await until(page,bad,'failed');assert.equal(await item(page,good).getAttribute('data-purpose-status'),'saved');
    await item(page,bad).locator('[data-purpose-result]').evaluate(n=>n.scrollIntoView({block:'center'}));
    await page.screenshot({path:path.join(out,'partial-'+bad+'-320.png')});
    const before=posts.filter(p=>p.purpose===good).length,operation=posts.at(-1);fault=false;
    await item(page,bad).locator('[data-purpose-save]').click();await until(page,bad,'saved');assert.deepEqual(posts.at(-1),operation);assert.equal(posts.filter(p=>p.purpose===good).length,before);
    for(const purpose of [good,bad])assert.equal(await item(page,purpose).locator('[data-purpose-effective]').innerText(),'Not running');
    await page.screenshot({path:path.join(out,'saved-'+good+'.png')});await context.close();
  });
  await check('Real 409 returns new saved choice; allow draft requires another explicit CAS',async()=>{
    const {context,page,posts}=await session();await auth(context,page);await open(page);
    assert.equal((await remote(context,page,'account_link','deny',0)).status(),200);
    await choose(page,'account_link','allow');await until(page,'account_link','conflict');await page.waitForTimeout(250);assert.equal(posts.length,1);
    assert.equal(await item(page,'account_link').locator('[data-purpose-choice]').innerText(),'Off choice saved');
    assert(await item(page,'account_link').locator('input[value=allow]').isChecked());
    await page.screenshot({path:path.join(out,'conflict-320.png')});
    await item(page,'account_link').locator('[data-purpose-result]').evaluate(n=>n.scrollIntoView({block:'center'}));
    await page.screenshot({path:path.join(out,'conflict-result-320.png')});
    await item(page,'account_link').locator('[data-purpose-save]').click();await until(page,'account_link','saved');assert.deepEqual(posts.map(p=>p.expected_revision),[0,1]);assert.notEqual(posts[0].operation_id,posts[1].operation_id);
    await context.close();
  });
  await check('Lost response after real commit retries the same operation and confirms current state',async()=>{
    const {context,page,posts}=await session();await auth(context,page);await open(page);let lost=true;
    await page.route('**/api/v3/measurement/purposes/preferences',async route=>{if(lost){lost=false;const r=await route.fetch();assert.equal(r.status(),200);return route.abort('connectionreset');}return route.continue();});
    await choose(page,'advertising','allow');await until(page,'advertising','failed');
    await item(page,'advertising').locator('[data-purpose-save]').click();await until(page,'advertising','saved');assert.deepEqual(posts[0],posts[1]);await context.close();
  });
  await check('An older allow receipt returns current deny and cannot revive it',async()=>{
    const {context,page,posts}=await session();await auth(context,page);await open(page);let lost=true;
    await page.route('**/api/v3/measurement/purposes/preferences',async route=>{if(lost){lost=false;const response=await route.fetch();assert.equal(response.status(),200);return route.abort('connectionreset');}return route.continue();});
    await choose(page,'account_link','allow');await until(page,'account_link','failed');
    assert.equal((await remote(context,page,'account_link','deny',1)).status(),200);
    await item(page,'account_link').locator('[data-purpose-save]').click();await until(page,'account_link','conflict');
    assert.equal(await item(page,'account_link').locator('[data-purpose-choice]').innerText(),'Off choice saved');assert.deepEqual(posts[0],posts[1]);
    await page.waitForTimeout(200);assert.equal(posts.length,2);await open(page);assert(await item(page,'account_link').locator('input[value=deny]').isChecked());await context.close();
  });
  await check('Read 503 remains unknown; deny can use strict initial revision zero',async()=>{
    const {context,page,posts}=await session();await page.route('**/api/v3/measurement/purposes/context',r=>r.fulfill({status:503,contentType:'application/json',body:'{}'}));
    await page.locator('[data-c-open]').first().click();await page.waitForFunction(()=>document.querySelector('[data-purpose-reading]').textContent.includes('could not'));
    assert.equal(await page.locator('[data-c-purpose-manager] input:checked').count(),0);
    await item(page,'advertising').locator('[data-purpose-deny]').click();await until(page,'advertising','saved');assert.equal(posts[0].expected_revision,0);await context.close();
  });
  for(const fault of ['429','bad','timeout'])await check('Explicit '+fault+' preference fault never claims saved or replays',async()=>{
    const {context,page,posts}=await session();await auth(context,page);await open(page);
    await page.route('**/api/v3/measurement/purposes/preferences',async route=>{
      if(fault==='timeout'){await new Promise(r=>setTimeout(r,6500));return route.fulfill({status:503,body:'{}'}).catch(()=>{});}
      return route.fulfill(fault==='429'?{status:429,headers:{'Retry-After':'2'},contentType:'application/json',body:'{}'}:{status:200,contentType:'application/json',body:'{"schema_version":3}'});
    });
    await choose(page,'advertising','allow');await until(page,'advertising',fault==='429'?'limited':'failed');
    assert.equal(await item(page,'advertising').locator('[data-purpose-choice]').innerText(),'Not confirmed');assert.equal(posts.length,1);
    await item(page,'advertising').locator('[data-purpose-result]').evaluate(n=>n.scrollIntoView({block:'center'}));
    if(fault==='429'){assert(await item(page,'advertising').locator('[data-purpose-save]').isDisabled());await page.waitForTimeout(2100);assert.equal(posts.length,1);assert(await item(page,'advertising').locator('[data-purpose-save]').isEnabled());}
    await page.screenshot({path:path.join(out,'fault-'+fault+'-320.png')});await context.close();
  });
  await check('Closing discards drafts and late responses; reopen reads actual current state',async()=>{
    const {context,page,posts}=await session();await auth(context,page);await open(page);
    await item(page,'account_link').locator('input[value=allow]').check();await page.locator('[data-c-close]').click();assert.equal(posts.length,0);await open(page);
    assert.equal(await item(page,'account_link').locator('input:checked').count(),0);
    let release,received;const gate=new Promise(r=>release=r),ready=new Promise(r=>received=r);
    await page.route('**/api/v3/measurement/purposes/preferences',async route=>{const response=await route.fetch();received();await gate;await route.fulfill({response}).catch(()=>{});});
    await choose(page,'account_link','allow');await ready;await page.locator('[data-c-close]').click();release();await page.waitForTimeout(100);
    assert.equal(await page.locator('dialog[open]').count(),0);await open(page);
    assert.equal(await item(page,'account_link').locator('[data-purpose-choice]').innerText(),'Allow choice saved');assert.equal(posts.length,1);
    await context.close();
  });
  await check('Injected unavailable capability and historical allow never preselect a new purpose',async()=>{
    const {context,page,posts}=await session();await page.route('**/api/v3/measurement/purposes/context',async route=>{const response=await route.fetch();const value=await response.json();value.legacy_advertising.choice='allow';for(const state of Object.values(value.purposes))state.capability='unavailable';await route.fulfill({json:value});});
    await open(page);assert.equal(await page.locator('[data-c-purpose-manager] input:checked').count(),0);assert.equal(await page.locator('[data-purpose-legacy]').innerText(),'Earlier allow');
    assert(await item(page,'account_link').locator('input[value=allow]').isDisabled());await item(page,'account_link').locator('[data-purpose-deny]').click();await until(page,'account_link','saved');assert.equal(posts[0].choice,'deny');await context.close();
  });
  await check('Real login scope change clears old drafts before any preference write',async()=>{
    const {context,page,posts}=await session();await auth(context,page);await open(page);await item(page,'advertising').locator('input[value=allow]').check();
    const token=await page.locator('meta[name="csrf-token"]').getAttribute('content');assert([200,204].includes((await context.request.post(base+'/api/v1/auth/logout',{headers:{'X-CSRF-TOKEN':token,'Origin':base,'Accept':'application/json'},data:{}})).status()));
    await item(page,'advertising').locator('[data-purpose-save]').click();await until(page,'advertising','login_required');assert.equal(await page.locator('[data-c-purpose-manager] input:checked').count(),0);assert.equal(posts.length,0);
    await item(page,'account_link').locator('input[value=allow]').check();await item(page,'account_link').locator('[data-purpose-save]').click();assert.equal(posts.length,0);await context.close();
  });
  await check('Real registration rotates CSRF without reload; v2 off and v3 save then verify/relogin preserve deny',async()=>{
    const {context,page,posts}=await session();await page.goto(base+'/identity/register?lang=en',{waitUntil:'domcontentloaded'});
    const old=await page.locator('meta[name="csrf-token"]').getAttribute('content');const url=page.url();let registrations=0;
    page.on('request',r=>{if(r.url().endsWith('/api/v1/auth/register'))registrations++;});
    const account={email:crypto.randomUUID()+'@example.test',password:'OnlyTest!A1'+crypto.randomUUID()};
    const before=Number(sql('SELECT count(*) FROM identity_viewers'));
    const status=await page.evaluate(async({account,token})=>{
      const form=document.querySelector('[data-identity-form]');
      const response=await fetch('/api/v1/auth/register',{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({name:'Synthetic same DOM',...account,password_confirmation:account.password,terms_accepted:true,terms_version:form.querySelector('[name=terms_version]').value,privacy_notice_version:form.querySelector('[name=privacy_notice_version]').value})});return response.status;
    },{account,token:old});
    assert.equal(status,200);assert.equal(page.url(),url);assert.equal(Number(sql('SELECT count(*) FROM identity_viewers')),before+1);
    const current=(await (await context.request.get(base+'/api/v1/auth/csrf')).json()).csrf_token;assert.notEqual(current,old);assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'),old);
    await open(page);const off=page.waitForResponse(r=>r.url().endsWith('/api/v2/measurement/preferences'));
    await page.locator('[data-c-dialog] [data-c-off]').click();await page.waitForFunction(()=>document.querySelector('[data-measurement-c]').dataset.state==='off');assert.equal((await off).status(),200);
    await choose(page,'account_link','allow');await until(page,'account_link','saved');assert.equal(posts.length,1);await page.locator('[data-c-close]').click();
    const verification=execFileSync('/opt/homebrew/opt/php@8.4/bin/php',[path.join(__dirname,'c-ui-fixture.php')],{env:{...process.env,H5_C_UI_VERIFY:'1',H5_C_UI_VERIFY_EMAIL:account.email},encoding:'utf8'}).trim();
    assert(verification.startsWith(base+'/api/v1/auth/email/verify/'));assert.equal((await context.request.get(verification,{headers:{'Accept':'application/json'}})).status(),200);
    assert.equal((await (await context.request.get(base+'/api/v2/measurement/context')).json()).site_choice,'deny');
    await auth(context,page,0,true);
    const token=(await (await context.request.get(base+'/api/v1/auth/csrf')).json()).csrf_token;
    assert.equal((await context.request.post(base+'/api/v1/auth/login',{headers:{'X-CSRF-TOKEN':token,'Accept':'application/json','Origin':base},data:account})).status(),200);
    await page.reload({waitUntil:'domcontentloaded'});assert.equal((await (await context.request.get(base+'/api/v2/measurement/context')).json()).site_choice,'deny');assert.equal(registrations,1);
    fs.writeFileSync(path.join(out,'same-dom-registration.json'),JSON.stringify({actual_registration:true,registration_posts:registrations,no_reload_before_off:true,csrf_rotated:true,v2_deny_confirmed:true,v3_allow_saved_not_running:true,actual_verification:true,deny_after_relogin:true},null,2));await context.close();
  });
  for(const failure of ['csrf-503','write-419'])await check(failure+' leaves v2 paused and v3 unconfirmed, with explicit retry only',async()=>{
    const {context,page,posts}=await session();await auth(context,page);await open(page);let fail=true,offPosts=0;
    page.on('request',r=>{if(r.url().endsWith('/api/v2/measurement/preferences'))offPosts++;});
    if(failure==='csrf-503')await page.route('**/api/v1/auth/csrf',r=>fail?r.fulfill({status:503,contentType:'application/json',body:'{}'}):r.continue());
    else await page.route(url=>['/api/v2/measurement/preferences','/api/v3/measurement/purposes/preferences'].includes(url.pathname),r=>fail?r.fulfill({status:419,contentType:'application/json',body:'{}'}):r.continue());
    await choose(page,'account_link','allow');await until(page,'account_link',failure==='csrf-503'?'failed':'login_required');
    assert.equal(posts.length,failure==='csrf-503'?0:1);
    await page.locator('[data-c-dialog] [data-c-off]').click();await page.waitForFunction(()=>document.querySelector('[data-measurement-c]').dataset.state==='save_failed');
    assert.equal(offPosts,failure==='csrf-503'?0:1);await page.waitForTimeout(250);assert.equal(offPosts,failure==='csrf-503'?0:1);
    fail=false;
    if(failure==='write-419'){await open(page);await choose(page,'account_link','allow');}else await item(page,'account_link').locator('[data-purpose-save]').click();
    await until(page,'account_link','saved');await page.locator('[data-c-retry]').click();await page.waitForFunction(()=>document.querySelector('[data-measurement-c]').dataset.state==='off');await context.close();
  });
  await check('Normal logout/account switch discards drafts and hides prior owner allow',async()=>{
    const {context,page}=await session();await auth(context,page,0);await open(page);await choose(page,'account_link','allow');await until(page,'account_link','saved');
    await item(page,'advertising').locator('input[value=allow]').check();await auth(context,page,0,true);await auth(context,page,1);await open(page);
    assert.equal(await page.locator('[data-c-purpose-manager] input:checked').count(),0);assert.equal(await item(page,'account_link').locator('[data-purpose-choice]').innerText(),'No confirmed choice');await context.close();
  });
  await check('Original site deny survives purpose choices, dialog close and normal login; preview still plays',async()=>{
    const {context,page}=await session();await page.locator('[data-c-notice] [data-c-off]').click();await page.waitForFunction(()=>document.querySelector('[data-measurement-c]').dataset.state==='off');
    await auth(context,page);await open(page);await choose(page,'account_link','allow');await until(page,'account_link','saved');await page.locator('[data-c-close]').click();
    const r=await context.request.get(base+'/api/v2/measurement/context');assert.equal((await r.json()).site_choice,'deny');
    await page.goto(base+'/dramas/'+fixture.drama+'/episodes/'+fixture.episodes[0],{waitUntil:'domcontentloaded'});await page.locator('[data-play]').click();await page.waitForFunction(()=>document.querySelector('video').currentTime>0.5);
    assert.equal((await (await context.request.get(base+'/api/v2/measurement/context')).json()).site_effective,'off');await context.close();
  });
  assert.deepEqual(errors,[]);assert.deepEqual(external,[]);assert.equal(sql('SELECT count(*) FROM billing_orders'),'0');
  fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({passed:results.length,results,errors,external,scope:'04 real synthetic 05-A HTTP management; faults labelled; not 01-B or 00/06 final combination'},null,2));
})().catch(async error=>{console.error(error);process.exitCode=1;fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({results,errors,error:String(error)},null,2));if(last&&!last.isClosed())await last.screenshot({path:path.join(out,'failure.png')}).catch(()=>{});}).finally(async()=>{await browser?.close();});
