// Explicit UI-only fixture on own Laravel GET HTML; application scripts inert; no preference/registration writes.
'use strict';
const {chromium}=require('playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const Y='3c109badaec215428ca8e1bd221829c82fe42d34',S='c473f592f3eff3d1e0dd9f1595e1b7d89a49e7a9';
const base=process.env.H5_C_UI_BASE,out=path.join(process.env.H5_C_UI_OUT,'dialog-layout'),DIALOG='[data-c-dialog]';
assert(process.env.H5_C_UI_HEADER_ONLY==='1'&&process.env.H5_C_UI_DIALOG_ONLY==='1'&&process.env.DB_DATABASE.endsWith('_header_audience_http_test'));
fs.mkdirSync(out,{recursive:true});
const oldCSS=Object.fromEntries(['measurement-c.css','purposes-r1.css'].map(name=>[name,execFileSync('git',['show',Y+':h5-platform/public/audience/'+name],{encoding:'utf8'})]));
const results=[],external=[],mutations=[],errors=[];let browser,last;
// Copied verbatim from the read-only S observer scaleDialog; no observer tool is executed or modified.
async function scaleDialog(page){return page.evaluate(selector=>{
 const dialog=document.querySelector(selector);if(!dialog?.open)throw Error('Dialog not open');const rendered=node=>node instanceof HTMLElement&&!node.closest('script,style,noscript,template,svg')&&node.getClientRects().length&&getComputedStyle(node).visibility==='visible';
 const all=[dialog,...dialog.querySelectorAll('*')].filter(rendered),outside=[...document.querySelectorAll('body,body *')].filter(node=>!dialog.contains(node)&&rendered(node)).map(node=>({node,px:getComputedStyle(node).fontSize}));
 const before=all.map(node=>({node,tag:node.tagName,px:parseFloat(getComputedStyle(node).fontSize)})),dpr=devicePixelRatio,scale=visualViewport?.scale??null;
 // Baselines for every rendered HTML element, including off-scroll dialog content, are frozen before any ancestor changes.
 for(const {node,px} of before)if(px>0)node.style.setProperty('font-size',(px*2)+'px','important');
 const representatives={},measurements=[];let outsideTolerance=0;for(const {node,tag,px} of before){if(!(px>0))continue;const after=parseFloat(getComputedStyle(node).fontSize),ratio=after/px;measurements.push({tag,before_px:px,after_px:after,ratio});if(Math.abs(ratio-2)>=0.02)outsideTolerance++;const item=representatives[tag]??={count:0,min_ratio:Infinity,max_ratio:-Infinity};item.count++;item.min_ratio=Math.min(item.min_ratio,ratio);item.max_ratio=Math.max(item.max_ratio,ratio);}
 return {method:'open_dialog_visible_html_precomputed_font_px_times_2',scope:'Only rendered HTML in the current open dialog, including off-scroll content; excludes hidden/SVG/pseudo text/background/browser UI',browser_zoom_tested:false,background_measured:outside.length,background_changed:outside.filter(({node,px})=>getComputedStyle(node).fontSize!==px).length,dpr_before:dpr,dpr_after:devicePixelRatio,scale_before:scale,scale_after:visualViewport?.scale??null,measured_elements:measurements.length,outside_tolerance:outsideTolerance,representatives,measurements};
 },DIALOG);}

async function fixture(locale,cssSource) {
 const context=await browser.newContext({viewport:{width:320,height:500},deviceScaleFactor:1,locale,serviceWorkers:'block'});
 await context.route('**/*',route=>{
  const req=route.request(),url=req.url();
  if(!url.startsWith(base+'/')){external.push(new URL(url).origin);return route.abort();}
  if(req.method()!=='GET'){mutations.push({method:req.method(),path:new URL(url).pathname});return route.abort();}
  if(req.resourceType()==='script')return route.fulfill({status:200,contentType:'application/javascript',body:'/* Explicit UI fixture: app scripts inert. */'});
  if(cssSource==='Y')for(const [name,css] of Object.entries(oldCSS))if(url===base+'/audience/'+name)return route.fulfill({status:200,contentType:'text/css',body:css});
  return route.continue();
 });
 const page=await context.newPage();last=page;page.on('pageerror',err=>errors.push(err.message));
 await page.goto(base+'/?lang='+(locale==='en'?'en':'zh_CN'),{waitUntil:'networkidle'});
 await page.evaluate(()=>{
  const dialog=document.querySelector('[data-c-dialog]'),c=JSON.parse(document.querySelector('#measurement-c-messages').textContent),p=JSON.parse(document.querySelector('#purpose-r1-messages').textContent);
  // Localized UI text/check-state fixtures only; no authenticated actor, API response or business success is fabricated.
  const put=(selector,text)=>{dialog.querySelector(selector).textContent=text;};
  put('[data-c-status]',c.off);put('[data-c-choice]',c.deny);put('[data-c-processing]',c.disabled);put('[data-c-local]',c.waiting);
  put('[data-purpose-reading]','');put('[data-purpose-legacy]',p.unknown);
  for(const name of ['account_link','advertising']){
   const field=dialog.querySelector('[data-purpose="'+name+'"]');
   field.querySelector('[data-purpose-choice]').textContent=p.saved_allow;
   field.querySelector('[data-purpose-capability]').textContent=p.choice_only;
   field.querySelector('[data-purpose-effective]').textContent=p.not_running;
   field.querySelector('input[value="allow"]').checked=true;
  }
  dialog.showModal();
 });
 for(const selector of ['[data-c-purpose-manager] > details','[data-c-use]']){await page.locator(selector+' > summary').click();assert(await page.locator(selector).evaluate(n=>n.open));}
 await page.evaluate(()=>document.fonts.ready);
 await page.locator(DIALOG+' .measurement-c-body').evaluate(n=>n.scrollTop=0);
 return {context,page};
}
async function measure(page) {
 return page.evaluate(()=>{
  const d=document.querySelector('[data-c-dialog]'),b=d.querySelector('.measurement-c-body'),rect=n=>{const r=n.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height};};
  const style=n=>{const s=getComputedStyle(n);return {font:s.fontSize,color:s.color,background:s.backgroundColor,fontWeight:s.fontWeight,lineHeight:s.lineHeight};};
  const br=b.getBoundingClientRect(),bs=getComputedStyle(b),left=br.left+parseFloat(bs.paddingLeft),right=br.right-parseFloat(bs.paddingRight),textOverflows=[];
  const walker=document.createTreeWalker(b,NodeFilter.SHOW_TEXT);
  while(walker.nextNode()){
   const n=walker.currentNode,parent=n.parentElement;if(!n.textContent.trim()||!parent?.checkVisibility()||parent.closest('script,style,noscript,template'))continue;
   const range=document.createRange();range.selectNodeContents(n);
   for(const r of range.getClientRects())if(r.width>0&&(r.left<left-1||r.right>right+1))textOverflows.push({tag:parent.tagName,text:n.textContent.trim().slice(0,80),left:r.left,right:r.right});
  }
  return {viewport:[innerWidth,innerHeight],dpr:devicePixelRatio,scale:visualViewport?.scale,body:{...rect(b),clientWidth:b.clientWidth,scrollWidth:b.scrollWidth,clientHeight:b.clientHeight,scrollHeight:b.scrollHeight,scrollTop:b.scrollTop,overflowX:bs.overflowX},
   title:{...rect(d.querySelector('h2')),text:d.querySelector('h2').textContent,style:style(d.querySelector('h2'))},
   colors:{dialog:style(d),body:style(b),page:style(document.body),backdrop:getComputedStyle(d,'::backdrop').backgroundColor},
   fields:[...d.querySelectorAll('fieldset')].map(f=>({purpose:f.dataset.purpose,...rect(f),clientWidth:f.clientWidth,scrollWidth:f.scrollWidth,legend:{...rect(f.querySelector('legend')),style:style(f.querySelector('legend'))}})),textOverflows};
 });
}
async function captures(page,name) {
 const positions=[];
 for(const [label,selector,align] of [['title','h2','start'],['account-legend','[data-purpose="account_link"] legend','start'],['account-bottom','[data-purpose="account_link"]','end'],['advertising-legend','[data-purpose="advertising"] legend','start'],['advertising-bottom','[data-purpose="advertising"]','end'],['scope-bottom','[data-c-use]','end']]) {
  if(label==='title')await page.locator(DIALOG+' .measurement-c-body').evaluate(b=>b.scrollTop=0);
  else await page.locator(DIALOG+' '+selector).evaluate((n,align)=>{const b=n.closest('.measurement-c-body'),r=n.getBoundingClientRect(),br=b.getBoundingClientRect();b.scrollTop+=align==='start'?r.top-br.top:r.bottom-br.top-b.clientHeight;},align);
  const position=await page.locator(DIALOG+' .measurement-c-body').evaluate(b=>({scrollTop:b.scrollTop,clientWidth:b.clientWidth,scrollWidth:b.scrollWidth}));
  await page.screenshot({path:path.join(out,name+'-'+label+'.png'),fullPage:false});positions.push({label,...position});
 }
 return positions;
}
(async()=>{
 browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
 for(const locale of ['en','zh-CN'])for(const cssSource of ['Y','candidate']){
  const {context,page}=await fixture(locale,cssSource);
  for(const doubled of [false,true]){
   const font=doubled?await scaleDialog(page):null;if(font){assert.equal(font.background_changed,0);assert.equal(font.outside_tolerance,0);assert.equal(font.dpr_before,font.dpr_after);assert.equal(font.scale_before,font.scale_after);for(const tag of ['H2','LABEL','SPAN','INPUT','BUTTON']){assert(font.representatives[tag].count>0);assert.equal(font.representatives[tag].min_ratio,2);assert.equal(font.representatives[tag].max_ratio,2);}}
   await page.locator(DIALOG+' .measurement-c-body').evaluate(n=>n.scrollTop=0);
   const measured=await measure(page),name=locale+'-'+cssSource+(doubled?'-font-x2':'-normal');
   const row={name,locale,cssSource,doubled,measured,font,frames:await captures(page,name)};results.push(row);
   fs.writeFileSync(path.join(out,'dialog-browser.json'),JSON.stringify({Y,S,uiFixture:true,appScriptsInert:true,results,external,mutations,errors},null,2));
   if(cssSource==='Y'&&locale==='en'&&doubled){assert.equal(measured.body.clientWidth,248);assert.equal(measured.body.scrollWidth,261);assert(measured.textOverflows.some(r=>r.tag==='H2'));}
   else {
    assert(measured.body.scrollWidth<=measured.body.clientWidth,'Dialog horizontal overflow '+name+': '+measured.body.scrollWidth+'/'+measured.body.clientWidth);
    assert.deepEqual(measured.textOverflows,[],'Rendered text overflow '+name);
    assert.equal(measured.fields.length,2);for(const field of measured.fields){assert(field.left>=measured.body.left&&field.right<=measured.body.right);assert(field.scrollWidth<=field.clientWidth);assert(field.legend.left>=field.left&&field.legend.right<=field.right);}
   }
   console.log('PASS '+name+' '+measured.body.scrollWidth+'/'+measured.body.clientWidth);
  }
  await context.close();
 }
 for(const locale of ['en','zh-CN']) {
  const row=(cssSource,doubled)=>results.find(r=>r.locale===locale&&r.cssSource===cssSource&&r.doubled===doubled);
  assert.deepEqual(row('candidate',false).measured,row('Y',false).measured,'Normal layout and appearance must remain unchanged '+locale);
  for(const doubled of [false,true]) {
   const prior=row('Y',doubled).measured,next=row('candidate',doubled).measured;
   assert.deepEqual(next.colors,prior.colors,'Dialog/page fonts and colors must remain unchanged');
   assert.deepEqual(next.title.style,prior.title.style);assert.equal(next.title.text,prior.title.text);
   assert.deepEqual(next.fields.map(f=>f.legend.style),prior.fields.map(f=>f.legend.style));
  }
 }
 assert.deepEqual(external,[]);assert.deepEqual(mutations,[]);assert.deepEqual(errors,[]);
 const counts=JSON.parse(execFileSync('/opt/homebrew/opt/postgresql@17/bin/psql',['-Atc',"SELECT json_build_object('viewers',(SELECT count(*) FROM identity_viewers),'receipts',(SELECT count(*) FROM identity_registration_receipts),'subjects',(SELECT count(*) FROM acquisition_c_subjects),'proofs',(SELECT count(*) FROM acquisition_r1_proofs),'operations',(SELECT count(*) FROM acquisition_r1_operations),'choices',(SELECT count(*) FROM acquisition_r1_choices),'events',(SELECT count(*) FROM acquisition_c_events))"],{encoding:'utf8',env:{...process.env,PGHOST:'127.0.0.1',PGPORT:process.env.DB_PORT,PGDATABASE:process.env.DB_DATABASE,PGUSER:process.env.DB_USERNAME,PGPASSWORD:process.env.DB_PASSWORD}}));
 assert(Object.values(counts).every(n=>n===0));
 fs.writeFileSync(path.join(out,'dialog-browser.json'),JSON.stringify({Y,S,passed:results.length,uiFixture:true,appScriptsInert:true,results,external,mutations,errors,counts},null,2));
})().catch(async error=>{console.error(error);process.exitCode=1;fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),results},null,2));}).finally(async()=>{await browser?.close();});
