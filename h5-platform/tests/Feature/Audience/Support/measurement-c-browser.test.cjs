/* Real Chrome + real Laravel/C core/Content HTTP. Fault injection is explicitly labelled below. */
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync,spawn} = require('node:child_process');
const net = require('node:net');
const base = process.env.H5_C_UI_BASE, out = process.env.H5_C_UI_OUT;
assert(base?.startsWith('http://127.0.0.1:') && out);
const fixture = JSON.parse(fs.readFileSync(path.join(out, 'fixture.json')));
const sql = query => execFileSync('/opt/homebrew/opt/postgresql@17/bin/psql', ['-At', '-v', 'ON_ERROR_STOP=1', '-c', query], {
  env: {...process.env, PGHOST:'127.0.0.1', PGPORT:process.env.DB_PORT, PGDATABASE:process.env.DB_DATABASE,
    PGUSER:process.env.DB_USERNAME, PGPASSWORD:process.env.DB_PASSWORD}, encoding:'utf8',
}).trim();
const count = () => Number(sql('SELECT count(*) FROM acquisition_c_events'));
const results = [], errors = [], eventRequests = [], external = [], legacy = [], responses = [], referrers = [];
let browser, lastPage;
const noticeOnly = process.env.H5_C_UI_NOTICE_ONLY === '1';
const noticeChecks = new Set([
  'Actual C start waits for context and visible notice; no automatic modal or pre-start events',
  'en 320×500: notice, manually opened scroll panel, readable details and reachable close/off',
  'zh-CN 320×500: notice, manually opened scroll panel, readable details and reachable close/off',
  'Actual deny survives refresh; free preview still plays and creates no new C events',
]);
function resetOwnedCache() {
  assert(process.env.DB_DATABASE.endsWith('_cui1_audience_http_test'));
  const owned = JSON.parse(fs.readFileSync(path.join(out,'../c-ui-1-audience-owned.json')));
  assert.equal(owned.database,process.env.DB_DATABASE);
  execFileSync('/opt/homebrew/opt/php@8.4/bin/php',['artisan','cache:clear'],{cwd:path.join(owned.root,'h5-platform'),env:process.env});
}
const check = async (name, run) => {
  if (noticeOnly && !noticeChecks.has(name)) return;
  resetOwnedCache(); await run(); results.push({name, passed:true}); console.log('PASS '+name);
};
async function session(locale = 'en', viewport = {width:1280,height:850}) {
  const context = await browser.newContext({viewport, locale});
  await context.route('**/*', route => {
    const url = route.request().url();
    if (!url.startsWith(base+'/')) { external.push(new URL(url).origin); return route.abort(); }
    if (url.includes('/api/v1/tracking/')) legacy.push(url);
    if (url.endsWith('/api/v2/measurement/events')) eventRequests.push(route.request().postDataJSON());
    return route.continue();
  });
  const page = await context.newPage();
  lastPage = page;
  page.on('pageerror', e => errors.push(e.message));
  page.on('request', request => { if(request.url().includes('/api/v2/measurement/')) referrers.push(request.headers().referer || ''); });
  page.on('response', async response => {
    if (response.url().includes('/api/v2/measurement/') && response.status() >= 400) {
      responses.push({endpoint:response.url().split('/').pop(),status:response.status(),body:await response.text().catch(()=>'<unavailable>')});
    }
  });
  return {context,page};
}
async function active(page, url = '/') {
  await page.goto(base+url, {waitUntil:'domcontentloaded'});
  await page.locator('[data-c-delivery]').scrollIntoViewIfNeeded();
  await page.waitForFunction(() => document.querySelector('[data-measurement-c]')?.dataset.state === 'active');
}
const state = page => page.locator('[data-measurement-c]').getAttribute('data-state');
async function untilState(page, value) { await page.waitForFunction(value => document.querySelector('[data-measurement-c]')?.dataset.state === value, value); }
(async () => {
  browser = await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless:true,
    args:['--autoplay-policy=no-user-gesture-required']});
  await check('Actual C start waits for context and visible notice; no automatic modal or pre-start events', async () => {
    const {context,page} = await session();
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    let starts = 0;
    await page.route('**/api/v2/measurement/context', async route => { await gate; await route.continue(); });
    page.on('request', request => { if (request.url().endsWith('/measurement/start')) starts++; });
    const before = count();
    await page.goto(base+'/?fbclid=forbidden-c-test&utm_source=not-c-data#main', {waitUntil:'domcontentloaded'});
    await page.waitForTimeout(900);
    assert.equal(starts,0); assert.equal(count(),before);
    assert.equal(await page.locator('dialog[open]').count(),0);
    release();
    await page.waitForTimeout(400); assert.equal(starts,0); assert.equal(count(),before);
    await page.locator('[data-c-delivery]').scrollIntoViewIfNeeded();
    await untilState(page,'active');
    await page.waitForTimeout(1200); assert(count() > before);
    await page.screenshot({path:path.join(out,'en-desktop.png'),fullPage:true});
    await context.close();
  });
  for (const locale of ['en','zh-CN']) await check(locale+' 320×500: notice, manually opened scroll panel, readable details and reachable close/off', async () => {
    const {context,page} = await session(locale,{width:320,height:500});
    await active(page,'/?lang='+(locale === 'en'?'en':'zh_CN'));
    assert.equal(await page.locator('dialog[open]').count(),0);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    assert.equal(await page.evaluate(()=>scrollY),0);
    const notice=page.locator('[data-c-notice]'); const noticeBox=await notice.boundingBox();
    const mainHeading=await page.locator('#main h1').boundingBox();
    assert(mainHeading.y+Math.min(40,mainHeading.height)<=500,'Main title begins within the first viewport');
    const buttons=notice.locator('button'); const buttonBoxes=await buttons.evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height};}));
    assert(buttonBoxes.every(box=>box.height>=44 && box.x>=0 && box.x+box.width<=320));
    assert.equal(buttonBoxes[0].y,buttonBoxes[1].y,'Notice actions share a row when space is available');
    assert.equal(await page.evaluate(()=>document.activeElement.tagName),'BODY');
    await page.screenshot({path:path.join(out,locale+'-320-notice-viewport.png')});
    await page.screenshot({path:path.join(out,locale+'-320-notice.png'),fullPage:true});
    const zoom=await page.addStyleTag({content:'[data-c-notice]{font-size:32px!important}[data-c-notice] p,[data-c-notice] button{font-size:28px!important}'});
    const enlarged=await buttons.evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height};}));
    assert(enlarged[1].y>enlarged[0].y,'Larger text wraps actions naturally');
    assert(enlarged.every(box=>box.height>=44 && box.x>=0 && box.x+box.width<=320));
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    for(const button of await buttons.all()) {
      await button.scrollIntoViewIfNeeded();
      assert(await button.evaluate(node=>{const r=node.getBoundingClientRect();return node.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2));}));
    }
    await page.screenshot({path:path.join(out,locale+'-320-notice-large-text.png'),fullPage:true});
    await zoom.evaluate(node=>node.remove()); await page.evaluate(()=>scrollTo(0,0));
    fs.writeFileSync(path.join(out,locale+'-notice-layout.json'),JSON.stringify({viewport:{width:320,height:500},notice:noticeBox,main_title:mainHeading,buttons:buttonBoxes,large_text_buttons:enlarged,no_horizontal_overflow:true,no_automatic_modal:true,no_focus_grab:true},null,2));
    await page.locator('[data-c-open]').first().click();
    const dialog = page.locator('[data-c-dialog]');
    assert(await dialog.isVisible());
    const rect = await dialog.boundingBox(); assert(rect.y >= 0 && rect.y+rect.height <= 501);
    assert.equal(await dialog.locator('.measurement-c-body').evaluate(node=>node.scrollTop),0);
    await page.screenshot({path:path.join(out,locale+'-320-privacy-initial.png')});
    await dialog.locator('[data-c-use] summary').click();
    assert(await dialog.locator('[data-c-use]').getAttribute('open') !== null);
    await dialog.locator('[data-c-use]').evaluate(node => node.scrollIntoView({block:'start'}));
    for (const action of ['[data-c-close]','[data-c-off]']) {
      const box = await dialog.locator(action).boundingBox(); assert(box.height >= 44 && box.y+box.height <= 500);
    }
    await page.screenshot({path:path.join(out,locale+'-320-privacy.png')});
    await dialog.locator('[data-c-close]').click(); assert.equal(await page.locator('dialog[open]').count(),0);
    assert.equal(await state(page),'active');
    await context.close();
  });
  await check('Actual playing advances; preloading and seeking do not create completion or watched time', async () => {
    assert.equal(fixture.drama[14],'7'); assert.equal(fixture.episodes[0][14],'7');
    const {context,page} = await session();
    const url = '/dramas/'+fixture.drama+'/episodes/'+fixture.episodes[0];
    await active(page,url);
    const before = Number(sql("SELECT count(*) FROM acquisition_c_events WHERE event_name='play_started'"));
    await page.waitForTimeout(1000);
    assert.equal(Number(sql("SELECT count(*) FROM acquisition_c_events WHERE event_name='play_started'")),before);
    await page.locator('[data-play]').click();
    await page.waitForFunction(() => document.querySelector('video').currentTime > 5.2);
    await page.evaluate(() => { document.querySelector('video').currentTime = 7.5; });
    await page.waitForTimeout(1600);
    assert(Number(sql("SELECT count(*) FROM acquisition_c_events WHERE event_name='play_started'")) > before);
    const starts = JSON.parse(sql("SELECT coalesce(json_agg(properties), '[]') FROM acquisition_c_events WHERE event_name='play_started'"));
    assert(starts.every(p => p.position_seconds > 0));
    assert.equal(Number(sql("SELECT count(*) FROM acquisition_c_events WHERE event_name='episode_completed'")),0);
    assert(starts.some(p=>p.drama_id===fixture.drama && p.episode_id===fixture.episodes[0]));
    assert(Number(sql('SELECT coalesce(max(watched_seconds),0) FROM acquisition_c_plays')) < 7);
    await page.screenshot({path:path.join(out,'en-player.png'),fullPage:true});
    await context.close();
  });
  await check('Actual deny survives refresh; free preview still plays and creates no new C events', async () => {
    const {context,page} = await session('zh-CN',{width:320,height:500});
    await active(page,'/?lang=zh_CN');
    await page.locator('[data-c-notice] [data-c-off]').click(); await untilState(page,'off');
    const before = count();
    await page.reload({waitUntil:'domcontentloaded'}); await untilState(page,'off');
    await page.goto(base+'/dramas/'+fixture.drama+'/episodes/'+fixture.episodes[1],{waitUntil:'domcontentloaded'});
    await untilState(page,'off'); await page.locator('[data-play]').click();
    await page.waitForFunction(() => document.querySelector('video').currentTime > 1);
    await page.waitForTimeout(900); assert.equal(count(),before);
    assert(await page.locator('[data-c-status]').first().innerText().then(t=>t.includes('服务器已确认')));
    await context.close();
  });
  await check('Injected context 503: local pause/server unconfirmed; privacy and browsing remain usable', async () => {
    const {context,page} = await session();
    await page.route('**/api/v2/measurement/context', route => route.fulfill({status:503,contentType:'application/json',body:'{}'}));
    const before = count(); await page.goto(base+'/',{waitUntil:'domcontentloaded'}); await untilState(page,'read_failed');
    assert.equal(count(),before); await page.locator('[data-c-open]').first().click();
    assert.equal(await page.locator('[data-c-processing]').innerText(),'Not confirmed');
    await page.screenshot({path:path.join(out,'read-failure.png')});
    await page.locator('[data-c-close]').click(); await page.locator('nav a[href="/catalog"]').click();
    await untilState(page,'read_failed'); await context.close();
  });
  await check('Actual preference storage 503 keeps draft across refresh, explicit retry confirms deny', async () => {
    const {context,page} = await session(); await active(page);
    assert(process.env.DB_DATABASE.endsWith('_cui1_audience_http_test'));
    // A temporary NOT VALID constraint faults only future off writes in this owned synthetic DB.
    // Existing rows remain intact, and CoreController must really roll back and return 503.
    sql("ALTER TABLE acquisition_c_preferences ADD CONSTRAINT audience_c_off_failure CHECK (choice <> 'deny') NOT VALID");
    try {
      const response=page.waitForResponse(r=>r.url().endsWith('/measurement/preferences'));
      const deniesBefore=Number(sql("SELECT count(*) FROM acquisition_c_preferences WHERE choice='deny'"));
      await page.locator('[data-c-off]').first().click(); await untilState(page,'save_failed');
      assert.equal((await response).status(),503);
      assert.equal(Number(sql("SELECT count(*) FROM acquisition_c_preferences WHERE choice='deny'")),deniesBefore);
      await page.locator('[data-c-open]').first().click();
      assert.equal(await page.locator('[data-c-processing]').innerText(),'Not confirmed');
      const before = count(); await page.reload({waitUntil:'domcontentloaded'}); await untilState(page,'save_failed');
      assert.equal(count(),before); await page.locator('[data-c-open]').first().click();
      await page.screenshot({path:path.join(out,'save-failure.png')});
    } finally { sql('ALTER TABLE acquisition_c_preferences DROP CONSTRAINT audience_c_off_failure'); }
    await page.locator('[data-c-retry]').click(); await untilState(page,'off'); await context.close();
  });
  await check('Actual read quota 429 does not block an initial revision-zero off request', async () => {
    const {context,page}=await session();
    let limited=false,readAttempts=0;
    for(let i=0;i<130;i++) {
      const response=await context.request.get(base+'/api/v2/measurement/context'); readAttempts++;
      if(response.status()===429){limited=true;break;} assert.equal(response.status(),200);
    }
    assert(limited); assert.equal(readAttempts,121);
    let reads=0;const patches=[];
    page.on('request',r=>{if(r.url().endsWith('/measurement/context'))reads++;if(r.url().endsWith('/measurement/preferences'))patches.push(r.postDataJSON());});
    await page.goto(base+'/',{waitUntil:'domcontentloaded'}); await untilState(page,'read_failed');
    const before=reads; const response=page.waitForResponse(r=>r.url().endsWith('/measurement/preferences'));
    await page.locator('[data-c-off]').first().click(); await untilState(page,'off');
    const saved=await response;assert.equal(saved.status(),200);
    const value=await saved.json();assert.equal(value.site_choice,'deny');assert.equal(value.site_effective,'off');
    assert.equal(reads,before);assert.deepEqual(patches.map(p=>p.expected_revision),[0]);
    assert.equal((await context.request.get(base+'/api/v2/measurement/context')).status(),429);
    fs.writeFileSync(path.join(out,'read-quota-off.json'),JSON.stringify({actual_read_429:true,read_attempts:readAttempts,off_status:200,revision:0,extra_reads:reads-before,server_choice:value.site_choice},null,2));
    await context.close();
  });
  await check('Initial continuous context 503 still allows the real preference endpoint to confirm off', async () => {
    const {context,page}=await session();let reads=0;const patches=[];
    await page.route('**/api/v2/measurement/context',route=>{reads++;return route.fulfill({status:503,contentType:'application/json',body:'{}'});});
    page.on('request',r=>{if(r.url().endsWith('/measurement/preferences'))patches.push(r.postDataJSON());});
    await page.goto(base+'/',{waitUntil:'domcontentloaded'}); await untilState(page,'read_failed');
    const before=reads;const response=page.waitForResponse(r=>r.url().endsWith('/measurement/preferences'));
    await page.locator('[data-c-off]').first().click(); await untilState(page,'off');
    assert.equal((await response).status(),200);assert.equal(reads,before);assert.deepEqual(patches.map(p=>p.expected_revision),[0]);
    await context.close();
  });
  await check('Actual newer revision returns 409 facts without another read or automatic replay', async () => {
    const {context,page} = await session(); await active(page);
    const remoteChoice=async revision=> {
      const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');
      const response=await context.request.patch(base+'/api/v2/measurement/preferences',{headers:{'X-CSRF-TOKEN':csrf,'Origin':base},
        data:{site_session:'allow',notice_version:'c-notice-c-core-1',expected_revision:revision}});
      assert.equal(response.status(),200);return response.json();
    };
    assert.equal((await remoteChoice(0)).revision,1);
    await page.reload({waitUntil:'domcontentloaded'});await untilState(page,'active');
    let reads=0;const patches=[];
    await page.route('**/api/v2/measurement/context',route=>{reads++;return route.fulfill({status:429,contentType:'application/json',body:'{}'});});
    await untilState(page,'read_failed');
    assert.equal((await remoteChoice(1)).revision,2);
    page.on('request',r=>{if(r.url().endsWith('/measurement/preferences'))patches.push(r.postDataJSON());});
    const before=reads;const response=page.waitForResponse(r=>r.url().endsWith('/measurement/preferences'));
    await page.locator('[data-c-off]').first().click(); await untilState(page,'conflict');
    const conflict=await response;assert.equal(conflict.status(),409);assert.equal((await conflict.json()).revision,2);
    await page.waitForTimeout(800); assert.equal(patches.length,1);assert.equal(reads,before);
    await page.locator('[data-c-open]').first().click();
    assert.equal(await page.locator('[data-c-choice]').innerText(),'Explicitly allowed');
    assert.equal(await page.locator('[data-c-processing]').innerText(),'Off');
    assert.equal(await page.locator('[data-c-local]').innerText(),'No optional collection');
    assert(Number(sql("SELECT count(*) FROM acquisition_c_preferences WHERE revision=2 AND choice='allow'"))>0);
    const retry=page.waitForResponse(r=>r.url().endsWith('/measurement/preferences'));
    await page.locator('[data-c-retry]').click();await untilState(page,'off');
    const saved=await retry;assert.equal(saved.status(),200);assert.equal((await saved.json()).revision,3);
    assert.deepEqual(patches.map(p=>p.expected_revision),[1,2]);assert.equal(reads,before);
    await context.close();
  });
  await check('Late start response cannot reopen after off; other tabs receive pause only', async () => {
    const {context,page} = await session();
    let release, received;
    const waitReceived = new Promise(resolve=>received=resolve);
    const gate = new Promise(resolve=>release=resolve);
    await page.route('**/api/v2/measurement/start',async route=> { const response=await route.fetch(); received(); await gate; await route.fulfill({response}); });
    await page.goto(base+'/',{waitUntil:'domcontentloaded'}); await waitReceived;
    await page.locator('[data-c-off]').first().click(); await untilState(page,'off'); release();
    await page.waitForTimeout(900); assert.equal(await state(page),'off');
    await context.close();
    const second = await session(); await active(second.page);
    const tab = await second.context.newPage(); await active(tab);
    await second.page.locator('[data-c-off]').first().click(); await untilState(second.page,'off');
    await tab.waitForFunction(() => document.querySelector('[data-measurement-c]')?.dataset.state !== 'active');
    const stored = await tab.evaluate(() => Object.entries(localStorage).filter(([key])=>key.startsWith('h5.c.')));
    assert.deepEqual(stored,[['h5.c.site-session.paused','1']]);
    await second.context.close();
  });
  await check('Actual membership denial and injected region/media hooks retain short error categories', async () => {
    const {context,page} = await session();
    await active(page,'/dramas/'+fixture.drama+'/episodes/'+fixture.episodes[5]);
    await page.locator('[data-play]').click();
    await page.waitForTimeout(1100);
    await page.evaluate(()=> {
      const root=document.querySelector('[data-player]');
      for(const code of ['REGION_BLOCKED','MEDIA_NOT_READY'])root.dispatchEvent(new CustomEvent('audience:playback',{detail:{type:'error',code}}));
    });
    await page.waitForTimeout(1000);
    const rows=JSON.parse(sql("SELECT coalesce(json_agg(properties),'[]') FROM acquisition_c_events WHERE event_name='player_error'"));
    const codes=new Set(rows.filter(p=>p.episode_id===fixture.episodes[5]).map(p=>p.error_code));
    for(const code of ['membership','region','unavailable'])assert(codes.has(code));
    await context.close();
  });
  await check('Unavailable browser storage promises only a page pause when server save fails', async () => {
    const {context,page} = await session();
    await page.addInitScript(()=>Object.defineProperty(window,'localStorage',{get(){throw new DOMException('blocked','SecurityError');}}));
    await active(page);
    await page.route('**/api/v2/measurement/preferences',route=>route.fulfill({status:503,contentType:'application/json',body:'{}'}));
    await page.locator('[data-c-off]').first().click(); await untilState(page,'save_failed');
    await page.locator('[data-c-open]').first().click();
    assert(await page.locator('[data-c-storage]').isVisible());
    assert((await page.locator('[data-c-storage]').innerText()).includes('only this page'));
    assert.equal(await page.locator('[data-c-processing]').innerText(),'Not confirmed');
    await context.close();
  });
  await check('No JavaScript leaves optional collection unstarted and data-use text readable', async () => {
    const context=await browser.newContext({javaScriptEnabled:false,locale:'en'}); const page=await context.newPage(); lastPage=page;
    const before=count(); await page.goto(base+'/catalog?lang=en',{waitUntil:'load'});
    assert.equal(await page.locator('dialog[open]').count(),0); assert.equal(count(),before);
    assert(await page.locator('noscript').first().innerText().then(value=>value.includes('server preference has not been read')));
    await page.locator('noscript summary').click();
    assert(await page.locator('noscript details').innerText().then(value=>value.includes('pseudonymous')));
    await context.close();
  });
  await check('Injected event 503: memory cap 100, batch cap 20/32KiB, max five attempts and expiry', async () => {
    const {context,page} = await session(); const batches=[];
    await page.clock.install({time:new Date('2026-10-30T10:00:00Z')});
    await page.route('**/api/v2/measurement/events',route=> {
      const raw=route.request().postData(); assert(Buffer.byteLength(raw)<=32768);
      const body=JSON.parse(raw); assert(body.events.length<=20); batches.push(body.events);
      return route.fulfill({status:503,contentType:'application/json',body:'{}'});
    });
    await active(page);
    await page.evaluate(()=>{for(let i=0;i<500;i++)H5AudienceAnalytics.emit('page_view');});
    for(let i=0;i<75;i++){await page.clock.fastForward(3100);await page.waitForTimeout(100);}
    const attempts=new Map();
    for(const batch of batches)for(const event of batch)attempts.set(event.event_id,(attempts.get(event.event_id)||0)+1);
    assert.equal(attempts.size,100); assert([...attempts.values()].every(value=>value===5));
    const before=batches.length;
    await page.clock.fastForward('31:00');
    await page.evaluate(()=>H5AudienceAnalytics.emit('page_view'));
    await page.waitForTimeout(150); assert.equal(batches.length,before); assert.equal(await state(page),'unavailable');
    await context.close();
  });
  await check('C hook filters forbidden legacy fields and unknown event names', async () => {
    const {context,page} = await session(); await active(page);
    await page.evaluate(() => {
      H5AudienceAnalytics.emit('page_view',{path:'/secret',viewer_id:'secret',query:'secret',referrer:'secret',click_id:'secret'});
      H5AudienceAnalytics.emit('payment_confirmed',{order_id:'secret'});
    });
    await page.waitForTimeout(1000); await context.close();
    const allowed = new Set(['page_instance_id','page_type','locale','drama_id','episode_id','play_instance_id','position_seconds','duration_seconds','watched_seconds','startup_ms','stall_ms','error_code']);
    assert(eventRequests.length>0);
    for (const body of eventRequests) for (const event of body.events) {
      assert(!['landing_view','payment_confirmed'].includes(event.event_name));
      for (const key of Object.keys(event.properties)) assert(allowed.has(key),key);
    }
    assert.equal(legacy.length,0); assert.equal(external.length,0); assert.deepEqual(errors,[]); assert(referrers.every(value=>value===''));
    assert.equal(sql('SELECT count(*) FROM billing_orders'),'0');
  });
  await check('Real Chrome tab hide/resume retains one page and one play; hidden time is not watched', async () => {
    const port = await new Promise(resolve=> { const s=net.createServer(); s.listen(0,'127.0.0.1',()=>{const port=s.address().port;s.close(()=>resolve(port));}); });
    const profile = fs.mkdtempSync(path.join(out,'native-chrome-'));
    const process = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',[
      '--remote-debugging-port='+port,'--remote-debugging-address=127.0.0.1','--user-data-dir='+profile,
      '--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-component-update',
      '--disable-sync','--disable-default-apps','--no-proxy-server','--window-size=1280,850','about:blank'],{stdio:'ignore'});
    let native;
    try {
      for(let i=0;i<50;i++) { try { native=await chromium.connectOverCDP('http://127.0.0.1:'+port,{noDefaults:true,timeout:500});break; } catch { await new Promise(r=>setTimeout(r,100)); } }
      assert(native,'Native Chrome startup');
      const context = native.contexts()[0], page = context.pages()[0]; lastPage=page;
      await context.route('**/*',route=>route.request().url().startsWith(base+'/')?route.continue():route.abort());
      let startRequests=0;
      page.on('request',request=> { if(request.url().endsWith('/measurement/start'))startRequests++; if(request.url().includes('/api/v2/measurement/'))assert(!request.headers().referer); });
      const old = new Set(JSON.parse(sql("SELECT coalesce(json_agg(id),'[]') FROM acquisition_c_events")));
      await active(page,'/dramas/'+fixture.drama+'/episodes/'+fixture.episodes[2]);
      await page.locator('[data-play]').click();
      await page.waitForFunction(()=>document.querySelector('video').currentTime>2.7);
      const tab=await context.newPage(); await tab.goto('about:blank'); await tab.bringToFront();
      await page.waitForFunction(()=>document.visibilityState==='hidden'); await tab.waitForTimeout(1500);
      await page.bringToFront(); await page.waitForFunction(()=>document.visibilityState==='visible'); await untilState(page,'active');
      await page.waitForTimeout(2800); await page.evaluate(()=>document.querySelector('video').pause()); await page.waitForTimeout(1100);
      const rows=JSON.parse(sql("SELECT coalesce(json_agg(json_build_object('id',id,'name',event_name,'p',properties)),'[]') FROM acquisition_c_events")).filter(row=>!old.has(row.id));
      assert.equal(rows.filter(row=>row.name==='page_view').length,1);
      assert.equal(rows.filter(row=>row.name==='play_started').length,1); assert.equal(startRequests,1);
      const pageIds=new Set(rows.map(row=>row.p.page_instance_id)); assert.equal(pageIds.size,1);
      const playIds=new Set(rows.map(row=>row.p.play_instance_id).filter(Boolean)); assert.equal(playIds.size,1);
      const progress=rows.filter(row=>row.name==='watch_progress'); assert(progress.length>0);
      assert(progress.every(row=>row.p.watched_seconds<6.3));
      assert.equal(rows.filter(row=>row.name==='episode_completed').length,0);
      fs.writeFileSync(path.join(out,'native-visibility.json'),JSON.stringify({real_hidden_visible:true,page_views:1,play_started:1,start_requests:startRequests,progress:progress.map(row=>row.p.watched_seconds)},null,2));
    } finally { await native?.close(); process.kill(); }
  });
  if(noticeOnly) {assert.equal(results.length,4);assert.deepEqual(errors,[]);assert.deepEqual(external,[]);assert.equal(legacy.length,0);}
  fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({results,passed:results.length,selection:noticeOnly?'C-V01':'all',errors,external,legacy_requests:legacy.length,
    event_batches:eventRequests.length,scope:'04 isolated synthetic HTTP; fault responses labelled; not official payment, policy eligibility or manual acceptance'},null,2));
})().catch(async error=>{
  console.error(error); process.exitCode=1;
  fs.writeFileSync(path.join(out,'browser-failure.json'),JSON.stringify({results,errors,responses,error:String(error)},null,2));
  if (lastPage && !lastPage.isClosed()) await lastPage.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});
}).finally(async()=>{await browser?.close();});
