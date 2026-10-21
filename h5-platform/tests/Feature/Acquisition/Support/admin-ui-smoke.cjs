const fs = require('node:fs');
const crypto = require('node:crypto');
const { chromium } = require(process.env.H5_PLAYWRIGHT_MODULE || 'playwright');
// Explicit isolated local fixture; never load production credentials or send platform requests.
const base = process.env.H5_ACQUISITION_PREVIEW_URL || 'http://127.0.0.1:8205';
if (!['127.0.0.1', 'localhost'].includes(new URL(base).hostname)) throw new Error('Local preview URL required');
const account = JSON.parse(fs.readFileSync(process.env.H5_ACQUISITION_PREVIEW_ACCOUNT || '.runtime/acquisition-preview-account.json', 'utf8'));
function totp(secret) {
 const chars='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits='';
 for(const c of secret.replace(/=+$/,'')) bits+=chars.indexOf(c).toString(2).padStart(5,'0');
 const key=Buffer.from(bits.match(/.{8}/g).map(x=>parseInt(x,2)));
 const counter=Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));
 const digest=crypto.createHmac('sha1',key).update(counter).digest(); const offset=digest[digest.length-1]&15;
 return String((digest.readUInt32BE(offset)&0x7fffffff)%1000000).padStart(6,'0');
}
async function login(page, identity) {
 await page.goto(base + '/admin/login');
 await page.getByLabel('邮箱地址').fill(identity.email);
 await page.locator('input[type=password]').first().fill(identity.password);
 await page.getByRole('button',{name:'登录',exact:true}).click();
 await page.getByLabel(/6-digit code/).fill(totp(identity.totp_secret));
 await page.getByRole('button',{name:'Confirm sign in'}).click();
 await page.waitForURL(url => url.pathname === '/admin');
}
(async () => {
 const browser = await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
 const page = await browser.newPage({viewport:{width:1280,height:900}});
 const passed=[], errors=[], geometry=[];
 page.on('pageerror', error => errors.push(error.message));
 const check=(name,condition)=>{if(!condition)throw new Error(name);passed.push(name)};
 const tab=async id=>{await page.locator(`[data-admin-tab="${id}"]`).click();await page.waitForFunction(id=>document.querySelector(`[data-admin-tab="${id}"]`)?.getAttribute('aria-selected')==='true',id)};
 const close=async editor=>{await editor.getByRole('button',{name:'取消',exact:true}).click();await editor.waitFor({state:'hidden'})};
 const prompt=()=>page.locator('dialog.h5-confirm-leave');
 const measureDialog=async (editor,label)=>{
  const metrics=await editor.evaluate((d,label)=>{
   const header=d.querySelector('.h5-dialog-header'), body=d.querySelector('.h5-dialog-body'), first=body.firstElementChild, actions=d.querySelector('.h5-dialog-actions'), close=d.querySelector('.h5-dialog-close');
   body.scrollTop=0; // Measure from content start after any prior scroll test.
   const rect=e=>e.getBoundingClientRect();
   const startGap=rect(first).top-rect(header).bottom;
   body.scrollTop=body.scrollHeight;
   const reachedBottom=Math.abs(body.scrollHeight-body.clientHeight-body.scrollTop)<=1;
   const result={label, startGap, titleBottom:rect(header.querySelector('h2')).bottom,firstContentTop:rect(first).top+body.scrollTop,bodyTop:rect(body).top,closeBottom:rect(close).bottom, titleLineHeight:getComputedStyle(header.querySelector('h2')).lineHeight, closeWidth:rect(close).width,closeHeight:rect(close).height,iconWidth:rect(close.querySelector('svg')).width,bodyClientHeight:body.clientHeight,bodyScrollHeight:body.scrollHeight,reachedBottom,actionsBottom:rect(actions).bottom,viewportHeight:innerHeight,bodyFits:body.scrollWidth<=body.clientWidth};
   body.scrollTop=0;return result;
  },label);
  geometry.push(metrics);
  check(label+' shared dialog safe start and mobile scroll/actions',metrics.firstContentTop>=metrics.closeBottom-1&&metrics.bodyTop>=metrics.closeBottom-1&&Math.abs(metrics.firstContentTop-metrics.titleBottom-Math.max(5,metrics.closeBottom-metrics.titleBottom))<1&&metrics.titleLineHeight==='25.2px'&&metrics.closeWidth===44&&metrics.iconWidth===16&&metrics.reachedBottom&&metrics.actionsBottom<=metrics.viewportHeight&&metrics.bodyFits);
 };
 const measurePage=async label=>{
  const metrics=await page.locator('#acquisition-workspace').evaluate(w=>{
   const heading=w.querySelector(':scope > .h5-heading'),tabs=w.querySelector(':scope > .h5-tabs'),panel=w.querySelector(':scope > [role=tabpanel]:not([hidden])');
   return {headingToTabs:tabs.getBoundingClientRect().top-heading.getBoundingClientRect().bottom,tabsToPanel:panel.getBoundingClientRect().top-tabs.getBoundingClientRect().bottom};
  });
  geometry.push({label,...metrics});check(label+' heading and tabs 22px gaps',Math.abs(metrics.headingToTabs-22)<1&&Math.abs(metrics.tabsToPanel-22)<1);
 };
 try {
  await login(page,account);
  const response=await page.goto(base + '/admin/acquisition-desk');
  if(response.status()!==200)throw new Error('Acquisition page unavailable: HTTP '+response.status()+'; check Web H5_LOCAL_MODULES=acquisition');
  check('A01/A02 exactly four authorized top-level tabs',await page.getByRole('tab').count()===4);
  check('A01 list has no visible persistent editor',await page.locator('form[data-editor]:visible').count()===0);
  check('external sending explicitly disabled',(await page.locator('[data-acquisition-environment]').innerText()).includes('外部发送：关闭'));
  if(process.env.H5_ACQUISITION_ERRORS_ONLY) {
   const out=process.env.H5_ACQUISITION_SCREENSHOTS,prefix=process.env.H5_ACQUISITION_CAPTURE_PREFIX||'';
   if(out) fs.mkdirSync(out,{recursive:true});
   const editor=page.locator('#acquisition-link-editor');
   for(const [label,width,height,theme] of [['desktop',1280,900,'light'],['mobile',375,812,'light'],['desktop-dark',1280,900,'dark'],['mobile-dark',375,812,'dark']]) {
    await page.setViewportSize({width,height});await page.evaluate(theme=>document.documentElement.classList.toggle('dark',theme==='dark'),theme);
    await page.getByRole('button',{name:'新增链接',exact:true}).click();await editor.waitFor({state:'visible'});
    await editor.getByLabel('推广名称').fill('F09 未保存');await editor.getByLabel(/^平台/).selectOption('tiktok');
    await editor.getByRole('button',{name:'取消',exact:true}).click();await prompt().getByRole('button',{name:'保存并继续'}).click();
    await prompt().getByRole('alert').waitFor({state:'visible'});
    const messages=await editor.locator('[role=alert]').allTextContents();
    check(label+' required drama and pixel messages are actionable Chinese',messages.includes('请选择已发布剧目')&&messages.includes('请选择授权像素')&&!messages.some(text=>text.includes('field is required')));
    check(label+' failed save preserves open editor and entered name/platform',await editor.isVisible()&&await prompt().isVisible()&&await editor.getByLabel('推广名称').inputValue()==='F09 未保存'&&await editor.getByLabel(/^平台/).inputValue()==='tiktok');
    if(out) await page.screenshot({path:out+'/'+prefix+label+'-rejected-save-actual-only.png'});
    await prompt().getByRole('button',{name:'继续编辑'}).click();await prompt().waitFor({state:'detached'});
    check(label+' continue editing retains unsaved input',await editor.getByLabel('推广名称').inputValue()==='F09 未保存'&&await editor.getByLabel(/^平台/).inputValue()==='tiktok');
    await closeAfterDiscard();
   }
   check('no browser JavaScript errors',errors.length===0);
   console.log(JSON.stringify({passed,browser:browser.version(),platform:process.platform,base:new URL(base).origin,externalSending:'disabled',errorsOnly:true,screenshots:out||null}));return;
   async function closeAfterDiscard() {
    await editor.getByRole('button',{name:'取消',exact:true}).click();await prompt().getByRole('button',{name:'放弃修改'}).click();await editor.waitFor({state:'hidden'});
    await page.waitForFunction(()=>document.activeElement?.dataset.editorAction==='newLink');
   }
  }
  await page.getByRole('tab',{name:'推广链接'}).focus();
  for(const [key,id] of [['ArrowRight','pixels'],['End','deliveries'],['Home','links'],['ArrowLeft','deliveries'],['Home','links']]) {
   await page.keyboard.press(key);
   await page.waitForFunction(id=>{const t=document.querySelector(`[data-admin-tab="${id}"]`);return t?.getAttribute('aria-selected')==='true'&&t.getAttribute('aria-disabled')!=='true'},id);
   check('continuous keyboard '+key+' focuses '+id,await page.evaluate(()=>document.activeElement?.dataset.adminTab)===id);
  }
  const link=page.locator('#acquisition-link-editor'), pixel=page.locator('#acquisition-pixel-editor'), assignment=page.locator('#acquisition-assignment-editor');
  await page.getByRole('button',{name:'新增链接',exact:true}).click();await link.waitFor({state:'visible'});
  check('A01 add link opens shared dialog with drama platform and pixel',await link.getByLabel('已发布剧目').count()===1&&await link.getByLabel(/^平台/).count()===1&&await link.getByLabel('授权像素').count()===1);
  await link.getByLabel('推广名称').fill('F09 unsaved link');await page.keyboard.press('Escape');
  await prompt().getByRole('button',{name:'继续编辑'}).click();
  check('Escape stay preserves actual link edit',await link.getByLabel('推广名称').inputValue()==='F09 unsaved link');
  await link.getByRole('button',{name:'取消',exact:true}).click();await prompt().getByRole('button',{name:'保存并继续'}).click();
  await prompt().getByRole('alert').waitFor({state:'visible'});
  check('rejected server save keeps editor and confirmation',await link.isVisible()&&await prompt().isVisible());
  await prompt().getByRole('button',{name:'放弃修改'}).click();await prompt().waitFor({state:'detached'});await link.waitFor({state:'hidden'});
  await page.waitForFunction(()=>document.activeElement?.dataset.editorAction==='newLink');passed.push('cancel restores focus to link opener');
  await page.getByRole('button',{name:'新增链接',exact:true}).click();await link.waitFor({state:'visible'});
  check('discard restores server-backed new link model',await link.getByLabel('推广名称').inputValue()==='');await close(link);
  await tab('pixels');
  const edit=page.getByRole('button',{name:'编辑 / 启停'}).first();
  if(await edit.count()===0)throw new Error('Prepare at least one own local test pixel before smoke');
  await edit.click();await pixel.waitFor({state:'visible'});
  const originalName=await pixel.getByLabel('名称',{exact:true}).inputValue();
  check('A02 row edit opens selected asset dialog',originalName.length>0);
  await pixel.getByLabel('名称',{exact:true}).fill(originalName+' temporary');
  await pixel.getByRole('button',{name:'取消',exact:true}).click();await prompt().getByRole('button',{name:'放弃修改'}).click();await pixel.waitFor({state:'hidden'});
  await edit.click();await pixel.waitFor({state:'visible'});
  check('pixel discard leaves saved asset unchanged',await pixel.getByLabel('名称',{exact:true}).inputValue()===originalName);
  await pixel.getByRole('button',{name:'保存像素',exact:true}).click();await pixel.waitFor({state:'hidden'});
  passed.push('confirmed pixel save closes dialog and returns to asset list');
  await page.getByRole('button',{name:'分配像素'}).first().click();await assignment.waitFor({state:'visible'});
  check('A02 assignment stays in selected pixel context',(await assignment.innerText()).includes(originalName)&&await page.locator('[data-admin-tab="pixels"]').getAttribute('aria-selected')==='true');
  await assignment.getByRole('button',{name:'读取可分配人员',exact:true}).click();
  const choice=assignment.getByRole('checkbox').first();await choice.waitFor({state:'visible'});
  const initial=await choice.isChecked();await choice.setChecked(!initial);
  await assignment.getByRole('button',{name:'取消',exact:true}).click();await prompt().getByRole('button',{name:'放弃修改'}).click();await assignment.waitFor({state:'hidden'});
  await page.getByRole('button',{name:'分配像素'}).first().click();await assignment.waitFor({state:'visible'});
  await assignment.getByRole('button',{name:'读取可分配人员',exact:true}).click();await choice.waitFor({state:'visible'});
  check('assignment discard leaves authorization unchanged',await choice.isChecked()===initial);
  const candidateId=await choice.getAttribute('value');await choice.setChecked(!initial);
  await assignment.getByRole('button',{name:'保存共享分配',exact:true}).click();await assignment.waitFor({state:'hidden'});
  await page.getByRole('button',{name:'分配像素'}).first().click();await assignment.waitFor({state:'visible'});
  await assignment.getByRole('button',{name:'读取可分配人员',exact:true}).click();
  const sameCandidate=assignment.locator(`input[type="checkbox"][value="${candidateId}"]`);await sameCandidate.waitFor({state:'visible'});
  check('assignment save persists selected asset authorization',await sameCandidate.isChecked()===!initial);
  await sameCandidate.setChecked(initial);await assignment.getByRole('button',{name:'保存共享分配',exact:true}).click();await assignment.waitFor({state:'hidden'});
  passed.push('assignment fixture authorization restored explicitly');
  await page.reload();await page.waitForFunction(()=>document.querySelector('[data-admin-tab="pixels"]')?.getAttribute('aria-selected')==='true');passed.push('authorized tab survives reload');
  await page.goto(base+'/admin');await page.goBack();await page.waitForFunction(()=>document.querySelector('[data-admin-tab="pixels"]')?.getAttribute('aria-selected')==='true');passed.push('browser Back restores asset tab');
  await page.goto(base+'/admin/acquisition-desk?tab=assignments');await page.waitForFunction(()=>document.querySelector('[data-admin-tab="links"]')?.getAttribute('aria-selected')==='true');passed.push('removed fifth tab URL safely falls back for Owner');
  const fixturePath=process.env.H5_ACQUISITION_F09_FIXTURE || '.runtime/acquisition-f09-fixture.json';
  if(fs.existsSync(fixturePath)) {
   const fixture=JSON.parse(fs.readFileSync(fixturePath,'utf8'));
   const row=page.locator(`[data-editor-action="selectLink"][data-editor-id="${fixture.link_id}"]`);
   await row.click();await link.waitFor({state:'visible'});
   check('A01 specific link row restores its editor',await link.getByLabel('推广名称').inputValue()==='F09 本地测试链接');
   await close(link);
   await page.locator(`[data-editor-action="showSummary"][data-editor-id="${fixture.link_id}"]`).click();
   await page.waitForFunction(()=>document.querySelector('[data-admin-tab="payments"]')?.getAttribute('aria-selected')==='true');
   const summary=page.locator('#acquisition-tabs-panel-payments');
   check('A03 test environment and exact USD remain visible',(await summary.innerText()).includes('测试')&&(await summary.innerText()).includes('$6.00 USD')&&(await summary.innerText()).includes('$12.00 USD'));
  }
  const operator=JSON.parse(fs.readFileSync(process.env.H5_ACQUISITION_OPERATOR_ACCOUNT || '.runtime/acquisition-operator-preview-account.json','utf8'));
  const other=await browser.newPage();await login(other,operator);await other.goto(base+'/admin/acquisition-desk?tab=assignments');
  await other.waitForFunction(()=>document.querySelector('[data-admin-tab="links"]')?.getAttribute('aria-selected')==='true');
  check('ordinary operator has four tabs and no assignment dialog',await other.getByRole('tab').count()===4&&await other.locator('#acquisition-assignment-editor').count()===0);await other.close();
  if(process.env.H5_ACQUISITION_SCREENSHOTS) {
   const out=process.env.H5_ACQUISITION_SCREENSHOTS,capturePrefix=process.env.H5_ACQUISITION_CAPTURE_PREFIX||'';fs.mkdirSync(out,{recursive:true});
   const {pathToFileURL}=require('node:url');const path=require('node:path');
   const prototype=await browser.newPage();await prototype.goto(pathToFileURL(path.resolve('docs/ui/08-admin-v2/index.html')).href);
   const frame=prototype.frameLocator('#codex-visualization');await frame.locator('[data-module="acquisition"]').click();
   for(const [label,width,height,theme] of [['desktop',1280,900,'light'],['mobile',375,812,'light'],['desktop-dark',1280,900,'dark'],['mobile-dark',375,812,'dark']]) {
    await page.setViewportSize({width,height});await prototype.setViewportSize({width,height});
    await prototype.emulateMedia({colorScheme:theme});await page.evaluate(theme=>document.documentElement.classList.toggle('dark',theme==='dark'),theme);
    if(!process.env.H5_ACQUISITION_DIALOGS_ONLY) for(const [real,design] of [['links','links'],['pixels','pixels'],['payments','results'],['deliveries','delivery']]) {
     await tab(real);await frame.locator(`[data-tab="${design}"][data-kind="acquisition"]`).click();
     await page.evaluate(()=>window.scrollTo(0,0));await prototype.evaluate(()=>window.scrollTo(0,0));
     await page.screenshot({path:out+'/'+capturePrefix+label+'-'+real+'-actual.png'});await prototype.screenshot({path:out+'/'+capturePrefix+label+'-'+real+'-approved.png'});
    }
    await tab('links');await measurePage(label);await page.getByRole('button',{name:'新增链接',exact:true}).click();await link.waitFor({state:'visible'});
    await measureDialog(link,label+'-new-link');
    await page.screenshot({path:out+'/'+capturePrefix+label+'-new-link-actual-only.png'});
    await link.getByLabel('推广名称').fill('F09 未保存');await link.getByRole('button',{name:'取消',exact:true}).click();
    await prompt().getByRole('button',{name:'保存并继续'}).click();await prompt().getByRole('alert').waitFor({state:'visible'});
    await page.screenshot({path:out+'/'+capturePrefix+label+'-rejected-save-actual-only.png'});
    await prompt().getByRole('button',{name:'放弃修改'}).click();await link.waitFor({state:'hidden'});
    if(fs.existsSync(fixturePath)) {
     const f=JSON.parse(fs.readFileSync(fixturePath,'utf8'));
     await page.locator(`[data-editor-action="selectLink"][data-editor-id="${f.link_id}"]`).click();await link.waitFor({state:'visible'});
     await page.screenshot({path:out+'/'+capturePrefix+label+'-edit-link-actual-only.png'});await close(link);
    }
    await tab('pixels');await edit.click();await pixel.waitFor({state:'visible'});await measureDialog(pixel,label+'-pixel');await page.screenshot({path:out+'/'+capturePrefix+label+'-pixel-editor-actual-only.png'});await close(pixel);
    await page.getByRole('button',{name:'分配像素'}).first().click();await assignment.waitFor({state:'visible'});await assignment.getByRole('button',{name:'读取可分配人员',exact:true}).click();await assignment.getByRole('checkbox').first().waitFor({state:'visible'});await measureDialog(assignment,label+'-assignment');await page.screenshot({path:out+'/'+capturePrefix+label+'-assignment-actual-only.png'});await close(assignment);
   }
   // Real form at a short mobile viewport: exercise overflow without adding fake fields or staff.
   for(const theme of ['light','dark']) {
    await page.setViewportSize({width:375,height:500});await page.evaluate(theme=>document.documentElement.classList.toggle('dark',theme==='dark'),theme);
    await tab('links');await page.getByRole('button',{name:'新增链接',exact:true}).click();await link.waitFor({state:'visible'});
    await measureDialog(link,'mobile-short-'+theme);
    const metrics=geometry[geometry.length-1];check('mobile-short-'+theme+' real content scrolls within body',metrics.bodyScrollHeight>metrics.bodyClientHeight);
    await link.locator('.h5-dialog-body').evaluate(body=>body.scrollTop=body.scrollHeight);
    await page.screenshot({path:out+'/'+capturePrefix+'mobile-short-'+theme+'-scrolled-link-actual-only.png'});
    await close(link);
   }
   await prototype.close();
   await tab('links');await page.getByRole('button',{name:'新增链接',exact:true}).click();await link.waitFor({state:'visible'});
   await link.getByRole('button',{name:'关闭弹窗',exact:true}).click();await link.waitFor({state:'hidden'});
   await page.waitForFunction(()=>document.activeElement?.dataset.editorAction==='newLink');passed.push('clean close X restores opener focus');
   await page.getByRole('button',{name:'新增链接',exact:true}).click();await link.waitFor({state:'visible'});await page.keyboard.press('Escape');await link.waitFor({state:'hidden'});
   await page.waitForFunction(()=>document.activeElement?.dataset.editorAction==='newLink');passed.push('clean Escape closes and restores opener focus');
  }
  await page.setViewportSize({width:375,height:812});
  check('mobile page has no horizontal overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth));
  check('no browser JavaScript errors',errors.length===0);
  console.log(JSON.stringify({passed,geometry,browser:browser.version(),platform:process.platform,devicePixelRatio:await page.evaluate(()=>devicePixelRatio),zoom:1,base:new URL(base).origin,externalSending:'disabled',screenshots:process.env.H5_ACQUISITION_SCREENSHOTS||null,dialogsOnly:!!process.env.H5_ACQUISITION_DIALOGS_ONLY}));
 } finally {await browser.close()}
})().catch(error=>{console.error(error.message);process.exit(1)});
