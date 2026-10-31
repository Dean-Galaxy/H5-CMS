// R1-P02: public source/full translations only. No real site, state, database or provider.
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {createHash} = require('node:crypto');
const {chromium, expect} = require(process.env.H5_PLAYWRIGHT_TEST || '/Users/hejunxiang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/test');
const app = path.resolve(__dirname, '../../../..');
const source = fs.readFileSync(process.env.H5_IDENTITY_SCRIPT || path.join(app, 'public/identity.js'), 'utf8');
const translations = Object.fromEntries(['en', 'zh_CN'].map(locale => [locale, JSON.parse(execFileSync(
  process.env.H5_PHP_BIN || '/opt/homebrew/opt/php@8.4/bin/php', ['-r', 'echo json_encode(require $argv[1]);', path.join(app, `lang/${locale}/identity.php`)], {encoding:'utf8'},
))]));
const validStateSource = source.slice(source.indexOf('  const validState ='), source.indexOf('  async function http('));
const renderSource = source.slice(source.indexOf('  function render(item) {'), source.indexOf('  function discard(message) {'));
if (!validStateSource || !renderSource) throw new Error('Expected source helpers not found');
const state = (choice, revision = 2) => ({choice, revision, effective:false, reason:'ASSOCIATION_NOT_READY', capability:'choice_only', choice_scope:'current_browser'});
function firstLine(copy, locale, prior) {
  return `${copy.account_link_name}: ` + (prior === null ? (locale === 'en' ? 'Saved choice unconfirmed' : '已保存选择未确认')
    : `${locale === 'en' ? 'Last confirmed choice' : '上次确认的选择'}: ${copy[prior]}`);
}
(async () => {
  const browser = await chromium.launch({executablePath:process.env.H5_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless:true});
  const outcomes = [];
  try {
    for (const locale of ['en', 'zh_CN']) {
      const copy = translations[locale].r1_purpose_text;
      const context = await browser.newContext();
      const page = await context.newPage();
      let apiRequests = 0;
      await page.route('**/*', route => {
        if (new URL(route.request().url()).pathname !== '/fixture') { apiRequests++; return route.abort(); }
        return route.fulfill({contentType:'text/html; charset=utf-8', body:'<!doctype html><meta charset="utf-8"><main></main>'});
      });
      await page.goto('http://127.0.0.1:18603/fixture');
      // Execute the verbatim product render/validator helpers, without exporting or changing production code.
      // Null/matching prior choices are presentation snapshots; do not create impossible dirty API flows.
      for (const prior of ['unknown', 'allow', 'deny', null]) for (const desired of ['allow', 'deny']) {
        try {
          const text = await page.evaluate(({copy, validStateSource, renderSource, prior, desired}) => {
            const render = new Function('text', 'complete', 'stopped', `${validStateSource}\n${renderSource}\nreturn render;`)(copy, true, false);
            const node = tag => document.createElement(tag);
            const item = {title:copy.account_link_name, state:prior === null ? null : {choice:prior, revision:2, effective:false, reason:'ASSOCIATION_NOT_READY', capability:'choice_only', choice_scope:'current_browser'},
              desired, dirty:true, status:'unconfirmed', busy:false, retryAfter:0, problem:null, http:null,
              output:node('p'), retry:node('button'), latest:node('button'), detail:node('p'), input:node('input')};
            render(item);
            return item.output.textContent;
          }, {copy, validStateSource, renderSource, prior, desired});
          expect(text.split('\n')[0]).toBe(firstLine(copy, locale, prior));
          expect(text).toContain(copy.off); expect(text).toContain(copy.unconfirmed);
          expect(text).toContain(`${copy.draft}: ${locale === 'en' ? (desired === 'allow' ? 'Allow' : 'Deny') : (desired === 'allow' ? '允许' : '拒绝')}`);
          outcomes.push({kind:'verbatim-render', locale, prior, desired, status:'passed', text});
        } catch (error) { outcomes.push({kind:'verbatim-render', locale, prior, desired, status:'failed', error:error.message}); }
      }
      for (const status of ['saved', 'unchanged']) for (const choice of ['allow', 'deny']) {
        try {
          const text = await page.evaluate(({copy, validStateSource, renderSource, status, choice}) => {
            const render = new Function('text', 'complete', 'stopped', `${validStateSource}\n${renderSource}\nreturn render;`)(copy, true, false);
            const node = tag => document.createElement(tag);
            const item = {title:copy.account_link_name, state:{choice, revision:2, effective:false, reason:'ASSOCIATION_NOT_READY', capability:'choice_only', choice_scope:'current_browser'},
              desired:null, dirty:false, status, busy:false, retryAfter:0, problem:null, http:null,
              output:node('p'), retry:node('button'), latest:node('button'), detail:node('p'), input:node('input')};
            render(item); return item.output.textContent;
          }, {copy, validStateSource, renderSource, status, choice});
          expect(text).toBe(`${copy.account_link_name}: ${copy[choice]}\n${copy.off} — ${copy[status]}`);
          outcomes.push({kind:'normal-render', locale, choice, status:'passed', responseStatus:status, text});
        } catch (error) { outcomes.push({kind:'normal-render', locale, choice, responseStatus:status, status:'failed', error:error.message}); }
      }
      expect(apiRequests).toBe(0);
      await context.close();
      // Valid dirty flows: unknown->allow/deny, allow->deny, deny->allow. Full actual identity.js.
      for (const [prior, desired] of [['unknown','allow'], ['unknown','deny'], ['allow','deny'], ['deny','allow']]) {
        const context = await browser.newContext(), page = await context.newPage();
        const commands = [], order = [], observations = {};
        const counts = {context:0, register:0, me:0, csrf:0, patch:0};
        const viewer = {id:'synthetic-p02-viewer', email:'synthetic-p02@example.test'};
        let account = state(prior), advertising = state('unknown'), broken = false;
        const operations = new Map();
        const html = `<!doctype html><meta charset="utf-8"><form method="post" action="/api/v1/auth/register" data-identity-form data-registration-r1 data-unknown="${translations[locale].r1_unknown}" data-confirmed="${translations[locale].r1_confirmed}">
          <input name="email" value="synthetic-p02@example.test"><input type="password" value="SyntheticOnly123">
          <fieldset data-registration-purposes>${['account_link','advertising'].map(p => `<label><input type="checkbox" disabled data-registration-purpose="${p}">${copy[p+'_name']}</label><p data-purpose-state="${p}"></p><button type="button" hidden data-purpose-retry="${p}">Retry</button><button type="button" hidden data-purpose-latest="${p}">Latest</button><p data-purpose-detail="${p}"></p>`).join('')}<p data-purpose-site></p><p data-purpose-legacy></p></fieldset>
          <script type="application/json" data-purpose-messages>${JSON.stringify(copy)}</script><button type="submit">Create</button><p data-result></p><button type="button" hidden data-registration-check>Check</button><a hidden data-registration-continue href="/identity/account">Continue</a></form>`;
        try {
          await page.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            const json = body => route.fulfill({contentType:'application/json', body:JSON.stringify(body)});
            if (url.pathname === '/fixture') return route.fulfill({contentType:'text/html; charset=utf-8', body:html});
            if (url.pathname === '/api/v3/measurement/purposes/context') { counts.context++; return json({schema_version:3, policy_version:'registration-purpose-r1', notice_version:'c-purpose-choice-only-r1-v1', is_test:true,
              site_session:{choice:'deny', effective:'off'}, purposes:{account_link:account, advertising}, legacy_advertising:{choice:'unknown'}}); }
            if (url.pathname === '/api/v1/auth/register') { counts.register++; return json({identity:{viewer}, redirect_to:'/identity/account'}); }
            if (url.pathname === '/api/v1/auth/csrf') { counts.csrf++; order.push('csrf'); return json({csrf_token:'synthetic-p02-token'}); }
            if (url.pathname === '/api/v1/auth/me') { counts.me++; order.push('me'); return json({viewer}); }
            if (url.pathname === '/api/v3/measurement/purposes/preferences') {
              counts.patch++; expect(order.slice(-2)).toEqual(['csrf','me']); order.push('patch');
              expect(request.headers()['x-csrf-token']).toBe('synthetic-p02-token');
              const command = request.postDataJSON(); commands.push(command);
              expect(Object.keys(command).sort()).toEqual(['choice','expected_revision','notice_version','operation_id','purpose']);
              if (!operations.has(command.operation_id)) {
                const next = state(command.choice, command.expected_revision + 1); operations.set(command.operation_id, next);
                if (command.purpose === 'account_link') account = next; else advertising = next;
              }
              if (command.purpose === 'account_link' && !broken) { broken = true; return route.fulfill({contentType:'application/json', body:'{'}); }
              return json({schema_version:3, purpose:command.purpose, status:'saved', state:operations.get(command.operation_id)});
            }
            throw new Error('Unexpected fixture path: '+url.pathname);
          });
          await page.goto('http://127.0.0.1:18603/fixture'); await page.addScriptTag({content:source});
          const input = page.locator('[data-registration-purpose="account_link"]');
          const output = page.locator('[data-purpose-state="account_link"]');
          await expect(input).toBeEnabled();
          if (desired === 'allow') await input.check(); else { if (prior !== 'allow') await input.check(); await input.uncheck(); }
          await page.locator('[data-registration-purpose="advertising"]').check();
          await page.getByRole('button', {name:'Create', exact:true}).click();
          await expect(output).toHaveAttribute('data-status','unconfirmed');
          await expect(page.locator('[data-purpose-state="advertising"]')).toHaveAttribute('data-status','saved');
          observations.unconfirmed = await output.textContent();
          expect(observations.unconfirmed.split('\n')[0]).toBe(firstLine(copy, locale, prior));
          expect(observations.unconfirmed).toContain(copy.unconfirmed); expect(observations.unconfirmed).toContain(copy.off);
          expect(observations.unconfirmed).toContain(`${copy.draft}: ${locale === 'en' ? (desired === 'allow' ? 'Allow' : 'Deny') : (desired === 'allow' ? '允许' : '拒绝')}`);
          expect(counts).toEqual({context:1, register:1, me:3, csrf:2, patch:2});
          const original = {...commands[0]};
          await page.locator('[data-purpose-retry="account_link"]').click();
          await expect(output).toHaveAttribute('data-status','saved');
          observations.saved = await output.textContent();
          expect(observations.saved).toBe(`${copy.account_link_name}: ${copy[desired]}\n${copy.off} — ${copy.saved}`);
          expect(commands[2]).toEqual(original); expect(commands.map(c=>c.purpose)).toEqual(['account_link','advertising','account_link']);
          expect(counts).toEqual({context:1, register:1, me:4, csrf:3, patch:3}); expect(operations.size).toBe(2);
          expect(account.effective).toBe(false); expect(advertising.effective).toBe(false);
          await page.locator('form').evaluate(form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
          expect(counts.register).toBe(1); expect(counts.patch).toBe(3);
          outcomes.push({kind:'full-script-http', locale, prior, desired, status:'passed', counts, syntheticLogicalWrites:operations.size, sameOperation:true, observations});
        } catch (error) { outcomes.push({kind:'full-script-http', locale, prior, desired, status:'failed', counts, observations, error:error.message}); }
        finally { await context.close(); }
      }
    }
    const passed = outcomes.every(o=>o.status==='passed');
    console.log(JSON.stringify({status:passed?'passed':'failed', identityScriptSha256:createHash('sha256').update(source).digest('hex'),
      boundary:'Public real JS/full translations only: 16 verbatim render snapshots including null, 8 unchanged/saved render checks, 8 full-script intercepted HTTP flows. No actual site or database.', outcomes}, null, 2));
    if (!passed) process.exitCode = 1;
  } finally { await browser.close(); }
})().catch(error=>{console.error(error.message);process.exitCode=1});
