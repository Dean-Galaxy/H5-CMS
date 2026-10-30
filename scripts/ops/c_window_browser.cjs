'use strict';
// 06 actual Chrome driver. --plan is inert; --run requires exact live new C manifest/process.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const crypto=require('node:crypto'),assert=require('node:assert/strict');
const TOOL_ROOT=path.resolve(__dirname,'../..');
const ROOT=process.env.H5_C_TARGET_ROOT||TOOL_ROOT;
const NOTICE='c-notice-c-core-1';
const CASES=['visit','decode-seek','deny-refresh-play','initial-read-503','save-503','revision-409','short-screens','boundaries','real-pressure'];
const PROTECTED=new Set([8206,8303,5606,8377,5877,8406,5906,8407,8408,8409,8410,5910]);
function digest(bytes){return crypto.createHash('sha256').update(bytes).digest('hex');}
function privateJson(file){const s=fs.lstatSync(file);assert(s.isFile()&&!s.isSymbolicLink()&&s.nlink===1&&s.uid===process.getuid()&&!(s.mode&0o077)&&s.size<=524288);return JSON.parse(fs.readFileSync(file,'utf8'));}
function targetRootGuard(root){assert(path.isAbsolute(root));assert.equal(path.basename(path.dirname(root)),'h5-c-ui-manual');assert.equal(path.basename(root),'H5');assert.equal(fs.realpathSync(root),root);}
function binding(root,cfg,candidate){
  targetRootGuard(root);assert.match(candidate,/^[a-f0-9]{40}$/);assert.equal(cfg.root,root);
  const suffix=digest(root).slice(0,10);assert.equal(cfg.workspace,'h5_'+suffix);assert.equal(cfg.db,'h5_'+suffix);assert.equal(cfg.user,'h5_app_'+suffix);
  for(const k of ['port','db_port'])assert(Number.isInteger(cfg[k])&&cfg[k]>=1024&&cfg[k]<=65535&&!PROTECTED.has(cfg[k]));
  assert.notEqual(cfg.port,cfg.db_port);return `http://127.0.0.1:${cfg.port}`;
}
function noOptionalDelta(a,b){
  const names=['acquisition_c_subjects','acquisition_c_sessions','acquisition_c_events','acquisition_c_event_keys','acquisition_c_plays',
    'acquisition_subjects','acquisition_sources','acquisition_behaviors','acquisition_event_keys'];
  for(const name of names){assert(Number.isSafeInteger(a.counts[name]));assert.equal(b.counts[name],a.counts[name],name);}
  assert.equal(b.c_event_high_watermark,a.c_event_high_watermark);
}
function financialZero(s){for(const name of ['identity_viewers','billing_prices','billing_orders','billing_payments','billing_entitlements','billing_subscriptions','billing_refunds','billing_inbox','billing_outbox','billing_behavior_outbox'])assert.equal(s.counts[name],0,name);}
function validateBatch(batch){
  assert(batch&&Object.keys(batch).length===1&&Array.isArray(batch.events)&&batch.events.length>0&&batch.events.length<=20);
  const allowed=new Set(['page_instance_id','page_type','locale','drama_id','episode_id','play_instance_id','position_seconds','duration_seconds','watched_seconds','startup_ms','stall_ms','error_code']);
  for(const event of batch.events){assert.deepEqual(Object.keys(event).sort(),['event_id','event_name','properties']);assert.match(event.event_id,/^[a-f0-9-]{36}$/i);assert(/^[a-z_]+$/.test(event.event_name));assert(event.properties&&Object.keys(event.properties).every(key=>allowed.has(key)));}
}
function seekEvidence(events, observation){
  const {before_position,resumed_position,duration,frames_before,frames_after}=observation;
  const progress=events.filter(e=>e.event_name==='watch_progress');
  const realViewingUpper=before_position+(duration-resumed_position)+.5;
  return before_position>=5&&resumed_position>before_position+1&&resumed_position<duration
    &&frames_after>frames_before&&progress.length>0
    &&progress.every(e=>Number.isFinite(e.properties?.watched_seconds)&&e.properties.watched_seconds<=realViewingUpper)
    &&!events.some(e=>e.event_name==='episode_completed');
}
function sqlForPlay(play){
  assert.match(play,/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i);
  return `SELECT json_build_object('plays',(SELECT COALESCE(json_agg(json_build_object('watched_seconds',watched_seconds,'started',started)),'[]'::json) FROM acquisition_c_plays WHERE play_instance_id='${play}'),'events',(SELECT COALESCE(json_agg(json_build_object('event_name',event_name,'watched_seconds',properties->'watched_seconds')),'[]'::json) FROM acquisition_c_events WHERE is_test=true AND properties->>'play_instance_id'='${play}'));`;
}
function serverSeek(cfg,candidate,play){
  binding(ROOT,cfg,candidate);const file=ROOT+'/.runtime/pg-app-password',info=fs.lstatSync(file);
  assert(info.isFile()&&!info.isSymbolicLink()&&info.nlink===1&&info.uid===process.getuid()&&!(info.mode&0o077)&&info.size<=512);
  // Explicit own app role/database; no inherited PGHOSTADDR/service/options. The session is read-only.
  const env={PATH:process.env.PATH||'/usr/bin:/bin',LANG:'C',PGHOST:'127.0.0.1',PGPORT:String(cfg.db_port),PGUSER:cfg.user,PGDATABASE:cfg.db+'_test',PGPASSWORD:fs.readFileSync(file,'utf8'),PGCONNECT_TIMEOUT:'5',PGOPTIONS:'-c default_transaction_read_only=on'};
  return JSON.parse(cp.execFileSync('/opt/homebrew/opt/postgresql@17/bin/psql',['-X','-q','-A','-t','-v','ON_ERROR_STOP=1','-c',sqlForPlay(play)],{env,encoding:'utf8',stdio:['ignore','pipe','pipe']}));
}
function runtime(candidate){
  targetRootGuard(ROOT);
  assert.equal(cp.execFileSync('git',['status','--porcelain','--','scripts/ops/c_window_browser.cjs'],{cwd:TOOL_ROOT,encoding:'utf8'}).trim(),'','Observation driver must be committed');
  assert.equal(cp.execFileSync('git',['rev-parse','HEAD'],{cwd:ROOT,encoding:'utf8'}).trim(),candidate);
  const cfg=privateJson(ROOT+'/.runtime/workspace.json'),base=binding(ROOT,cfg,candidate);
  const manifestFile=ROOT+'/.runtime/06/c-window/manifest.json',manifest=privateJson(manifestFile);
  cp.execFileSync('python3',['-B',ROOT+'/scripts/ops/c_window_preflight.py','verify','--root',ROOT,'--candidate',candidate,'--manifest',manifestFile],{cwd:ROOT,stdio:'pipe'});
  assert.equal(manifest.measurement_enabled,true);
  const record=privateJson(ROOT+'/.runtime/06/c-window/process.json');assert.equal(record.root,ROOT);assert.equal(record.candidate,candidate);
  const command=['/opt/homebrew/opt/php@8.4/bin/php','-d','expose_php=0','-S',`127.0.0.1:${cfg.port}`,'-t',ROOT+'/h5-platform/public',ROOT+'/h5-platform/tests/Browser/Support/c-window-router.php'];
  assert.deepEqual(record.command,command);assert.equal(record.manifest_sha256,digest(fs.readFileSync(manifestFile)));
  assert(Number.isInteger(record.pid)&&record.pid>1);
  assert.equal(cp.execFileSync('ps',['-p',String(record.pid),'-o','command='],{encoding:'utf8'}).trim(),command.join(' '));
  assert.equal(cp.execFileSync('ps',['-p',String(record.pid),'-o','lstart='],{encoding:'utf8'}).trim(),record.started_at);
  const fixture=privateJson(ROOT+'/.runtime/06/analytics-browser/fixture.json');assert.equal(fixture.candidate,candidate);assert.equal(fixture.synthetic_only,true);assert.equal(fixture.free_count,5);assert.equal(fixture.episodes.length,6);
  return {cfg,base,manifest,fixture};
}
async function run(candidate,selected=CASES){
  assert(Array.isArray(selected)&&selected.length>0&&new Set(selected).size===selected.length&&selected.every(name=>CASES.includes(name)));
  process.umask(0o077);const live=runtime(candidate),{base,fixture}=live;
  const directory=ROOT+'/.runtime/06/c-window/browser-'+Date.now()+'-'+crypto.randomBytes(3).toString('hex');
  fs.mkdirSync(directory,{mode:0o700});
  const toolCommit=cp.execFileSync('git',['rev-parse','HEAD'],{cwd:TOOL_ROOT,encoding:'utf8'}).trim();
  const checks=[],result={candidate,tool_commit:toolCommit,driver_sha256:digest(fs.readFileSync(__filename)),cases:[],passed:false,real_device:false,official_payment:false,external_attempts:0};
  let phase='gate',browser;
  const check=(name,value)=>{assert(value,name);checks.push({name,passed:true});};
  const state=()=>JSON.parse(cp.execFileSync('python3',['-B',ROOT+'/scripts/ops/c_window.py','state','--candidate',candidate],{cwd:ROOT,encoding:'utf8',stdio:['ignore','pipe','pipe']}));
  const initial=state();financialZero(initial);
  check('real UnconfiguredGateway and API off',initial.gateway_class==='App\\Modules\\Billing\\Gateway\\UnconfiguredGateway'&&initial.paypal_api_enabled===false&&initial.new_purchase_provider==='unconfigured'&&initial.payment_fixture_installed===false);
  check('external transport/debug off',initial.advertising_external_enabled===false&&initial.advertising_transport==='disabled'&&initial.debug===false);
  let privateRaw=[];
  try{
    const {chromium}=require(process.env.H5_PLAYWRIGHT_MODULE||'playwright');
    browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true,args:['--no-proxy-server','--disable-background-networking','--disable-component-update','--disable-sync','--no-first-run']});
    result.browser_version=browser.version();
    async function session(name,{fault=null,locale='en',width=390,height=844,holdStart=false,headers={}}={}){
      const context=await browser.newContext({viewport:{width,height},serviceWorkers:'block',locale,extraHTTPHeaders:headers});
      const raw={name,requests:[],responses:[],batches:[],scriptErrors:[],media:[],requestFailures:[],console:[],initialDom:[]};privateRaw.push(raw);
      let release;const gate=new Promise(resolve=>release=resolve);
      await context.route('**/*',async route=>{
        const request=route.request(),url=new URL(request.url());
        if(['blob:','data:'].includes(url.protocol))return route.continue();
        if(url.origin!==base){result.external_attempts++;return route.abort();}
        if(holdStart&&url.pathname==='/api/v2/measurement/start')await gate;
        if(fault){const injected=await fault(route,url);if(injected)return;}
        return route.continue();
      });
      assert.equal(typeof context.routeWebSocket,'function','Playwright with routeWebSocket required');
      await context.routeWebSocket('**/*',socket=>{result.external_attempts++;socket.close();});
      await context.addInitScript(()=>{
        const original=HTMLMediaElement.prototype.canPlayType;
        HTMLMediaElement.prototype.canPlayType=function(type){return /mpegurl/i.test(type)?'':original.call(this,type);};
        const probe=window.__cProbe={modalCalls:[],starts:[],noticeAt:null,contexts:[]};
        const modal=HTMLDialogElement.prototype.showModal;
        HTMLDialogElement.prototype.showModal=function(){probe.modalCalls.push(performance.now());return modal.call(this);};
        const observer=new IntersectionObserver(entries=>{if(entries.some(e=>e.isIntersecting&&e.intersectionRatio>=.98)&&probe.noticeAt===null)probe.noticeAt=performance.now();},{threshold:[0,.98,1]});
        let observed=false;const observe=()=>{const n=document.querySelector('[data-c-delivery]');if(n&&!observed){observed=true;observer.observe(n);}};
        new MutationObserver(observe).observe(document,{subtree:true,childList:true});observe();
        const originalFetch=window.fetch;
        window.fetch=function(input,options){
          if(new URL(typeof input==='string'?input:input.url,location.href).pathname==='/api/v2/measurement/start'){
            const n=document.querySelector('[data-c-delivery]'),r=n?.getBoundingClientRect();
            probe.starts.push({at:performance.now(),noticeAt:probe.noticeAt,visible:!!r&&r.top>=0&&r.left>=0&&r.bottom<=innerHeight&&r.right<=innerWidth&&getComputedStyle(n).visibility!=='hidden'&&document.visibilityState==='visible'});
          }
          const pending=originalFetch.call(this,input,options);
          if(new URL(typeof input==='string'?input:input.url,location.href).pathname==='/api/v2/measurement/context')pending.then(response=>response.clone().json().then(value=>probe.contexts.push({status:response.status,reason:value.reason,site_choice:value.site_choice,site_effective:value.site_effective}))).catch(()=>{});
          return pending;
        };
      });
      const page=await context.newPage();page.setDefaultTimeout(15000);
      page.on('pageerror',error=>raw.scriptErrors.push(error.name));
      page.on('requestfailed',request=>raw.requestFailures.push({path:new URL(request.url()).pathname,error:request.failure()?.errorText}));
      page.on('console',message=>{if(message.type()==='error'&&raw.console.length<20)raw.console.push(message.text());});
      page.on('request',request=>{
        const url=new URL(request.url());raw.requests.push({path:url.pathname,method:request.method()});
        if(url.pathname==='/api/v2/measurement/events')try{raw.batches.push(JSON.parse(request.postData()));}catch{}
      });
      page.on('response',response=>{const url=new URL(response.url());raw.responses.push({path:url.pathname,status:response.status()});if(url.pathname.startsWith('/api/v1/content/playback/'))raw.media.push({path:url.pathname,status:response.status()});});
      const goto=async (target='/')=>{
        const response=await page.goto(base+target,{waitUntil:'domcontentloaded'});assert.equal(response.status(),200);await page.locator('[data-measurement-c]').waitFor();
        raw.initialDom.push(await page.evaluate(()=>{const n=document.querySelector('[data-c-delivery]'),r=n?.getBoundingClientRect();return {viewport:{width:innerWidth,height:innerHeight},scrollY,visibility:document.visibilityState,notice:r?{top:r.top,bottom:r.bottom,left:r.left,right:r.right,height:r.height}:null,state:document.querySelector('[data-measurement-c]')?.dataset.state};}));
        if(name==='visit')await page.screenshot({path:directory+'/visit-natural-viewport.png',fullPage:false});
        // Explicitly model the user reaching the notice, preserving the product's unchanged 98% gate.
        await page.locator('[data-c-delivery]').scrollIntoViewIfNeeded();
      };
      const waitStatus=async value=>page.waitForFunction(value=>document.querySelector('[data-measurement-c]')?.dataset.state===value,value);
      const off=async()=>{const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/v2/measurement/preferences'&&r.request().method()==='PATCH');await page.locator('[data-c-off]').first().click();return response;};
      const screenshot=async label=>page.screenshot({path:directory+'/'+name+'-'+label+'.png',fullPage:true});
      const close=async()=>{release();try{raw.finalDom=await page.evaluate(()=>({probe:window.__cProbe,state:document.querySelector('[data-measurement-c]')?.dataset.state,scrollY,viewport:{width:innerWidth,height:innerHeight}}));await page.screenshot({path:directory+'/'+name+'-final.png',fullPage:false,timeout:5000});}catch{}try{check(name+' no script errors',raw.scriptErrors.length===0);}finally{await context.close();}};
      return {context,page,raw,goto,waitStatus,off,screenshot,close,release};
    }
    async function play(s,seek=false){
      await s.page.locator('video').evaluate(video=>video.muted=true);
      await s.page.locator('[data-play]').click();
      await s.page.waitForFunction(()=>{const v=document.querySelector('video');return v&&!v.paused&&v.currentTime>.6&&v.readyState>=3&&v.getVideoPlaybackQuality().totalVideoFrames>0;},null,{timeout:35000});
      const before=await s.page.locator('video').evaluate(v=>({time:v.currentTime,frames:v.getVideoPlaybackQuality().totalVideoFrames,mse:v.currentSrc.startsWith('blob:'),error:v.error?.code??null}));
      check('actual signed HLS/MSE decoded frames',before.mse&&before.frames>0&&before.error===null&&s.raw.media.some(r=>r.path.endsWith('.m3u8')&&r.status===200)&&s.raw.media.some(r=>r.path.endsWith('.ts')&&r.status===200));
      const unsigned=await s.context.request.get(base+s.raw.media.find(r=>r.path.endsWith('.m3u8')&&r.status===200).path);
      check('actual unsigned media request403',unsigned.status()===403);
      if(seek){
        // Native video controls are not stable DOM. This is a real media seek via keyboard, not a synthetic seeking/timeupdate event.
        // Observe the first genuine five-second report before seeking; a sub-second play need not emit progress.
        await s.page.waitForFunction(()=>{const v=document.querySelector('video');return v.currentTime>=5.2&&!v.ended;},null,{timeout:15000});
        const paused=await s.page.locator('video').evaluate(v=>{v.pause();return {position:v.currentTime,frames:v.getVideoPlaybackQuality().totalVideoFrames};});
        await s.page.locator('video').focus();await s.page.keyboard.press('End');
        await s.page.waitForFunction(prior=>document.querySelector('video').currentTime>prior+1,paused.position);
        const resumed=await s.page.locator('video').evaluate(v=>{v.currentTime=Math.min(v.currentTime,v.duration-.35);return {position:v.currentTime,duration:v.duration};});
        await s.page.locator('video').evaluate(v=>v.play());
        await s.page.waitForFunction(()=>document.querySelector('video').ended,null,{timeout:15000});
        await s.page.waitForTimeout(1600);
        const observation={before_position:paused.position,resumed_position:resumed.position,duration:resumed.duration,frames_before:paused.frames,frames_after:await s.page.locator('video').evaluate(v=>v.getVideoPlaybackQuality().totalVideoFrames)};
        s.raw.actualSeek=observation;
        const events=s.raw.batches.flatMap(b=>b.events||[]);
        check('real seek does not count skipped viewing',seekEvidence(events,observation));
        const playId=events.find(e=>e.event_name==='watch_progress')?.properties.play_instance_id;
        const persisted=serverSeek(live.cfg,candidate,playId);s.raw.serverSeek=persisted;
        check('own server play counter excludes skipped viewing',persisted.plays.length===1&&persisted.plays[0].started===true&&persisted.plays[0].watched_seconds<=observation.before_position+(observation.duration-observation.resumed_position)+.5&&seekEvidence(persisted.events.map(e=>({event_name:e.event_name,properties:{watched_seconds:e.watched_seconds}})),observation));
      }else{
        await s.page.waitForTimeout(700);
        const after=await s.page.locator('video').evaluate(v=>({time:v.currentTime,frames:v.getVideoPlaybackQuality().totalVideoFrames}));
        check('real free playback continues',after.time>before.time+.3&&after.frames>before.frames);
      }
      await s.screenshot('mse');
    }
    for(const name of selected){
      phase=name;const entry={name,passed:false};result.cases.push(entry);
      if(name==='visit'){
        const before=state(),s=await session(name,{holdStart:true});
        try{
          await s.goto();await s.page.waitForFunction(()=>window.__cProbe.starts.length>0);
          const observed=await s.page.evaluate(()=>window.__cProbe);
          check('no automatic dialog',observed.modalCalls.length===0&&!await s.page.locator('[data-c-dialog]').evaluate(d=>d.open));
          check('notice visible before start dispatch',observed.starts.every(v=>v.visible&&v.noticeAt!==null&&v.at>=v.noticeAt));
          noOptionalDelta(before,state());check('held start creates no identifiers or events',true);
          s.release();await s.waitStatus('active');await s.page.waitForTimeout(1000);
          check('successful start produces actual C events',state().counts.acquisition_c_events>before.counts.acquisition_c_events);
          check('old v1 script absent',await s.page.locator('script[src*="/audience/analytics.js"]').count()===0);
          await s.screenshot('first-visit');
        }finally{await s.close();}
      }else if(name==='decode-seek'){
        const s=await session(name);try{await s.goto(`/dramas/${fixture.drama_id}/episodes/${fixture.episodes[0]}`);await s.waitStatus('active');await play(s,true);}finally{await s.close();}
      }else if(name==='deny-refresh-play'){
        const s=await session(name);try{
          await s.goto();await s.waitStatus('active');assert.equal((await s.off()).status(),200);await s.waitStatus('off');await s.page.waitForTimeout(1000);
          const before=state();await s.page.reload();await s.waitStatus('off');
          await s.goto(`/dramas/${fixture.drama_id}/episodes/${fixture.episodes[0]}`);await s.waitStatus('off');await play(s);await s.page.waitForTimeout(1200);
          noOptionalDelta(before,state());check('confirmed off refresh and real free playback add zero C/v1',true);
        }finally{await s.close();}
      }else if(name==='initial-read-503'){
        const s=await session(name,{fault:async(route,url)=>{if(url.pathname==='/api/v2/measurement/context'){await route.fulfill({status:503,contentType:'application/json',body:'{"error":"06_INITIAL_READ_FAILURE"}'});return true;}}});
        try{await s.goto();await s.waitStatus('read_failed');const response=await s.off();assert.equal(response.status(),200);const saved=await response.json();check('initial GET503 still permits real server confirmed deny',saved.site_choice==='deny'&&saved.site_effective==='off');await s.waitStatus('off');await s.screenshot('confirmed-off');}finally{await s.close();}
      }else if(name==='save-503'){
        const armed=JSON.parse(cp.execFileSync('python3',['-B',ROOT+'/scripts/ops/c_window_fault.py','arm','--candidate',candidate],{cwd:ROOT,encoding:'utf8',stdio:['ignore','pipe','pipe']}));
        let s;
        try{
          s=await session(name,{headers:{'X-06-C-Fault':armed.nonce}});await s.goto();await s.waitStatus('active');
          const response=await s.off();assert.equal(response.status(),503);assert.equal((await response.json()).reason,'MEASUREMENT_UNAVAILABLE');await s.waitStatus('save_failed');
          const receipt=privateJson(ROOT+'/.runtime/06/analytics-browser/c-store-fault-receipt.json');
          check('real PG write-path fault reached server and rolled back',receipt.candidate===candidate&&receipt.nonce_sha256===digest(armed.nonce)&&receipt.sqlstate==='22012');
          const actual=await s.context.request.get(base+'/api/v2/measurement/context');assert.equal(actual.status(),200);
          check('real storage503 does not claim server denial',(await actual.json()).site_choice!=='deny');await s.screenshot('not-confirmed');
        }finally{
          try{if(s)await s.close();}finally{cp.execFileSync('python3',['-B',ROOT+'/scripts/ops/c_window_fault.py','clear','--candidate',candidate,'--nonce',armed.nonce],{cwd:ROOT,stdio:'pipe'});}
        }
      }else if(name==='revision-409'){
        const s=await session(name);try{
          await s.goto();await s.waitStatus('active');await s.page.waitForTimeout(1200);
          const conflict=await s.page.evaluate(async notice=>{const headers={'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,'Accept':'application/json'};const r=await fetch('/api/v2/measurement/preferences',{method:'PATCH',headers,body:JSON.stringify({site_session:'allow',notice_version:notice,expected_revision:0})});return r.status;},NOTICE);
          assert.equal(conflict,200);const before=s.raw.requests.filter(r=>r.path.endsWith('/preferences')).length;
          assert.equal((await s.off()).status(),409);await s.waitStatus('conflict');await s.page.waitForTimeout(1200);
          check('real revision409 does not automatically replay choice',s.raw.requests.filter(r=>r.path.endsWith('/preferences')).length===before+1);
          const actual=await s.context.request.get(base+'/api/v2/measurement/context');assert.equal(actual.status(),200);check('409 readback preserves actual server choice',(await actual.json()).site_choice==='allow');await s.screenshot('conflict');
        }finally{await s.close();}
      }else if(name==='short-screens'){
        for(const locale of ['en','zh_CN']){const s=await session(name+'-'+locale,{locale:locale==='en'?'en-US':'zh-CN',width:320,height:500});try{
          await s.goto('/?lang='+locale);check(locale+' renders requested actual language',await s.page.locator('[data-measurement-c]').getAttribute('data-locale')===locale);
          check(locale+' no horizontal overflow',await s.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
          (entry.short_screens ||= []).push({locale,notice_height:await s.page.locator('[data-c-notice]').evaluate(n=>n.getBoundingClientRect().height),viewport:{width:320,height:500}});
          await s.screenshot('notice');await s.page.locator('[data-c-open]').first().click();
          const off=s.page.locator('[data-c-dialog] [data-c-off]');await off.scrollIntoViewIfNeeded();check(locale+' close action visible and operable',await off.isVisible()&&await off.isEnabled());
          await s.screenshot('privacy');await s.page.locator('[data-c-close]').click();check(locale+' user close does not grant a choice',!await s.page.locator('[data-c-dialog]').evaluate(d=>d.open));
        }finally{await s.close();}}
      }else if(name==='boundaries'){
        const s=await session(name);try{
          await s.goto();
          for(const endpoint of ['/api/v1/auth/register','/identity/register','/membership/checkout','/membership/cancel','/membership/resume','/billing/return/recheck','/api/v1/billing/refunds','/webhooks/paypal','/webhooks/stripe']){
            const response=await s.context.request.post(base+endpoint,{data:{},headers:{Origin:base}});assert.equal(response.status(),403);assert.equal((await response.json()).error,'C_SYNTHETIC_PRIVACY_WINDOW_ONLY');
          }check('actual local registration finance and official webhook endpoints403',true);
          await s.goto(`/dramas/${fixture.drama_id}/episodes/${fixture.episodes[5]}`);
          const mediaBefore=s.raw.media.length;
          const grant=s.page.waitForResponse(r=>new URL(r.url()).pathname.endsWith('/playback')&&r.request().method()==='POST');
          await s.page.locator('[data-play]').click();const denied=await (await grant).json();
          check('sixth episode remains membership denied',denied.allowed===false&&denied.reason==='MEMBERSHIP_REQUIRED');
          await s.page.waitForTimeout(300);check('membership refusal issues no media requests',s.raw.media.length===mediaBefore);
        }finally{await s.close();}
      }else if(name==='real-pressure'){
        const s=await session(name);try{
          await s.goto();await s.waitStatus('active');
          const budgets=await s.page.evaluate(async notice=>{
            const csrf=document.querySelector('meta[name="csrf-token"]').content,results={};
            // No cache clear/restart/time travel anywhere in this pressure scenario.
            for(const [endpoint,limit] of [['start',65],['events',125],['context',125]]){
              let throttled=false,statuses={};
              for(let i=0;i<limit;i++){
                const body=endpoint==='start'?{notice_version:notice,page_instance_id:crypto.randomUUID()}:endpoint==='events'?{events:[{event_id:crypto.randomUUID(),event_name:'page_view',properties:{page_instance_id:crypto.randomUUID(),page_type:'home',locale:'en'}}]}:null;
                const r=await fetch('/api/v2/measurement/'+endpoint,{method:body?'POST':'GET',credentials:'same-origin',headers:{Accept:'application/json',...(body?{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}:{})},...(body?{body:JSON.stringify(body)}:{})});
                statuses[r.status]=(statuses[r.status]||0)+1;
                if(r.status===429){throttled=Number(r.headers.get('Retry-After'))>0;break;}
                if(r.status!==200)throw new Error('unexpected pressure status');
              }results[endpoint]={throttled,statuses};
            }return results;
          },NOTICE);
          check('real context/start/event budgets each reach server429',Object.values(budgets).every(v=>v.throttled));entry.server_pressure=budgets;
          // Fresh browser has no cached context. Its initial GET really hits the exhausted shared IP context budget.
          const fresh=await session(name+'-unknown');try{
            await fresh.goto();await fresh.waitStatus('read_failed');check('fresh initial context gets real429',fresh.raw.responses.some(r=>r.path==='/api/v2/measurement/context'&&r.status===429));
            const response=await fresh.off();assert.equal(response.status(),200);const actual=await response.json();check('pressure leaves real close quota usable without cache clearing',actual.site_choice==='deny'&&actual.site_effective==='off');await fresh.waitStatus('off');await fresh.screenshot('real429-off');
          }finally{await fresh.close();}
        }finally{await s.close();}
      }
      entry.passed=true;
    }
    phase='final';financialZero(state());const final=state();
    check('no old v1 measurement created', ['acquisition_subjects','acquisition_sources','acquisition_behaviors','acquisition_event_keys'].every(k=>final.counts[k]===initial.counts[k]));
    privateRaw.forEach(raw=>raw.batches.forEach(validateBatch));check('observed C event fields stay within whitelist',true);
    check('zero observed browser external attempts',result.external_attempts===0);result.passed=true;
  }catch(error){result.failed_phase=phase;result.error_type=error.name;fs.writeFileSync(directory+'/failure-private.txt',String(error.stack||error),{mode:0o600});process.exitCode=1;}
  finally{
    if(browser)await browser.close();result.checks=checks;
    fs.writeFileSync(directory+'/network-private.json',JSON.stringify(privateRaw,null,2),{mode:0o600});
    fs.writeFileSync(directory+'/result-private.json',JSON.stringify(result,null,2),{mode:0o600});
    console.log(JSON.stringify({candidate,tool_commit:toolCommit,driver_sha256:result.driver_sha256,passed:result.passed,checks:checks.length,failed_phase:result.failed_phase,cases:result.cases.map(c=>({name:c.name,passed:c.passed})),browser_executed:!!browser,real_device:false}));
  }
  return result;
}
module.exports={CASES,targetRootGuard,sqlForPlay,binding,noOptionalDelta,financialZero,validateBatch,seekEvidence,run};
if(require.main===module){
  const args=process.argv.slice(2);
  if(args.length===1&&args[0]==='--plan')console.log(JSON.stringify({cases:CASES,browser_executed:false,runtime_started:false}));
  else if(args.length===3&&args[0]==='--run'&&args[1]==='--candidate'&&/^[a-f0-9]{40}$/.test(args[2]))run(args[2]).catch(()=>{console.error('06 C browser admission failed; no public raw diagnostics.');process.exitCode=1;});
  else{console.error('Use --plan or --run --candidate FINAL_40_SHA.');process.exitCode=64;}
}
