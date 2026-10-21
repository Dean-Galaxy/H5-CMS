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
 const passed=[], errors=[];
 page.on('pageerror', error => errors.push(error.message));
 const check=(name,condition)=>{if(!condition)throw new Error(name);passed.push(name)};
 const tab=async id=>{await page.locator(`[data-admin-tab="${id}"]`).click();await page.waitForFunction(id=>document.querySelector(`[data-admin-tab="${id}"]`)?.getAttribute('aria-selected')==='true',id)};
 const close=async editor=>{await editor.getByRole('button',{name:'取消',exact:true}).click();await editor.waitFor({state:'hidden'})};
 const prompt=()=>page.locator('dialog.h5-confirm-leave');
 try {
  await login(page,account);
  const response=await page.goto(base + '/admin/acquisition-desk');
  if(response.status()!==200)throw new Error('Acquisition page unavailable: HTTP '+response.status()+'; check Web H5_LOCAL_MODULES=acquisition');
  check('A01/A02 exactly four authorized top-level tabs',await page.getByRole('tab').count()===4);
  check('A01 list has no visible persistent editor',await page.locator('form[data-editor]:visible').count()===0);
  check('external sending explicitly disabled',(await page.locator('[data-acquisition-environment]').innerText()).includes('外部发送：关闭'));
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
  await close(assignment);
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
   const out=process.env.H5_ACQUISITION_SCREENSHOTS;fs.mkdirSync(out,{recursive:true});
   const {pathToFileURL}=require('node:url');const path=require('node:path');
   const prototype=await browser.newPage();await prototype.goto(pathToFileURL(path.resolve('docs/ui/08-admin-v2/index.html')).href);
   const frame=prototype.frameLocator('#codex-visualization');await frame.locator('[data-module="acquisition"]').click();
   for(const [label,width,height,theme] of [['desktop',1280,900,'light'],['mobile',375,812,'light'],['desktop-dark',1280,900,'dark'],['mobile-dark',375,812,'dark']]) {
    await page.setViewportSize({width,height});await prototype.setViewportSize({width,height});
    await prototype.emulateMedia({colorScheme:theme});await page.evaluate(theme=>document.documentElement.classList.toggle('dark',theme==='dark'),theme);
    for(const [real,design] of [['links','links'],['pixels','pixels'],['payments','results'],['deliveries','delivery']]) {
     await tab(real);await frame.locator(`[data-tab="${design}"][data-kind="acquisition"]`).click();
     await page.screenshot({path:out+'/'+label+'-'+real+'-actual.png'});await prototype.screenshot({path:out+'/'+label+'-'+real+'-approved.png'});
    }
    await tab('links');await page.getByRole('button',{name:'新增链接',exact:true}).click();await link.waitFor({state:'visible'});
    await page.screenshot({path:out+'/'+label+'-new-link-actual-only.png'});
    await link.getByLabel('推广名称').fill('F09 未保存');await link.getByRole('button',{name:'取消',exact:true}).click();
    await prompt().getByRole('button',{name:'保存并继续'}).click();await prompt().getByRole('alert').waitFor({state:'visible'});
    await page.screenshot({path:out+'/'+label+'-rejected-save-actual-only.png'});
    await prompt().getByRole('button',{name:'放弃修改'}).click();await link.waitFor({state:'hidden'});
    if(fs.existsSync(fixturePath)) {
     const f=JSON.parse(fs.readFileSync(fixturePath,'utf8'));
     await page.locator(`[data-editor-action="selectLink"][data-editor-id="${f.link_id}"]`).click();await link.waitFor({state:'visible'});
     await page.screenshot({path:out+'/'+label+'-edit-link-actual-only.png'});await close(link);
    }
    await tab('pixels');await edit.click();await pixel.waitFor({state:'visible'});await page.screenshot({path:out+'/'+label+'-pixel-editor-actual-only.png'});await close(pixel);
    await page.getByRole('button',{name:'分配像素'}).first().click();await assignment.waitFor({state:'visible'});await page.screenshot({path:out+'/'+label+'-assignment-actual-only.png'});await close(assignment);
   }
   await prototype.close();
  }
  await page.setViewportSize({width:375,height:812});
  check('mobile page has no horizontal overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth));
  check('no browser JavaScript errors',errors.length===0);
  console.log(JSON.stringify({passed,base:new URL(base).origin,externalSending:'disabled',screenshots:process.env.H5_ACQUISITION_SCREENSHOTS||null}));
 } finally {await browser.close()}
})().catch(error=>{console.error(error.message);process.exit(1)});
