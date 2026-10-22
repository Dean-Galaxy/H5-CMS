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
 const initial=state();pass('fresh synthetic owner has no MFA or recovery codes',initial.staff_count===3&&!initial.secret_set&&initial.recovery_count===0);pass('actual PHP has no imagick',initial.imagick===false);
 browser=await chromium.launch({headless:true,executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});report.browser=browser.version();await freshPage();
 stage='first normal login';await login();await page.waitForURL('**/admin/multi-factor-authentication/set-up');pass('first login requires normal setup',true);
 stage='first setup QR';await page.getByRole('button',{name:'Set up',exact:true}).click();const first=await inspectQr();pass('first QR decodes with positive natural width and one SVG data layer',first.decoded&&first.naturalWidthPositive&&first.singleLayer);const cancelledSecret=await secret();
 stage='cancel setup';await page.getByRole('button',{name:'取消',exact:true}).click();await page.getByAltText('QR code to scan with an authenticator app',{exact:true}).waitFor({state:'hidden'});const cancelled=state();pass('cancel leaves no persisted MFA or recovery codes',!cancelled.secret_set&&cancelled.recovery_count===0);
 stage='reopen setup';await page.getByRole('button',{name:'Set up',exact:true}).click();const second=await inspectQr();const enabledSecret=await secret();pass('reopen creates a different candidate and a decodable QR',enabledSecret!==cancelledSecret&&second.decoded&&second.naturalWidthPositive&&second.singleLayer);
 // Save only synthetic candidate privately for recovery if this harness is interrupted. Never evidence.
 fs.writeFileSync(run+'/synthetic-secrets.private.json',JSON.stringify({secret:enabledSecret}),{mode:0o600});
 stage='reject invalid setup code';const accepted=new Set(Array.from({length:17},(_,i)=>i-8).map(n=>otp(enabledSecret,n)));let invalid='000000';while(accepted.has(invalid))invalid=String(Number(invalid)+1).padStart(6,'0');await page.locator('input[id$=".code"]:visible').fill(invalid);stage='invalid setup password input';await page.locator('input[id$=".password"]:visible').fill(account.password);await page.getByRole('button',{name:'下一步',exact:true}).click();stage='invalid setup error response';await page.getByText('The code you entered is invalid.',{exact:true}).waitFor();pass('invalid setup code rejected without persistence',!state().secret_set);
 stage='valid code to recovery step';await page.locator('input[id$=".code"]:visible').fill(otp(enabledSecret));await page.getByRole('button',{name:'下一步',exact:true}).click();await page.getByRole('button',{name:'Enable authenticator app',exact:true}).waitFor();const recovery=(await page.locator('.fi-sc-text.fi-font-mono:visible').allTextContents()).map(s=>s.trim());pass('normal recovery step creates eight unique codes',recovery.length===8&&new Set(recovery).size===8&&recovery.every(s=>s.length>10));const pending=state();pass('before final confirmation MFA and recovery codes are not persisted',!pending.secret_set&&pending.recovery_count===0);fs.writeFileSync(run+'/synthetic-secrets.private.json',JSON.stringify({secret:enabledSecret,recoveryCodes:recovery}),{mode:0o600});
 stage='normal final confirmation';await page.getByRole('button',{name:'Enable authenticator app',exact:true}).click();await page.getByRole('button',{name:'Continue',exact:true}).waitFor();const saved=state();pass('final confirmation persists MFA and eight recovery hashes',saved.secret_set&&saved.recovery_count===8);await page.getByRole('button',{name:'Continue',exact:true}).click();await page.waitForURL('**/admin/login');pass('setup completion requires a fresh MFA-proven login',true);
 stage='fresh session and password reentry';await context.close();await freshPage();await login();await page.locator('input[id="multiFactorChallengeForm.app.code"]').waitFor();pass('new session asks for MFA after password',new URL(page.url()).pathname==='/admin/login');
 stage='TOTP login';// No sleeping longer than a single OTP window; do not reuse a setup time step.
 await page.waitForTimeout(31000-Date.now()%30000);await page.locator('input[id="multiFactorChallengeForm.app.code"]').fill(otp(enabledSecret));await authSubmit(false);await page.waitForURL(u=>u.pathname==='/admin');pass('fresh time-window TOTP login succeeds',true);
 stage='explicit logout then TOTP';await logout();await login();await page.locator('input[id="multiFactorChallengeForm.app.code"]').waitFor();pass('explicit logout requires password and a new MFA challenge',true);await page.waitForTimeout(31000-Date.now()%30000);await page.locator('input[id="multiFactorChallengeForm.app.code"]').fill(otp(enabledSecret));await authSubmit(false);await page.waitForURL(u=>u.pathname==='/admin');pass('explicit logout then fresh TOTP successfully reenters admin',true);
 stage='recovery login';await logout();await login();await page.locator('input[id="multiFactorChallengeForm.app.code"]').waitFor();await page.getByRole('button',{name:'Use a recovery code instead',exact:true}).click();await page.locator('input[id="multiFactorChallengeForm.app.recoveryCode"]').fill(recovery[0]);await page.locator('input[id="multiFactorChallengeForm.app.recoveryCode"]').press('Tab');await page.waitForLoadState('networkidle');await authSubmit(false);await page.waitForURL(u=>u.pathname==='/admin');pass('normal recovery code login succeeds and consumes exactly one',state().recovery_count===7);
 stage='recovery reuse rejection';await logout();await login();await page.locator('input[id="multiFactorChallengeForm.app.code"]').waitFor();await page.getByRole('button',{name:'Use a recovery code instead',exact:true}).click();await page.locator('input[id="multiFactorChallengeForm.app.recoveryCode"]').fill(recovery[0]);await page.locator('input[id="multiFactorChallengeForm.app.recoveryCode"]').press('Tab');await page.waitForLoadState('networkidle');await authSubmit(false);await page.getByText('The recovery code you entered is invalid.',{exact:true}).waitFor();pass('used recovery code rejected and remains at seven',new URL(page.url()).pathname==='/admin/login'&&state().recovery_count===7);
 pass('no browser script errors',pageErrors===0);report.result='passed';
 }catch(e){report.result='failed';report.failures.push({stage,type:e.name,path:page?new URL(page.url()).pathname:null});console.log(JSON.stringify({result:'failed',stage,type:e.name}));process.exitCode=1;}finally{report.finishedAt=new Date().toISOString();report.browserScriptErrors=pageErrors;if(browser)await browser.close();fs.writeFileSync(run+'/result.json',JSON.stringify(report,null,2));console.log(JSON.stringify({result:report.result,checks:report.checks.length,evidence:run+'/result.json'}));}})();
