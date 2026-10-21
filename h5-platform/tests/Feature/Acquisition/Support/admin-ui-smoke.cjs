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
(async () => {
 const browser = await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
 const page = await browser.newPage({viewport:{width:1280,height:900}});
 try {
  await page.goto(base + '/admin/login');
  await page.getByLabel('邮箱地址').fill(account.email);
  await page.locator('input[type=password]').first().fill(account.password);
  await page.getByRole('button',{name:'登录'}).click();
  await page.getByLabel(/6-digit code/).fill(totp(account.totp_secret));
  await page.getByRole('button',{name:'Confirm sign in'}).click();
  await page.waitForTimeout(1000);
  if (new URL(page.url()).pathname === '/admin/login') throw new Error('Local preview MFA login failed');
  await page.goto(base + '/admin/acquisition-desk');
  await page.waitForTimeout(800);
  const restoreProbe=await page.locator('[data-admin-ui]').evaluate(async root=>{const host=root.closest('[wire\\:id]');return await window.Livewire.find(host.getAttribute('wire:id')).call('restoreEditor','link')});
  if(restoreProbe!==true) throw new Error('Livewire restore method did not return true: '+JSON.stringify(restoreProbe));
  const passed=[];
  const tabs=page.getByRole('tab');
  if (await tabs.count() !== 5) throw new Error('Expected four staff tabs plus Owner assignment tab');
  passed.push('Owner-only assignment tab visible for Owner');
  if (await page.locator('[role=tabpanel]:not([hidden])').count() !== 1) throw new Error('Exactly one panel must be visible');
  passed.push('one active panel on initial load');
  await page.getByRole('tab',{name:'推广链接'}).focus();
  for (const [key, id] of [['ArrowRight','pixels'], ['End','assignments'], ['Home','links'], ['ArrowLeft','assignments'], ['Home','links']]) {
   await page.keyboard.press(key);
   await page.waitForFunction(id => {
    const selected=document.querySelector('[data-admin-tab="'+id+'"]');
    return selected?.getAttribute('aria-selected')==='true' && selected.getAttribute('aria-disabled')!=='true';
   }, id);
   const focus=await page.evaluate(()=>document.activeElement?.dataset.adminTab);
   if(focus!==id) throw new Error('Continuous keyboard focus lost after '+key);
   passed.push('continuous keyboard '+key+' retains focus on '+id);
  }
  await page.getByLabel('推广名称').fill('Unsaved browser check');
  await page.getByRole('tab',{name:'像素资产'}).click();
  const prompt=page.getByRole('dialog'); await prompt.waitFor();
  await prompt.getByRole('button',{name:'继续编辑'}).click();
  if (await page.getByLabel('推广名称').inputValue() !== 'Unsaved browser check' || await page.getByRole('tab',{name:'推广链接'}).getAttribute('aria-selected') !== 'true') throw new Error('Continue editing must retain input and tab');
  passed.push('unsaved tab change retains edits when staying');
  await page.getByRole('tab',{name:'像素资产'}).click();
  await page.getByRole('dialog').getByRole('button',{name:'放弃修改'}).click();
  await page.locator('dialog.h5-confirm-leave').waitFor({state:'detached',timeout:5000});
  await page.getByRole('tab',{name:'像素资产'}).waitFor({state:'visible'});
  if (await page.getByRole('tab',{name:'像素资产'}).getAttribute('aria-selected') !== 'true') throw new Error('Discard did not continue: selected='+await page.locator('[role=tab][aria-selected=true]').innerText()+' input='+await page.getByLabel('推广名称').inputValue()+' dialogs='+await page.locator('dialog').count()+' alert='+await page.locator('dialog [role=alert]').textContent()+' component='+JSON.stringify(await page.locator('[data-admin-ui]').evaluate(e=>({wire:e.closest('[wire\\:id]')?.getAttribute('wire:id'), livewire:typeof window.Livewire, component:typeof window.Livewire?.find(e.closest('[wire\\:id]')?.getAttribute('wire:id'))?.call}))) );
  passed.push('discard restores Livewire editor and continues');
  await page.getByRole('button',{name:'编辑 / 启停'}).first().click();
  await page.waitForFunction(() => document.querySelector('[wire\\:model="pixel.name"]').value !== '');
  const originalName=await page.getByLabel('名称',{exact:true}).inputValue();
  await page.getByLabel('名称',{exact:true}).fill('');
  await page.getByRole('tab',{name:'推广链接'}).click();
  await page.getByRole('dialog').getByRole('button',{name:'保存并继续'}).click();
  await page.getByRole('dialog').getByRole('alert').waitFor({state:'visible'});
  if(await page.getByRole('tab',{name:'像素资产'}).getAttribute('aria-selected')!=='true') throw new Error('Failed save left editor');
  passed.push('server rejected save retains dirty editor and current tab');
  await page.getByRole('dialog').getByRole('button',{name:'继续编辑'}).click();
  await page.getByLabel('名称',{exact:true}).fill(originalName);
  await page.getByRole('tab',{name:'推广链接'}).click();
  await page.getByRole('dialog').getByRole('button',{name:'保存并继续'}).click();
  await page.locator('dialog.h5-confirm-leave').waitFor({state:'detached'});
  await page.waitForFunction(() => document.querySelector('[data-admin-tab="links"]').getAttribute('aria-selected') === 'true');
  passed.push('confirmed server save continues tab navigation');
  await page.getByRole('tab',{name:'像素资产'}).click();
  await page.getByLabel('名称',{exact:true}).fill('Unsaved local fixture');
  await page.getByRole('button',{name:'登记另一个'}).click();
  await page.getByRole('dialog').getByRole('button',{name:'继续编辑'}).click();
  if(await page.getByLabel('名称',{exact:true}).inputValue()!=='Unsaved local fixture') throw new Error('New pixel action lost edit');
  passed.push('new asset action protects unsaved editor');
  await page.getByRole('button',{name:'分配像素'}).first().click();
  await page.getByRole('dialog').getByRole('button',{name:'放弃修改'}).click();
  await page.locator('dialog.h5-confirm-leave').waitFor({state:'detached'});
  await page.waitForFunction(() => document.querySelector('[data-admin-tab="assignments"]').getAttribute('aria-selected') === 'true');
  passed.push('authorized assignment action restores editor and opens assignment tab');
  for(const name of ['本站付款汇总','发送状态','负责人分配','推广链接']) { await page.getByRole('tab',{name}).click(); if(await page.locator('[role=tabpanel]:not([hidden])').count()!==1) throw new Error('Multiple panels visible after '+name); passed.push('tab '+name+' renders one panel'); }
  await page.setViewportSize({width:375,height:667});
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth);
  if(overflow) throw new Error('mobile page has horizontal overflow');
  passed.push('mobile viewport has no horizontal overflow');
  console.log(JSON.stringify({url:new URL(page.url()).pathname,passed,tabs:await tabs.allTextContents(),externalSending:await page.locator('.h5-alert strong').innerText()}));
 } finally { await browser.close(); }
})().catch(e=>{console.error(e.message);process.exit(1)});
