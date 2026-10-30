// CSS-only real Laravel HTML. App scripts are inert and all non-GET/external requests are rejected.
// Text enlargement matches the fixed 06 observer: snapshot EVERY original computed size before changing any.
const {chromium}=require('playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const baseline='05d2db9cd77b304193b770da514b4a5f6a51607e';
const base=process.env.H5_C_UI_BASE,out=process.env.H5_C_UI_OUT;
assert(process.env.H5_C_UI_HEADER_ONLY==='1'&&process.env.DB_DATABASE.endsWith('_header_audience_http_test'));
const actualA='1263bdcb3576986a74a0cf59a7c7ea61072b9062';
const actualACSS=execFileSync('git',['show',actualA+':h5-platform/public/audience/site.css'],{encoding:'utf8'});
assert.equal(fs.readFileSync('h5-platform/public/identity.css','utf8'),execFileSync('git',['show',actualA+':h5-platform/public/identity.css'],{encoding:'utf8'}),'Identity stylesheet must remain byte-identical to actual A');
const oldCSS=execFileSync('git',['show',baseline+':h5-platform/public/audience/site.css'],{encoding:'utf8'});
const results=[],errors=[],external=[],mutations=[];let browser,last;
async function fixture(locale,width,old=false) {
  const context=await browser.newContext({viewport:{width,height:width===320?500:850},locale});
  await context.route('**/*',route=>{
    const request=route.request(),url=request.url();
    if(!url.startsWith(base+'/')) {external.push(new URL(url).origin);return route.abort();}
    if(request.method()!=='GET'){mutations.push({method:request.method(),path:new URL(url).pathname});return route.abort();}
    if(request.resourceType()==='script')return route.fulfill({status:200,contentType:'application/javascript',body:'/* Isolated header CSS fixture: application scripts intentionally inert. */'});
    if(old&&url===base+'/audience/site.css')return route.fulfill({status:200,contentType:'text/css',body:old==='actualA'?actualACSS:oldCSS});
    return route.continue();
  });
  const page=await context.newPage();last=page;page.on('pageerror',error=>errors.push(error.message));
  await page.goto(base+'/identity/register?lang='+(locale==='en'?'en':'zh_CN'),{waitUntil:'networkidle'});
  return {context,page};
}
async function enlarge(page) {
  return page.evaluate(()=>{
    const before=parseFloat(getComputedStyle(document.body).fontSize);
    const originals=[...document.querySelectorAll('body,button,input,legend,p,dd,dt,summary,a')].map(node=>[node,parseFloat(getComputedStyle(node).fontSize)]);
    for(const [node,size] of originals)node.style.setProperty('font-size',(size*2)+'px','important');
    return {ratio:parseFloat(getComputedStyle(document.body).fontSize)/before,checked:originals.length,
      everyRatioTwo:originals.every(([node,size])=>Math.abs(parseFloat(getComputedStyle(node).fontSize)-size*2)<0.05)};
  });
}
async function measure(page) {
  return page.evaluate(()=>{
    const box=node=>{const r=node.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width,height:r.height,font:parseFloat(getComputedStyle(node).fontSize),href:node.getAttribute('href')};};
    const header=document.querySelector('.site-header');
    return {viewport:innerWidth,viewportHeight:innerHeight,deviceScaleFactor:devicePixelRatio,brand:box(header.querySelector('.brand')),documentWidth:document.documentElement.scrollWidth,header:box(header),nav:box(header.querySelector('nav')),
      links:[...header.querySelectorAll('a')].filter(n=>n.getClientRects().length&&(!n.closest('details')||n.closest('details').open)).map(box),summary:box(header.querySelector('summary'))};
  });
}
async function reachable(page,locale) {
  const header=page.locator('.site-header'),nav=header.locator('nav a');
  assert.deepEqual(await nav.evaluateAll(nodes=>nodes.map(n=>n.getAttribute('href'))),['/','/catalog','/membership']);
  assert.equal(await header.locator('.brand').getAttribute('href'),'/');
  assert((await header.locator('[data-audience-login]').getAttribute('href')).startsWith('/identity/login?'));
  for(const node of [header.locator('.brand'),...await nav.all(),header.locator('[data-audience-login]'),header.locator('summary')]) {
    await node.focus();assert(await node.evaluate(n=>document.activeElement===n));
    await node.scrollIntoViewIfNeeded();
    assert(await node.evaluate(n=>{const r=n.getBoundingClientRect();const hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);return n.contains(hit);}));
    assert((await node.boundingBox()).height>=44,'Header target must be >=44px: '+await node.getAttribute('class'));
    await page.keyboard.press('Tab');
  }
  await header.locator('summary').focus();await page.keyboard.press('Enter');
  assert(await header.locator('details').evaluate(n=>n.open));
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  for(const node of await header.locator('.language a').all()) {assert(await node.isVisible());assert((await node.boundingBox()).height>=44,'Header target must be >=44px: '+await node.getAttribute('class'));}
  const language=header.locator('.language a[lang="'+(locale==='en'?'zh-Hans':'en')+'"]');
  assert(new URL(await language.getAttribute('href')).origin===base);
  await header.locator('summary').focus();await page.keyboard.press('Enter');
  await page.evaluate(()=>scrollTo(0,0));
}
(async()=>{
  browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
  {
    const {context,page}=await fixture('en',320,true);const normal=await measure(page);assert.equal(normal.documentWidth,320);
    const zoom=await enlarge(page),measured=await measure(page);
    assert.equal(zoom.ratio,2);assert(zoom.everyRatioTwo);assert(measured.documentWidth>320);assert(measured.nav.right>320);
    assert.equal(measured.links.find(link=>link.href==='/membership').font,26);
    await page.screenshot({path:path.join(out,'baseline-en-320-font200.png')});
    fs.writeFileSync(path.join(out,'baseline.json'),JSON.stringify({baseline,normal_document_width:normal.documentWidth,zoom,measured,expected_failure:true},null,2));
    results.push({name:'Frozen old CSS reproduces English font200 header overflow',passed:true,documentWidth:measured.documentWidth});await context.close();
  }
  for(const locale of ['en','zh-CN'])for(const width of [320,1280])for(const doubled of [true,false]) {
    const {context,page}=await fixture(locale,width);const zoom=doubled?await enlarge(page):{ratio:1,everyRatioTwo:null};const measured=await measure(page);
    assert.equal(zoom.ratio,doubled?2:1);if(doubled)assert(zoom.everyRatioTwo);
    const name=locale+'-'+width+(doubled?'-font200':'-normal');
    await page.screenshot({path:path.join(out,name+'.png')});
    assert(measured.documentWidth<=width,'Page overflow '+name+': '+measured.documentWidth);
    assert(measured.nav.right<=width+0.5&&measured.nav.x>=0);
    assert(measured.summary.x>=0&&measured.summary.right<=width+0.5);
    assert(measured.links.every(link=>link.x>=0&&link.right<=width+0.5));
    for(let i=0;i<measured.links.length;i++)for(let j=i+1;j<measured.links.length;j++) {
      const a=measured.links[i],b=measured.links[j];assert(!(Math.min(a.right,b.right)-Math.max(a.x,b.x)>1&&Math.min(a.bottom,b.bottom)-Math.max(a.y,b.y)>1),'Header links overlap');
    }
    await reachable(page,locale);
    results.push({name,passed:true,zoom,measured});console.log('PASS '+name);await context.close();
  }
  for(const locale of ['en','zh-CN']) {
    const {context,page}=await fixture(locale,320);await page.locator('.site-header nav a[href="/catalog"]').click();await page.waitForURL('**/catalog');
    await page.locator('.site-header .brand').click();assert.equal(new URL(page.url()).pathname,'/');
    await page.locator('.site-header [data-audience-login]').click();await page.waitForURL('**/identity/login**');assert.equal(await page.locator('[name=email]').count(),1);
    await page.locator('.site-header summary').click();await page.locator('.language a[lang="'+(locale==='en'?'zh-Hans':'en')+'"]').click();
    assert.equal(await page.locator('html').getAttribute('lang'),locale==='en'?'zh-CN':'en');
    results.push({name:locale+' real native catalog/home/login/language GET navigation',passed:true});await context.close();
  }
  // UI TEXT FIXTURE ONLY: take the actual server-localized created string; never submit registration or run app JS.
  const colorChecks=[];
  for(const locale of ['en','zh-CN'])for(const cssSource of ['actualA','candidate']) {
    const {context,page}=await fixture(locale,320,cssSource==='actualA'?'actualA':false);
    const measured=await page.evaluate(()=>{
      const messages=JSON.parse(document.querySelector('[data-purpose-messages]').textContent);
      if(typeof messages.created!=='string'||!messages.created)throw Error('Missing server-localized created message');
      const node=document.querySelector('[data-result]');node.textContent=messages.created;
      let background=node;
      while(background&&getComputedStyle(background).backgroundColor==='rgba(0, 0, 0, 0)')background=background.parentElement;
      if(!background)throw Error('Missing opaque background');
      const style=getComputedStyle(node),bg=getComputedStyle(background).backgroundColor;
      const luminance=color=>{const rgb=color.match(/^rgb\((\d+), (\d+), (\d+)\)$/);if(!rgb)throw Error('Expected opaque computed color: '+color);
        const c=rgb.slice(1).map(Number).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;});return .2126*c[0]+.7152*c[1]+.0722*c[2];};
      const fg=luminance(style.color),back=luminance(bg);
      return {foreground:style.color,background:bg,backgroundTag:background.tagName,backgroundClass:background.className,fontSize:style.fontSize,role:node.getAttribute('role'),text:node.textContent,
        contrast:(Math.max(fg,back)+.05)/(Math.min(fg,back)+.05),viewport:[innerWidth,innerHeight],dpr:devicePixelRatio,messageSource:'server-rendered [data-purpose-messages].created; UI text fixture only'};
    });
    const node=page.locator('[data-result]');await node.scrollIntoViewIfNeeded();
    assert(await node.isVisible());assert.equal(measured.fontSize,'14px');assert.equal(measured.role,'status');
    await page.screenshot({path:path.join(out,'ui-text-fixture-created-'+locale+'-'+cssSource+'-320.png')});
    const nonAudienceForeground=await page.evaluate(()=>{document.body.classList.remove('audience-site');return getComputedStyle(document.querySelector('[data-result]')).color;});
    assert.equal(nonAudienceForeground,'rgb(145, 61, 32)');
    colorChecks.push({locale,cssSource,actualA,measured,nonAudienceForeground,passesBodyText45:measured.contrast>=4.5});
    fs.writeFileSync(path.join(out,'status-color.json'),JSON.stringify({actualA,uiTextFixture:true,appScriptsInert:true,registrationSubmissions:0,checks:colorChecks},null,2));
    if(cssSource==='actualA'){assert(Math.abs(measured.contrast-2.289894)<.00001);assert(measured.contrast<4.5);}
    else assert(measured.contrast>=4.5,'Audience status body text contrast must be >=4.5; actual '+measured.contrast);
    results.push({name:locale+' created UI text fixture '+cssSource,passed:true,measured});console.log('PASS status color '+locale+' '+cssSource+' '+measured.contrast);await context.close();
  }
  assert.deepEqual(errors,[]);assert.deepEqual(external,[]);assert.deepEqual(mutations,[]);
  const counts=JSON.parse(execFileSync('/opt/homebrew/opt/postgresql@17/bin/psql',['-Atc',"SELECT json_build_object('viewers',(SELECT count(*) FROM identity_viewers),'registration_receipts',(SELECT count(*) FROM identity_registration_receipts),'orders',(SELECT count(*) FROM billing_orders),'subjects',(SELECT count(*) FROM acquisition_c_subjects),'purpose_proofs',(SELECT count(*) FROM acquisition_r1_proofs))"],{encoding:'utf8',env:{...process.env,PGHOST:'127.0.0.1',PGPORT:process.env.DB_PORT,PGDATABASE:process.env.DB_DATABASE,PGUSER:process.env.DB_USERNAME,PGPASSWORD:process.env.DB_PASSWORD}}));
  assert(Object.values(counts).every(count=>count===0));
  const data={baseline,passed:results.length,results,errors,external,mutations,counts,scope:'Own synthetic app HTML/CSS; app scripts inert; real computed text enlargement, not DPR; no registration submission'};
  fs.writeFileSync(path.join(out,'header-browser.json'),JSON.stringify(data,null,2));
})().catch(async error=>{console.error(error);process.exitCode=1;if(last&&!last.isClosed()){await last.screenshot({path:path.join(out,'current-failed.png')}).catch(()=>{});const measured=await measure(last).catch(()=>null);fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),results,measured},null,2));}}).finally(async()=>{await browser?.close();});
