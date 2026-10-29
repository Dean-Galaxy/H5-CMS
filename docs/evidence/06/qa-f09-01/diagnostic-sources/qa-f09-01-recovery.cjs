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
async function login(){await page.goto(base+'/admin/login');await page.locator('input[type=email]').fill(account.email);await page.locator('input[type=password]').fill(account.password);await page.locator('button[type=submit]').click();}
async function logout(){await page.locator('form[action$="/logout"]').evaluate(f=>f.requestSubmit());await page.waitForURL('**/admin/login');await context.close();await freshPage();}
async function inspectQr(){const img=page.getByAltText('QR code to scan with an authenticator app',{exact:true});await img.waitFor();return img.evaluate(async e=>{let decoded=false;try{await e.decode();decoded=true;}catch{}const src=e.getAttribute('src'),prefix='data:image/svg+xml;base64,';let singleLayer=false;if(src.startsWith(prefix)){const inner=atob(src.slice(prefix.length));const doc=new DOMParser().parseFromString(inner,'image/svg+xml');singleLayer=doc.documentElement.localName==='svg'&&!doc.querySelector('parsererror')&&!inner.startsWith('data:');}return{decoded,naturalWidthPositive:e.naturalWidth>0,singleLayer};});}
async function secret(){const s=(await page.locator('.fi-sc-text.fi-font-mono:visible').allTextContents()).map(x=>x.trim()).filter(x=>/^[A-Z2-7]{16,}$/.test(x));assert.equal(s.length,1);return s[0];}
(async()=>{try{
 const enrollment=process.env.H5_MFA_ENROLLMENT_RUN;assert(/^run-[0-9]+$/.test(enrollment||''));const secrets=JSON.parse(fs.readFileSync(root+'/.runtime/06-mfa/'+enrollment+'/synthetic-secrets.private.json'));report.enrollmentRun=enrollment;report.scope='Same normally enrolled synthetic account; second unused recovery code, previous diagnostic consumed first asynchronously.';
 const baseline=state().recovery_count;assert.equal(baseline,7);report.baselineRecoveryCount=baseline;
 browser=await chromium.launch({headless:true,executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});report.browser=browser.version();await freshPage();report.postResponses=[];
 function watch(){page.on('response',r=>{if(r.request().method()==='POST'){const u=new URL(r.url());if(u.origin===base)report.postResponses.push({path:u.pathname,status:r.status(),at:new Date().toISOString()})}})}watch();
 stage='password for recovery';await login();await page.locator('input[id="multiFactorChallengeForm.app.code"]').waitFor();pass('password alone still requires MFA',true);
 stage='open recovery';await page.getByRole('button',{name:'Use a recovery code instead',exact:true}).click();const input=page.locator('input[id="multiFactorChallengeForm.app.recoveryCode"]');await input.fill(secrets.recoveryCodes[1]);await input.press('Tab');await page.waitForLoadState('networkidle');
 stage='confirm unused recovery';await page.getByRole('button',{name:'Confirm sign in',exact:true}).click();await page.waitForURL(u=>u.pathname==='/admin');pass('unused recovery code successfully logs in',true);pass('exactly one remaining code consumed',state().recovery_count===baseline-1);
 stage='explicit logout';await logout();watch();pass('normal logout returns to login',true);
 stage='password for reuse';await login();await page.locator('input[id="multiFactorChallengeForm.app.code"]').waitFor();await page.getByRole('button',{name:'Use a recovery code instead',exact:true}).click();await page.locator('input[id="multiFactorChallengeForm.app.recoveryCode"]').fill(secrets.recoveryCodes[1]);await page.locator('input[id="multiFactorChallengeForm.app.recoveryCode"]').press('Tab');await page.waitForLoadState('networkidle');
 stage='confirm reused recovery';await page.getByRole('button',{name:'Confirm sign in',exact:true}).click();await page.getByText('The recovery code you entered is invalid.',{exact:true}).waitFor();pass('same recovery code reuse is explicitly rejected',new URL(page.url()).pathname==='/admin/login');pass('rejected reuse preserves remaining count',state().recovery_count===baseline-1);pass('no browser script errors',pageErrors===0);report.result='passed';
 }catch(e){report.result='failed';report.failures.push({stage,type:e.name,path:page?new URL(page.url()).pathname:null,state:state(),validation:page?await page.locator('[data-validation-error]').allTextContents():[]});console.log(JSON.stringify(report.failures));process.exitCode=1;}finally{report.finishedAt=new Date().toISOString();if(browser)await browser.close();fs.writeFileSync(run+'/result.json',JSON.stringify(report,null,2));console.log(JSON.stringify({result:report.result,checks:report.checks.length,evidence:run+'/result.json'}));}})();
