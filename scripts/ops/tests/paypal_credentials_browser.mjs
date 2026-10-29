// Synthetic data only; refuse to overwrite any existing private submission.
const { chromium } = await import(process.env.H5_PLAYWRIGHT_MODULE || 'playwright');
import fs from 'node:fs';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
const root=process.cwd();
const destination=path.join(root,'.runtime/paypal-sandbox/credentials.json');
if(fs.existsSync(destination)) throw new Error('Existing private submission: browser test refused');
const browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
const context=await browser.newContext({viewport:{width:1100,height:900}});
const page=await context.newPage();
const requests=[];const errors=[];
await context.route('**/*',route=>{
 const url=new URL(route.request().url());
 if(url.origin!=='http://127.0.0.1:8407') {errors.push('external_request_blocked');return route.abort();}
 requests.push(url.pathname);return route.continue();
});
page.on('pageerror',()=>errors.push('browser_script_error'));
const checks={};
let syntheticSaved=false;
try {
 let response=await page.goto('http://127.0.0.1:8407/');
 checks.form_status=response.status()===200;
 checks.masked_fields=await page.locator('input[type=password]').count()===6;
 checks.no_store=(await response.allHeaders())['cache-control']==='no-store';
 await page.screenshot({path:'docs/evidence/06/paypal-preparation/empty-local-form.png',fullPage:true});
 await page.locator('[name=client_id]').fill('SYNTHETIC_CLIENT');
 await page.locator('[name=client_secret]').fill('SYNTHETIC_BROWSER_SECRET_06');
 await page.locator('[name=merchant_scope]').fill('h5_us_sandbox');
 await Promise.all([page.waitForURL('**/save'),page.getByRole('button',{name:'保存到本机私密文件'}).click()]);
 checks.saved_message=(await page.textContent('body')).includes('仅离线格式检查通过');
 const doc=JSON.parse(fs.readFileSync(destination,'utf8'));syntheticSaved=true;
 checks.saved_correctly=doc.credentials.client_secret==='SYNTHETIC_BROWSER_SECRET_06'&&doc.workspace_root===root&&doc.validation==='offline_shape_only';
 checks.private_mode=(fs.statSync(destination).mode&0o777)===0o600;
 checks.no_secret_response=!(await page.content()).includes('SYNTHETIC_BROWSER_SECRET_06');
 await page.goto('http://127.0.0.1:8407/');
 checks.no_prefill=await page.locator('[name=client_secret]').inputValue()==='';
 await Promise.all([page.waitForURL('**/stop'),page.getByRole('button',{name:'关闭录入服务'}).click()]);
 checks.shutdown_message=(await page.textContent('body')).includes('录入服务已关闭');
 checks.no_external_requests=errors.length===0;
 const result={timestamp:new Date().toISOString(),source_sha:execFileSync('git',['rev-parse','HEAD'],{encoding:'utf8'}).trim(),browser:browser.version(),synthetic_only:true,application_started:false,paypal_contacted:false,checks,request_paths:requests,errors};
 fs.writeFileSync('docs/evidence/06/paypal-preparation/browser.json',JSON.stringify(result,null,2)+'\n');
 console.log(JSON.stringify(result));
 if(Object.values(checks).some(v=>v!==true)) process.exitCode=1;
}finally{
 await browser.close();
 if(syntheticSaved) {
  const doc=JSON.parse(fs.readFileSync(destination,'utf8'));
  if(doc.credentials.client_secret==='SYNTHETIC_BROWSER_SECRET_06') fs.unlinkSync(destination);
 }
}
