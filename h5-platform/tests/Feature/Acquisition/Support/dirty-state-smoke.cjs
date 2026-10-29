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
 const browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
 const context=await browser.newContext({viewport:{width:1280,height:900}});
 const page=await context.newPage(), passed=[], errors=[];
 page.on('pageerror',error=>errors.push(error.message));
 const check=(name,condition)=>{if(!condition)throw new Error(name);passed.push(name)};
 const fixture=JSON.parse(fs.readFileSync('.runtime/acquisition-f09-fixture.json','utf8'));
 const prompt=()=>page.locator('dialog.h5-confirm-leave');
 const dialog=type=>page.locator('#acquisition-'+type+'-editor');
 const action=type=>type==='assignment'?'selectAssignment':type==='pixel'?'selectPixel':'selectLink';
 const selector=type=>`[data-editor-action="${action(type)}"][data-editor-id="${type==='link'?fixture.link_id:fixture.pixel_id}"]`;
 const saveName=type=>({link:'保存链接',pixel:'保存像素',assignment:'保存共享分配'})[type];
 const saveMethod=type=>({link:'saveLink',pixel:'savePixel',assignment:'share'})[type];
 async function open(type,target=page) {
  await target.goto(base+'/admin/acquisition-desk?tab='+(type==='link'?'links':'pixels'));
  await target.locator(selector(type)).click();const d=target.locator('#acquisition-'+type+'-editor');await d.waitFor({state:'visible'});
  if(type==='assignment'){await d.getByRole('button',{name:'读取可分配人员',exact:true}).click();await d.getByRole('checkbox').first().waitFor({state:'visible'});}
  return d;
 }
 async function modify(type,d) {
  if(type==='assignment') {const c=d.getByRole('checkbox').first(),value=!(await c.isChecked());await c.setChecked(value);return value;}
  const f=d.getByLabel(type==='pixel'?'名称':'推广名称',{exact:true}),value=(await f.inputValue())+' 未保存';await f.fill(value);return value;
 }
 async function retained(type,d,value) {return type==='assignment'?(await d.getByRole('checkbox').first().isChecked())===value:(await d.getByLabel(type==='pixel'?'名称':'推广名称',{exact:true}).inputValue())===value;}
 async function cancelAgain(type,value) {
  const d=dialog(type);await d.getByRole('button',{name:'取消',exact:true}).click();await prompt().waitFor({state:'visible'});
  await prompt().getByRole('button',{name:'继续编辑'}).click();await prompt().waitFor({state:'detached'});
  check(type+' continue after refusal/unknown keeps input',await retained(type,d,value));
  await d.getByRole('button',{name:'取消',exact:true}).click();await prompt().waitFor({state:'visible'});
  check(type+' repeated cancel remains guarded',await d.isVisible());
  await prompt().getByRole('button',{name:'放弃修改'}).click();await d.waitFor({state:'hidden'});
  await page.waitForFunction(action=>document.activeElement?.dataset.editorAction===action,action(type));
  passed.push(type+' explicit discard restores opener focus');
 }
 try {
  await login(page,account);
  // Missing required pixel name: the server's validation morph must not erase edit state.
  let d=await open('pixel');check('external sending explicitly disabled',(await page.locator('[data-acquisition-environment]').innerText()).includes('外部发送：关闭'));await d.getByLabel('名称',{exact:true}).fill('');await d.getByRole('button',{name:'取消',exact:true}).click();
  await prompt().getByRole('button',{name:'保存并继续'}).click();await prompt().getByRole('alert').waitFor({state:'visible'});
  await prompt().getByRole('button',{name:'继续编辑'}).click();await prompt().waitFor({state:'detached'});
  check('pixel required rejection keeps empty input and editor',await d.isVisible()&&await d.getByLabel('名称',{exact:true}).inputValue()==='');
  await cancelAgain('pixel','');
  for(const type of ['link','pixel','assignment']) {
   // A second real browser session saves the same local fixture, creating an actual version conflict.
   d=await open(type);const value=await modify(type,d);
   const other=await page.context().newPage(),bumpType=type==='assignment'?'pixel':type;
   const otherDialog=await open(bumpType,other);await otherDialog.getByRole('button',{name:saveName(bumpType),exact:true}).click();await otherDialog.waitFor({state:'hidden'});await other.close();
   await d.getByRole('button',{name:saveName(type),exact:true}).click();await page.getByText('版本已变更，请重新打开后保存',{exact:true}).first().waitFor({state:'visible'});
   check(type+' real stale version refusal retains edit',await d.isVisible()&&await retained(type,d,value));
   await cancelAgain(type,value);
   // Explicit local network fault: abort only this save before delivery; no external platform request.
   d=await open(type);const unknownValue=await modify(type,d);let interrupted=false;
   const route=async r=>{const payload=r.request().postDataJSON();const saving=payload?.components?.some(c=>c.calls?.some(call=>call.method===saveMethod(type)));
    if(saving&&!interrupted){interrupted=true;await r.abort('failed');}else await r.continue();};
   await page.route('**/livewire*/update',route);
   await d.getByRole('button',{name:saveName(type),exact:true}).click();await d.locator('[data-editor-error]').waitFor({state:'visible'});await page.unroute('**/livewire*/update',route);
   check(type+' unknown request result retains edit and shows failure',interrupted&&await d.isVisible()&&await retained(type,d,unknownValue));
   await cancelAgain(type,unknownValue);
   // Real confirmed save clears dirty. Write existing values back so fixture semantics stay unchanged.
   d=await open(type);
   if(type==='assignment'){const c=d.getByRole('checkbox').first(),original=await c.isChecked();await c.setChecked(!original);await c.setChecked(original);}
   else {const f=d.getByLabel(type==='pixel'?'名称':'推广名称',{exact:true}),original=await f.inputValue();await f.fill(original+' temporary');await f.fill(original);}
   await d.getByRole('button',{name:saveName(type),exact:true}).click();await d.waitFor({state:'hidden'});
   await page.waitForFunction(action=>document.activeElement?.dataset.editorAction===action,action(type));
   d=await open(type);await d.getByRole('button',{name:'取消',exact:true}).click();await d.waitFor({state:'hidden'});
   check(type+' confirmed save clears dirty and clean reopen closes without prompt',await prompt().count()===0);
  }
  check('no browser JavaScript errors',errors.length===0);
  console.log(JSON.stringify({passed,browser:browser.version(),base:new URL(base).origin,externalSending:'disabled',unknownSimulation:'local save request aborted before delivery',fixtureMutation:'own test fixture versions incremented by unchanged-value saves; names and assignments preserved'}));
 }finally{await browser.close();}
})().catch(error=>{console.error(error.message);process.exit(1)});
