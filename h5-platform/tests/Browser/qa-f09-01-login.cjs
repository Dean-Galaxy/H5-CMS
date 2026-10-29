// 06: real normal enrollment in a dedicated synthetic-account environment. No screenshots/trace/HAR.
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.H5_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../../..');
const candidate = '6b6e7d5149b2e4b599766e22d73ff99e65c159d5';
const ws = JSON.parse(fs.readFileSync(root + '/.runtime/workspace.json'));
assert.equal(fs.realpathSync(ws.root), root);
assert.equal(ws.port, 8391); assert.equal(ws.db_port, 5891);
const production = ['app','config','resources','public','routes','database','bootstrap','composer.json','composer.lock'].map(p=>'h5-platform/'+p);
assert.equal(cp.execFileSync('git',['diff',candidate,'--',...production],{cwd:root,encoding:'utf8'}),'');
const accountPath = root + '/.runtime/06-mfa/account.json';
assert(!fs.lstatSync(accountPath).isSymbolicLink() && (fs.statSync(accountPath).mode & 0o077) === 0);
const account = JSON.parse(fs.readFileSync(accountPath));
assert(/^h5-06-mfa-[a-f0-9]+@example\.test$/.test(account.email));
const base = 'http://127.0.0.1:'+ws.port;
const run = root + '/.runtime/06-mfa/run-' + Date.now();
fs.mkdirSync(run,{mode:0o700});
const report = {candidate,checkout:cp.execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim(),base,dbPort:ws.db_port,startedAt:new Date().toISOString(),checks:[],failures:[],screenshots:false,trace:false,secretsExported:false};
let stage='preflight',browser,page,context,pageErrors=0;
function pass(name,value){assert(value,name);report.checks.push({name,passed:true}); console.log('PASS '+name);}
function state(){return JSON.parse(cp.execFileSync('/opt/homebrew/opt/php@8.4/bin/php',[root+'/scripts/ops/mfa_state.php'],{cwd:root,encoding:'utf8',stdio:['ignore','pipe','pipe']}));}
function otp(secret,offset=0){let bits='';for(const c of secret)bits+='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5,'0');const key=Buffer.from((bits.match(/.{8}/g)||[]).map(x=>parseInt(x,2))),t=Buffer.alloc(8);t.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)+offset));const h=crypto.createHmac('sha1',key).update(t).digest();return String((h.readUInt32BE(h[19]&15)&0x7fffffff)%1000000).padStart(6,'0');}
async function freshPage(){context=await browser.newContext({viewport:{width:1100,height:1000},deviceScaleFactor:1});page=await context.newPage();page.setDefaultTimeout(60000);page.setDefaultNavigationTimeout(60000);page.on('pageerror',()=>pageErrors++);}
async function login(){await rateBudget(2);await page.goto(base+'/admin/login');await page.locator('input[type=email]').fill(account.email);await page.locator('input[type=password]').fill(account.password);await authSubmit(true);}
async function logout(){await page.locator('form[action$="/logout"]').evaluate(f=>f.requestSubmit());await page.waitForURL('**/admin/login');await context.close();await freshPage();}
async function inspectQr(){const img=page.getByAltText('QR code to scan with an authenticator app',{exact:true});await img.waitFor();return img.evaluate(async e=>{let decoded=false;try{await e.decode();decoded=true;}catch{}const src=e.getAttribute('src'),prefix='data:image/svg+xml;base64,';let singleLayer=false;if(src.startsWith(prefix)){const inner=atob(src.slice(prefix.length));const doc=new DOMParser().parseFromString(inner,'image/svg+xml');singleLayer=doc.documentElement.localName==='svg'&&!doc.querySelector('parsererror')&&!inner.startsWith('data:');}return{decoded,naturalWidthPositive:e.naturalWidth>0,singleLayer};});}
async function secret(){const s=(await page.locator('.fi-sc-text.fi-font-mono:visible').allTextContents()).map(x=>x.trim()).filter(x=>/^[A-Z2-7]{16,}$/.test(x));assert.equal(s.length,1);return s[0];}
// Respect the real per-IP window. No clear/reset or limiter bypass.
async function rateBudget(required){
 for(let tries=0;tries<3;tries++){
  const v=state().login_rate_limit;
  if(v.attempts+required<=v.maximum)return;
  report.rateLimitWaits??=[];report.rateLimitWaits.push({stage,attempts:v.attempts,waitSeconds:v.wait_seconds,required});
  let ms=(v.wait_seconds+1)*1000;while(ms>0){const n=Math.min(ms,30000);await page.waitForTimeout(n);ms-=n;}
 }
 throw Error('Rate window did not naturally clear');
}
async function authSubmit(password){
 await rateBudget(1);await page.waitForLoadState('networkidle');
 const before=state().login_rate_limit;
 const response=await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).origin===base),(password?page.locator('button[type=submit]'):page.getByRole('button',{name:'Confirm sign in',exact:true})).click()]);
 report.authRequests??=[];report.authRequests.push({stage,status:response[0].status(),attemptsBefore:before.attempts,attemptsAfter:state().login_rate_limit.attempts});
}
(async()=>{try{
 const enrollment=process.env.H5_MFA_ENROLLMENT_RUN;assert(/^run-[0-9]+$/.test(enrollment||''));
 const previous=JSON.parse(fs.readFileSync(root+'/.runtime/06-mfa/'+enrollment+'/result.json'));
 assert.equal(previous.candidate,candidate);assert(previous.checks.some(x=>x.name==='setup completion requires a fresh MFA-proven login'&&x.passed));
 const secrets=JSON.parse(fs.readFileSync(root+'/.runtime/06-mfa/'+enrollment+'/synthetic-secrets.private.json'));
 report.enrollmentRun=enrollment;report.scope='Continuation on same normally enrolled synthetic account; no new or preseeded MFA.';
 const before=state();pass('same normally enrolled synthetic account retains eight unused recovery codes',before.secret_set&&before.recovery_count===8&&before.staff_count===3);
 browser=await chromium.launch({headless:true,executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});report.browser=browser.version();await freshPage();
 stage='first password';await login();await page.locator('input[id="multiFactorChallengeForm.app.code"]').waitFor();
 stage='first TOTP';await page.waitForTimeout(31000-Date.now()%30000);await page.locator('input[id="multiFactorChallengeForm.app.code"]').fill(otp(secrets.secret));await authSubmit(false);await page.waitForURL(u=>u.pathname==='/admin');pass('normal password and fresh-window TOTP login succeeds',true);
 stage='explicit logout';await logout();pass('explicit normal logout reaches login page',true);
 stage='second password';await login();await page.locator('input[id="multiFactorChallengeForm.app.code"]').waitFor();pass('password after explicit logout still requires MFA',true);
 stage='second TOTP';await page.waitForTimeout(31000-Date.now()%30000);await page.locator('input[id="multiFactorChallengeForm.app.code"]').fill(otp(secrets.secret));await authSubmit(false);await page.waitForURL(u=>u.pathname==='/admin');pass('new session valid TOTP enters admin',true);
 stage='logout before recovery';await logout();stage='password before recovery';await login();await page.locator('input[id="multiFactorChallengeForm.app.code"]').waitFor();
 stage='show recovery input';await page.getByRole('button',{name:'Use a recovery code instead',exact:true}).click();const input=page.locator('input[id="multiFactorChallengeForm.app.recoveryCode"]');await input.waitFor();
 stage='fill recovery input';await input.fill(secrets.recoveryCodes[0]);await input.press('Tab');
 // Let the normal Livewire on-blur update complete before confirmation, as a human would.
 await page.waitForLoadState('networkidle');
 stage='confirm recovery';await authSubmit(false);await page.waitForURL(u=>u.pathname==='/admin');pass('normal recovery code enters admin and consumes exactly one',state().recovery_count===7);
 stage='logout before recovery reuse';await logout();stage='password before recovery reuse';await login();await page.locator('input[id="multiFactorChallengeForm.app.code"]').waitFor();
 stage='show reuse input';await page.getByRole('button',{name:'Use a recovery code instead',exact:true}).click();await page.locator('input[id="multiFactorChallengeForm.app.recoveryCode"]').fill(secrets.recoveryCodes[0]);await page.locator('input[id="multiFactorChallengeForm.app.recoveryCode"]').press('Tab');await page.waitForLoadState('networkidle');
 stage='confirm reuse';await authSubmit(false);await page.getByText('The recovery code you entered is invalid.',{exact:true}).waitFor();pass('used recovery code is rejected and remaining count stays seven',new URL(page.url()).pathname==='/admin/login'&&state().recovery_count===7);pass('no browser script errors',pageErrors===0);report.result='passed';
 }catch(e){report.result='failed';const flags=page?await page.locator('body').evaluate(e=>({rateLimited:/Too many|次数过多/.test(e.textContent),invalidRecovery:e.textContent.includes('The recovery code you entered is invalid.'),invalidCode:e.textContent.includes('The code you entered is invalid.')})).catch(()=>null):null;report.failures.push({stage,type:e.name,path:page?new URL(page.url()).pathname:null,flags,state:state()});console.log(JSON.stringify(report.failures));process.exitCode=1;}finally{report.finishedAt=new Date().toISOString();report.browserScriptErrors=pageErrors;if(browser)await browser.close();fs.writeFileSync(run+'/result.json',JSON.stringify(report,null,2));console.log(JSON.stringify({result:report.result,checks:report.checks.length,evidence:run+'/result.json'}));}})();
