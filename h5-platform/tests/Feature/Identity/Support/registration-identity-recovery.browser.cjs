// P03 acceptance: entire public identity.js + complete real translations, intercepted HTTP only.
const fs = require('node:fs'), path = require('node:path');
const {execFileSync} = require('node:child_process');
const {createHash} = require('node:crypto');
const {chromium, expect} = require(process.env.H5_PLAYWRIGHT_TEST || '/Users/hejunxiang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/test');
const app = path.resolve(__dirname, '../../../..');
const source = fs.readFileSync(process.env.H5_IDENTITY_SCRIPT || path.join(app, 'public/identity.js'), 'utf8');
const translations = Object.fromEntries(['en','zh_CN'].map(locale=>[locale,JSON.parse(execFileSync(process.env.H5_PHP_BIN || '/opt/homebrew/opt/php@8.4/bin/php', ['-r','echo json_encode(require $argv[1]);',path.join(app,`lang/${locale}/identity.php`)],{encoding:'utf8'}))]));
const scenarios = ['en','zh_CN'].flatMap(locale=>['created','current'].map(origin=>({kind:'recover',locale,origin})));
scenarios.push({kind:'continuous-and-purpose-failure',locale:'en',origin:'created'}, {kind:'denied-late-success',locale:'en',origin:'created'}, {kind:'changed-late-catch',locale:'zh_CN',origin:'current'}, {kind:'unrelated-global-error',locale:'en',origin:'current'});
const state = (choice='unknown',revision=0)=>({choice,revision,effective:false,reason:'ASSOCIATION_NOT_READY',capability:'choice_only',choice_scope:'current_browser'});
(async()=>{
  const browser=await chromium.launch({executablePath:process.env.H5_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true}),outcomes=[];
  try { for (const scenario of scenarios) {
    const c=await browser.newContext(),p=await c.newPage(),local=translations[scenario.locale],text=local.r1_purpose_text;
    const expectedSummary=scenario.origin==='created'?text.created:text.current_account;
    const recovered=scenario.origin==='current',faultRead=recovered?2:1;
    const counts={context:0,register:0,me:0,csrf:0,patch:0},commands=[],events=[],observations={};
    const viewer={id:'00000000-0000-4000-8000-000000000003',email:'synthetic-p03@example.test'};
    let account=state(),ad=state(),purposeResponses=0,purposeFault=false,pendingReady=false,releasePending;
    const gate=new Promise(resolve=>{releasePending=resolve;});
    const receipts=new Map();
    const html=`<!doctype html><meta charset="utf-8"><form method="post" action="/api/v1/auth/register" data-identity-form data-registration-r1 data-unknown="${local.r1_unknown}" data-confirmed="${local.r1_confirmed}"><input name="email" value="synthetic-p03@example.test"><input type="password" value="SyntheticOnly123"><fieldset data-registration-purposes>${['account_link','advertising'].map(k=>`<label><input type="checkbox" disabled data-registration-purpose="${k}">${text[k+'_name']}</label><p data-purpose-state="${k}"></p><button type="button" hidden data-purpose-retry="${k}">Retry</button><button type="button" hidden data-purpose-latest="${k}">Latest</button><p data-purpose-detail="${k}"></p>`).join('')}<p data-purpose-site></p><p data-purpose-legacy></p></fieldset><script type="application/json" data-purpose-messages>${JSON.stringify(text)}</script><button type="submit">Create</button><p data-result></p><button type="button" hidden data-registration-check>Check</button><a hidden data-registration-continue href="/identity/account">Continue</a></form>`;
    const output=k=>p.locator(`[data-purpose-state="${k}"]`),retry=k=>p.locator(`[data-purpose-retry="${k}"]`),global=p.locator('[data-result]');
    const snapshot=async()=>({global:await global.textContent(),accountStatus:await output('account_link').getAttribute('data-status'),advertisingStatus:await output('advertising').getAttribute('data-status'),purposeResponses,counts:{...counts}});
    try {
      await p.route('**/*',async route=>{
        const request=route.request(),url=new URL(request.url());
        const json=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
        if(url.pathname==='/fixture')return route.fulfill({contentType:'text/html; charset=utf-8',body:html});
        if(url.pathname==='/api/v3/measurement/purposes/context'){counts.context++;events.push('context:200');return json({schema_version:3,policy_version:'registration-purpose-r1',notice_version:'c-purpose-choice-only-r1-v1',is_test:true,site_session:{choice:'deny',effective:'off'},purposes:{account_link:account,advertising:ad},legacy_advertising:{choice:'unknown'}});}
        if(url.pathname==='/api/v1/auth/register'){counts.register++;events.push(recovered?'register:connection-reset':'register:200');if(recovered)return route.abort('connectionreset');return json({identity:{viewer},redirect_to:'/identity/account'});}
        if(url.pathname==='/api/v1/auth/csrf'){counts.csrf++;events.push('csrf:200');return json({csrf_token:'synthetic-p03-token'});}
        if(url.pathname==='/api/v1/auth/me'){
          const index=++counts.me;
          if(index===faultRead){observations.beforeInitialFault=await global.textContent();events.push(`me:${index}:503`);return json({error:{code:'DEPENDENCY_NOT_READY'}},503);}
          if(scenario.kind==='continuous-and-purpose-failure'&&index===faultRead+1){events.push(`me:${index}:503`);return json({},503);}
          if(['denied-late-success','changed-late-catch','unrelated-global-error'].includes(scenario.kind)&&index===faultRead+1){
            pendingReady=true;events.push(`me:${index}:held`);await gate;
            if(['changed-late-catch','unrelated-global-error'].includes(scenario.kind)){events.push(`me:${index}:connection-reset`);return route.abort('connectionreset');}
            events.push(`me:${index}:200-A-late`);return json({viewer});
          }
          if(scenario.kind==='changed-late-catch'&&index===faultRead+2){events.push(`me:${index}:200-B`);return json({viewer:{id:'00000000-0000-4000-8000-000000000004',email:'other-p03@example.test'}});}
          events.push(`me:${index}:200-A`);return json({viewer});
        }
        if(url.pathname==='/api/v3/measurement/purposes/preferences'){
          counts.patch++;const command=request.postDataJSON();commands.push(command);
          expect(Object.keys(command).sort()).toEqual(['choice','expected_revision','notice_version','operation_id','purpose']);
          expect(request.headers()['x-csrf-token']).toBe('synthetic-p03-token');
          expect(events.at(-1)).toMatch(/^me:\d+:200-A$/); // Fixed token -> current matching identity -> this write, no extra lookup.
          if(counts.patch===1)observations.firstMatchingBeforePurposeResponse=await snapshot();
          if(scenario.kind==='denied-late-success'){events.push('patch:advertising:403');purposeResponses++;return json({},403);}
          if(scenario.kind==='continuous-and-purpose-failure'&&command.purpose==='account_link'&&!purposeFault){purposeFault=true;events.push('patch:account_link:503');purposeResponses++;return json({},503);}
          if(!receipts.has(command.operation_id)){const next=state(command.choice,command.expected_revision+1);receipts.set(command.operation_id,next);if(command.purpose==='account_link')account=next;else ad=next;}
          events.push(`patch:${command.purpose}:200`);purposeResponses++;
          return json({schema_version:3,purpose:command.purpose,status:'saved',state:receipts.get(command.operation_id)});
        }
        throw new Error('Unexpected intercepted path: '+url.pathname);
      });
      await p.goto('http://127.0.0.1:18604/fixture');await p.addScriptTag({content:source});
      await expect(p.locator('[data-registration-purpose="account_link"]')).toBeEnabled();
      await p.locator('[data-registration-purpose="account_link"]').check();await p.locator('[data-registration-purpose="advertising"]').check();
      await p.getByRole('button',{name:'Create',exact:true}).click();
      await expect(output('account_link')).toHaveAttribute('data-status','unconfirmed');await expect(output('advertising')).toHaveAttribute('data-status','unconfirmed');
      observations.paused=await snapshot();expect(observations.beforeInitialFault).toBe(expectedSummary);expect(observations.paused.global).toBe(text.account_unknown);expect(counts.patch).toBe(0);expect(counts.csrf).toBe(0);
      if(scenario.kind==='recover'){
        await retry('account_link').click();await expect(output('account_link')).toHaveAttribute('data-status','saved');
        observations.afterFirst=await snapshot();
        expect(observations.firstMatchingBeforePurposeResponse.global).toBe(expectedSummary);expect(observations.firstMatchingBeforePurposeResponse.purposeResponses).toBe(0);
        expect(observations.firstMatchingBeforePurposeResponse.accountStatus).toBe('unconfirmed');expect(observations.firstMatchingBeforePurposeResponse.advertisingStatus).toBe('unconfirmed');
        expect(observations.afterFirst.global).toBe(expectedSummary);expect(observations.afterFirst.advertisingStatus).toBe('unconfirmed');
        await retry('advertising').click();await expect(output('advertising')).toHaveAttribute('data-status','saved');
        expect(await global.textContent()).toBe(expectedSummary);
        expect(counts).toEqual({context:1,register:1,me:recovered?4:3,csrf:2,patch:2});expect(commands.map(v=>v.purpose)).toEqual(['account_link','advertising']);
      }else if(scenario.kind==='continuous-and-purpose-failure'){
        await retry('account_link').click();await expect(retry('account_link')).toBeEnabled();
        expect(counts.me).toBe(2);expect(counts.patch).toBe(0);expect(await global.textContent()).toBe(text.account_unknown);observations.continuouslyUnknown=await snapshot();
        await retry('account_link').click();await expect(output('account_link')).toContainText(text.unconfirmed);await expect(retry('account_link')).toBeEnabled();
        expect(counts.patch).toBe(1);expect(observations.firstMatchingBeforePurposeResponse.global).toBe(expectedSummary);expect(await global.textContent()).toBe(expectedSummary);observations.identityRecoveredPurposeFailed=await snapshot();
        const firstCommand={...commands[0]};
        await retry('advertising').click();await expect(output('advertising')).toHaveAttribute('data-status','saved');
        expect(await global.textContent()).toBe(expectedSummary);expect(await output('account_link').getAttribute('data-status')).toBe('unconfirmed');
        await retry('account_link').click();await expect(output('account_link')).toHaveAttribute('data-status','saved');
        expect(commands[2]).toEqual(firstCommand);expect(commands.map(v=>v.purpose)).toEqual(['account_link','advertising','account_link']);
        expect(counts).toEqual({context:1,register:1,me:5,csrf:4,patch:3});expect(await global.textContent()).toBe(expectedSummary);
      }else{
        await retry('account_link').click();await expect.poll(()=>pendingReady).toBe(true);
        if(scenario.kind==='unrelated-global-error'){
          const marker='fixture: unrelated global error';await global.evaluate((node,value)=>{node.textContent=value;},marker);releasePending();
          await expect(retry('account_link')).toBeEnabled();expect(counts.patch).toBe(0);expect(await global.textContent()).toBe(marker);observations.afterUnrelatedErrorCatch=await snapshot();
          await retry('account_link').click();await expect(output('account_link')).toHaveAttribute('data-status','saved');expect(await global.textContent()).toBe(marker);
          await retry('advertising').click();await expect(output('advertising')).toHaveAttribute('data-status','saved');expect(await global.textContent()).toBe(marker);
          expect(counts).toEqual({context:1,register:1,me:5,csrf:3,patch:2});
        }else{
          await retry('advertising').click();
          const terminal=scenario.kind==='changed-late-catch'?text.account_changed:text.account_unknown;
          await expect(output('advertising')).toHaveAttribute('data-status','not_requested');expect(await global.textContent()).toBe(terminal);observations.terminatedBeforeLateResponse=await snapshot();
          releasePending();await expect(retry('account_link')).toBeEnabled();
          expect(await global.textContent()).toBe(terminal);expect(await output('account_link').getAttribute('data-status')).toBe('not_requested');
          const before={...counts};await retry('advertising').evaluate(button=>button.click());expect(counts).toEqual(before);
          expect(counts.patch).toBe(scenario.kind==='changed-late-catch'?0:1);expect(receipts.size).toBe(0);observations.terminatedAfterLateResponse=await snapshot();
        }
      }
      expect(counts.register).toBe(1);expect(account.effective).toBe(false);expect(ad.effective).toBe(false);
      if(!['changed-late-catch','denied-late-success'].includes(scenario.kind)){
        expect(receipts.size).toBe(2);expect(new Set(commands.map(command=>command.operation_id)).size).toBe(2);
        expect(commands.every(command=>command.choice==='allow'&&command.expected_revision===0&&command.notice_version==='c-purpose-choice-only-r1-v1')).toBe(true);
        if(recovered&&scenario.kind==='recover')expect(await global.textContent()).not.toBe(text.created);
      }
      await p.locator('form').evaluate(form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));expect(counts.register).toBe(1);
      observations.final=await snapshot();outcomes.push({...scenario,status:'passed',counts,events,observations});
    }catch(error){outcomes.push({...scenario,status:'failed',counts,events,observations,error:error.message});}
    finally{releasePending();await c.close();}
  }
  const passed=outcomes.every(row=>row.status==='passed');console.log(JSON.stringify({status:passed?'passed':'failed',identityScriptSha256:createHash('sha256').update(source).digest('hex'),boundary:'8 whole-script flows; real complete translations; every HTTP request intercepted, synthetic memory only. Controlled late responses and one fixture-only unrelated UI marker, not actual sites or a broad race/security audit.',outcomes},null,2));if(!passed)process.exitCode=1;
  }finally{await browser.close();}
})().catch(error=>{console.error(error.message);process.exitCode=1});
