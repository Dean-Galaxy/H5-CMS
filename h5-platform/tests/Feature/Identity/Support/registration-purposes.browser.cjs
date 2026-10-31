const fs=require('node:fs'),path=require('node:path');
const {chromium,expect}=require(process.env.H5_PLAYWRIGHT_TEST || '/Users/hejunxiang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/test');
const source=fs.readFileSync(process.env.H5_IDENTITY_SCRIPT || path.resolve(__dirname,'../../../../public/identity.js'),'utf8');
const {execFileSync}=require('node:child_process');
const translations=Object.fromEntries(['en','zh_CN'].map(locale=>[locale,JSON.parse(execFileSync(process.env.H5_PHP_BIN||'/opt/homebrew/opt/php@8.4/bin/php',['-r', 'echo json_encode(require $argv[1]);',path.resolve(__dirname,`../../../../lang/${locale}/identity.php`)],{encoding:'utf8'}))]));
const css=fs.readFileSync(path.resolve(__dirname,'../../../../public/identity.css'),'utf8');
const text={account_link_name:'Account-linking preference',advertising_name:'Advertising preference',latest:'Use latest choice',draft:'Will save',site_on:'Site session analytics is running',site_off:'Site session analytics is off',site_unknown:'Site state unconfirmed',rate_limited:'Please wait before retrying',expired:'Page expired',invalid:'Check your choice',checking:'Checking',saved:'Saved, not running',unchanged:'Unchanged, not running',not_requested:'Not requested',unconfirmed:'Account created; this choice unconfirmed',conflict:'Conflict; reconfirm to submit',retry:'Reconfirm',unavailable:'Unavailable',account_unknown:'Account unconfirmed',account_changed:'Account changed; drafts discarded',created:'Account created; optional processing off',unknown:'Unknown',allow:'Permission saved',deny:'Refusal saved',off:'Not running',site:'Site analytics',legacy:'Earlier separate advertising'};
const allScenarios=['untouched','both-saved','partial-retry','conflict-rebase','lost-patch','lost-registration','identity-unknown','identity-read-fault','identity-changed','changed-before-retry','existing-allow','legacy-not-new-grant','context-failure','csrf-failure','toggle-back','short-english','short-chinese','large-english','large-chinese','conflict-use-latest','rate-limited','lost-registration-zh','lost-registration-choice-failure','lost-registration-anonymous','csrf-switch-before-identity','csrf-switch-after-identity'];
const scenarios=process.env.H5_IDENTITY_SCENARIOS ? allScenarios.filter(s=>process.env.H5_IDENTITY_SCENARIOS.split(',').includes(s)) : allScenarios;
(async()=>{const b=await chromium.launch({executablePath:process.env.H5_CHROME||'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true}),out=[];
try{for(const scenario of scenarios){
process.stderr.write('Checking '+scenario+'\n');
const c=await b.newContext({viewport:{width:320,height:500}}),p=await c.newPage();let registrations=0,reads=0;const commands=[];let failOnce=true,switched=false,csrfCalls=0;const order=[],persisted=[];
const state=(choice='unknown',revision=0)=>({choice,revision,effective:false,reason:'ASSOCIATION_NOT_READY',capability:'choice_only',choice_scope:'current_browser'});
let account=state(scenario==='existing-allow'||scenario==='toggle-back'?'allow':'unknown'),ad=state();
const ctx=()=>({schema_version:3,policy_version:'registration-purpose-r1',notice_version:'c-purpose-choice-only-r1-v1',is_test:true,eligibility:'eligible',site_session:{choice:'deny',effective:'off',reason:'SITE_OPTOUT'},purposes:{account_link:account,advertising:ad},legacy_advertising:{choice:scenario==='legacy-not-new-grant'?'allow':'unknown'}});
const viewer={id:'synthetic-viewer-a',email:'synthetic@example.test'};
const zh=['short-chinese','large-chinese','lost-registration-zh'].includes(scenario);
const local=translations[zh?'zh_CN':'en'];
const real=local.r1_purpose_text;
const messages={...text,draft_allow:real.draft_allow,draft_deny:real.draft_deny,created:real.created,current_account:real.current_account,unconfirmed:real.unconfirmed};
const html=`<html lang=${zh?'zh-CN':'en'}><meta charset="utf-8"><style>${css}</style><section class="card identity-registration-r1"><h1>${zh?'本机注册测试':'Local test registration'}</h1><form method="post" action="/api/v1/auth/register" data-identity-form data-registration-r1 data-unknown="${local.r1_unknown}" data-confirmed="${local.r1_confirmed}">
<label>Email<input type=email name=email value="synthetic@example.test"></label><input type=password value="OnlySynthetic123">
<fieldset data-registration-purposes><legend>${zh?'可选的额外用途，不影响注册':'Optional additional uses — not required to register'}</legend>${['account_link','advertising'].map(k=>`<label class=identity-check><input type=checkbox disabled data-registration-purpose="${k}"><span>${k==='account_link'?'Account-linked analytics':'Advertising measurement'}</span></label><p role=status data-purpose-state="${k}">Checking</p><button type=button data-purpose-retry="${k}" hidden>Reconfirm ${k}</button><button type=button data-purpose-latest="${k}" hidden>Use latest choice</button><details><summary>Technical details</summary><p data-purpose-detail="${k}"></p></details>`).join('')}<p data-purpose-site></p><p data-purpose-legacy></p></fieldset>
<script type=application/json data-purpose-messages>${JSON.stringify(messages)}</script><button type=submit>Create</button><p data-result></p><button type=button data-registration-check hidden>Check account</button><a href=/identity/account data-registration-continue hidden>Continue using the site</a></form></section>`;
await p.route('**/*',async route=>{const r=route.request(),u=new URL(r.url());
if(u.pathname==='/fixture')return route.fulfill({contentType:'text/html',body:html});
if(u.pathname==='/api/v3/measurement/purposes/context')return route.fulfill({status:scenario==='context-failure'?503:200,contentType:'application/json',body:JSON.stringify(scenario==='context-failure'?{}:ctx())});
if(u.pathname==='/api/v1/auth/register'){registrations++;if(scenario.startsWith('lost-registration'))return route.abort();return route.fulfill({contentType:'application/json',body:JSON.stringify({identity:{viewer},redirect_to:'/identity/account'})});}
if(u.pathname==='/api/v1/auth/me'){reads++;let v=switched?{id:'synthetic-viewer-b',email:'other@example.test'}:viewer;let status=200;
order.push(`me:${switched?'B':'A'}`);
// The identity response snapshots A, then another tab switches the necessary session to B.
if(scenario==='csrf-switch-after-identity'&&csrfCalls>0)switched=true;
if(scenario==='identity-unknown'||scenario==='lost-registration-anonymous')v=null;
if(scenario==='identity-changed'||scenario==='changed-before-retry'&&commands.length>=2)v={id:'different',email:'other@example.test'};
if(scenario==='identity-read-fault'&&reads===1)status=503;
return route.fulfill({status,contentType:'application/json',body:JSON.stringify({viewer:v})});}
if(u.pathname==='/api/v1/auth/csrf'){
csrfCalls++;if(scenario==='csrf-switch-before-identity')switched=true;
order.push(`csrf:${switched?'B':'A'}`);
return route.fulfill({status:scenario==='csrf-failure'?503:200,contentType:'application/json',body:JSON.stringify({csrf_token:scenario.startsWith('csrf-switch-')?(switched?'synthetic-token-b':'synthetic-token-a'):'synthetic-fixture'})});}

if(u.pathname==='/api/v3/measurement/purposes/preferences'){
const command=r.postDataJSON();commands.push(command);expect(Object.keys(command).sort()).toEqual(['choice','expected_revision','notice_version','operation_id','purpose']);if(scenario.startsWith('csrf-switch-')){
const token=r.headers()['x-csrf-token'];order.push(`patch:${token==='synthetic-token-a'?'A':'B'}/current:${switched?'B':'A'}`);
if(token!==(switched?'synthetic-token-b':'synthetic-token-a'))return route.fulfill({status:419,contentType:'application/json',body:'{}'});
persisted.push(command);
}else expect(r.headers()['x-csrf-token']).toBe('synthetic-fixture');
if(command.purpose==='advertising'&&failOnce&&['partial-retry','changed-before-retry'].includes(scenario)){failOnce=false;return route.fulfill({status:503,contentType:'application/json',body:'{}'});}
if(command.purpose==='account_link'&&failOnce&&['lost-patch','lost-registration-choice-failure'].includes(scenario)){failOnce=false;return route.abort();}
if(command.purpose==='account_link'&&failOnce&&scenario==='rate-limited'){failOnce=false;return route.fulfill({status:429,headers:{'Retry-After':'1'},contentType:'application/json',body:'{}'});}
if(command.purpose==='account_link'&&failOnce&&['conflict-rebase','conflict-use-latest'].includes(scenario)){failOnce=false;account=state('deny',2);return route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({schema_version:3,purpose:'account_link',state:account})});}
const s=state(command.choice,command.expected_revision+1);if(command.purpose==='account_link')account=s;else ad=s;
return route.fulfill({contentType:'application/json',body:JSON.stringify({schema_version:3,purpose:command.purpose,status:'saved',state:s})});
}
return route.abort();});
await p.goto('http://127.0.0.1:18601/fixture');await p.addScriptTag({content:source});if(scenario.startsWith('large-'))await p.addStyleTag({content:'body{font-size:32px}label,button{font-size:inherit}'});
await expect(p.locator('[data-registration-continue]')).not.toBeVisible();
const a=p.locator('[data-registration-purpose="account_link"]'),d=p.locator('[data-registration-purpose="advertising"]');
if(scenario!=='context-failure')await expect(a).toBeEnabled();
else await expect(p.locator('[data-purpose-state="account_link"]')).toContainText('Unavailable');
if(['both-saved','partial-retry','changed-before-retry','short-english','short-chinese','large-english','large-chinese'].includes(scenario)){await a.check();await d.check();}
else if(['conflict-rebase','lost-patch','lost-registration','identity-unknown','identity-read-fault','identity-changed','csrf-failure','conflict-use-latest','rate-limited','lost-registration-zh','lost-registration-choice-failure','lost-registration-anonymous','csrf-switch-before-identity','csrf-switch-after-identity'].includes(scenario))await a.check();
if(scenario==='existing-allow')await expect(a).toBeChecked();
if(scenario==='toggle-back'){await a.uncheck();await a.check();}
if(scenario==='legacy-not-new-grant')await expect(a).not.toBeChecked();
await p.getByRole('button',{name:'Create',exact:true}).click();
if(scenario==='lost-registration-anonymous'){
 await expect(p.locator('[data-result]')).toHaveText(local.r1_unknown);
 await expect(p.locator('[data-registration-continue]')).not.toBeVisible();
 await p.locator('form').evaluate(f=>f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
 await expect.poll(()=>reads).toBe(2);expect(registrations).toBe(1);expect(commands.length).toBe(0);
 out.push({scenario,status:'passed',registrationPosts:registrations,purposeCommands:commands.length});await c.close();continue;
}
await expect(p.locator('[data-registration-continue]')).toBeVisible();
const s=k=>p.locator(`[data-purpose-state="${k}"]`),retry=k=>p.locator(`[data-purpose-retry="${k}"]`);
if(scenario==='csrf-switch-before-identity'){
 await expect.poll(async()=> (await s('account_link').getAttribute('data-status'))==='saved'||(await p.locator('[data-result]').textContent()).includes('Account changed')).toBe(true);
 process.stderr.write(JSON.stringify({scenario,order,attempted:commands.length,persisted:persisted.length})+'\n');
 await expect(p.locator('[data-result]')).toContainText('Account changed');
 expect(commands.length,JSON.stringify({order,persisted:persisted.length})).toBe(0);expect(persisted.length).toBe(0);
 await expect(a).not.toBeChecked();await expect(a).toBeDisabled();await expect(d).not.toBeChecked();
 expect(order).toEqual(['me:A','csrf:B','me:B']);
 out.push({scenario,status:'passed',registrationPosts:registrations,purposeCommands:commands.length,persistedCommands:persisted.length,order});await c.close();continue;
}
if(scenario==='csrf-switch-after-identity'){
 await expect(s('account_link')).toHaveAttribute('data-status','unconfirmed');
 await expect(s('account_link')).toContainText('Page expired');
 expect(commands.length).toBe(1);expect(persisted.length).toBe(0);expect(csrfCalls).toBe(1);
 expect(order).toEqual(['me:A','csrf:A','me:A','patch:A/current:B']);
 // There is no automatic refresh/replay; an explicit retry obtains B then detects B and discards.
 await retry('account_link').click();await expect(p.locator('[data-result]')).toContainText('Account changed');
 expect(commands.length).toBe(1);expect(persisted.length).toBe(0);expect(order.slice(-2)).toEqual(['csrf:B','me:B']);
 await expect(a).not.toBeChecked();await expect(a).toBeDisabled();expect(registrations).toBe(1);
 out.push({scenario,status:'passed',registrationPosts:registrations,purposeCommands:commands.length,persistedCommands:persisted.length,order});await c.close();continue;
}
if(['both-saved','short-english','short-chinese','large-english','large-chinese'].includes(scenario)){await expect(s('advertising')).toHaveAttribute('data-status','saved');expect(commands.length).toBe(2);}
else if(['partial-retry','changed-before-retry'].includes(scenario)){await expect(s('advertising')).toHaveAttribute('data-status','unconfirmed');await expect(s('account_link')).toHaveAttribute('data-status','saved');expect(commands.length).toBe(2);const old=commands[1];await retry('advertising').click();
if(scenario==='changed-before-retry'){await expect(p.locator('[data-result]')).toContainText('Account changed');expect(commands.length).toBe(2);await expect(a).not.toBeChecked();await expect(d).not.toBeChecked();await expect(a).toBeDisabled();}else{await expect(s('advertising')).toHaveAttribute('data-status','saved');expect(commands.length).toBe(3);expect(commands[2]).toEqual(old);}}
else if(scenario==='conflict-rebase'){await expect(s('account_link')).toHaveAttribute('data-status','conflict');expect(commands.length).toBe(1);const old=commands[0];await retry('account_link').click();await expect(s('account_link')).toHaveAttribute('data-status','saved');expect(commands.length).toBe(2);expect(commands[1].expected_revision).toBe(2);expect(commands[1].operation_id).not.toBe(old.operation_id);}
else if(scenario==='conflict-use-latest'){await expect(s('account_link')).toHaveAttribute('data-status','conflict');await expect(s('account_link')).toContainText('Refusal saved');await expect(s('account_link')).toContainText('Will save: Allow');await p.locator('[data-purpose-latest=account_link]').click();await expect(a).not.toBeChecked();await expect(s('account_link')).toHaveAttribute('data-status','not_requested');expect(commands.length).toBe(1);}
else if(scenario==='rate-limited'){await expect(s('account_link')).toHaveAttribute('data-status','unconfirmed');await expect(retry('account_link')).toBeDisabled();await expect(retry('account_link')).toBeEnabled();await retry('account_link').click();await expect(s('account_link')).toHaveAttribute('data-status','saved');expect(commands[1]).toEqual(commands[0]);}
else if(scenario==='lost-patch'){await expect(s('account_link')).toHaveAttribute('data-status','unconfirmed');const old=commands[0];await retry('account_link').click();await expect(s('account_link')).toHaveAttribute('data-status','saved');expect(commands[1]).toEqual(old);}
else if(['lost-registration','lost-registration-zh'].includes(scenario)){await expect(s('account_link')).toHaveAttribute('data-status','saved');expect(commands.length).toBe(1);}
else if(scenario==='lost-registration-choice-failure'){await expect(s('account_link')).toHaveAttribute('data-status','unconfirmed');await expect(s('account_link')).toContainText(real.unconfirmed);await expect(s('account_link')).not.toContainText('account was created');expect(commands.length).toBe(1);}
else if(scenario==='identity-read-fault'){await expect(s('account_link')).toHaveAttribute('data-status','unconfirmed');expect(commands.length).toBe(0);await retry('account_link').click();await expect(s('account_link')).toHaveAttribute('data-status','saved');}
else if(['identity-unknown','identity-changed'].includes(scenario)){await expect(p.locator('[data-result]')).toContainText(scenario==='identity-changed'?'Account changed':'Account unconfirmed');expect(commands.length).toBe(0);}
else if(scenario==='csrf-failure'){await expect(s('account_link')).toHaveAttribute('data-status','unconfirmed');expect(commands.length).toBe(0);}
else{await expect.poll(()=>reads).toBeGreaterThanOrEqual(1);expect(commands.length).toBe(0);await expect(s('account_link')).toHaveAttribute('data-status','not_requested');}
if(scenario.startsWith('lost-registration')){await expect(p.locator('[data-result]')).toHaveText(real.current_account);await expect(p.locator('[data-result]')).not.toHaveText(real.created);}
if(['both-saved','short-chinese'].includes(scenario))await expect(p.locator('[data-result]')).toHaveText(real.created);
await expect(p.getByRole('button',{name:'Create',exact:true})).not.toBeVisible();
await expect(s('account_link')).not.toContainText('ASSOCIATION_NOT_READY');
await expect(p.locator('[data-purpose-detail=account_link]')).not.toBeVisible();
expect(registrations).toBe(1);await p.locator('form').evaluate(f=>f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));expect(registrations).toBe(1);
await expect(p.locator('[data-registration-continue]')).toBeVisible();
expect(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
out.push({scenario,status:'passed',registrationPosts:registrations,purposeCommands:commands.length});await c.close();
}console.log(JSON.stringify({status:'passed',boundary:'actual identity.js with intercepted HTTP, synthetic identity and 320x500 DOM; no real provider or running site',outcomes:out},null,2));}finally{await b.close()}})().catch(e=>{console.error(e.message);process.exit(1)});
