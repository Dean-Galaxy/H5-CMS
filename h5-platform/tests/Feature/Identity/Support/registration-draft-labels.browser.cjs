// R1-P01: real identity.js and complete real translations; every HTTP request is intercepted.
// No Laravel application, database, running site or real account/provider is contacted.
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium, expect} = require(process.env.H5_PLAYWRIGHT_TEST || '/Users/hejunxiang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/test');
const app = path.resolve(__dirname, '../../../..');
const source = fs.readFileSync(process.env.H5_IDENTITY_SCRIPT || path.join(app, 'public/identity.js'), 'utf8');
const css = fs.readFileSync(path.join(app, 'public/identity.css'), 'utf8');
const translations = Object.fromEntries(['en', 'zh_CN'].map(locale => [locale, JSON.parse(execFileSync(
  process.env.H5_PHP_BIN || '/opt/homebrew/opt/php@8.4/bin/php',
  ['-r', 'echo json_encode(require $argv[1]);', path.join(app, `lang/${locale}/identity.php`)], {encoding:'utf8'},
))]));

(async () => {
  const browser = await chromium.launch({executablePath:process.env.H5_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless:true});
  const outcomes = [];
  try {
    for (const locale of ['en', 'zh_CN']) for (const desired of ['allow', 'deny']) {
      const context = await browser.newContext({viewport:{width:390, height:844}});
      const page = await context.newPage();
      const copy = translations[locale].r1_purpose_text;
      const expectedIntent = locale === 'en' ? (desired === 'allow' ? 'Allow' : 'Deny') : (desired === 'allow' ? '允许' : '拒绝');
      const commands = [], order = [], observations = {};
      let registrations = 0, advertisingCalls = 0;
      const state = (choice = 'unknown', revision = 0) => ({choice, revision, effective:false, reason:'ASSOCIATION_NOT_READY', capability:'choice_only', choice_scope:'current_browser'});
      let account = state(), advertising = state(desired === 'deny' ? 'allow' : 'unknown', desired === 'deny' ? 3 : 0);
      const viewer = {id:'synthetic-p01-viewer', email:'synthetic-p01@example.test'};
      const html = `<!doctype html><html lang="${locale}"><meta charset="utf-8"><style>${css}</style><section class="card identity-registration-r1">
        <form method="post" action="/api/v1/auth/register" data-identity-form data-registration-r1 data-unknown="${translations[locale].r1_unknown}" data-confirmed="${translations[locale].r1_confirmed}">
        <label>Email<input name="email" value="synthetic-p01@example.test"></label><input type="password" value="SyntheticOnly123">
        <fieldset data-registration-purposes>${['account_link', 'advertising'].map(purpose => `
          <label class="identity-check"><input type="checkbox" disabled data-registration-purpose="${purpose}"><span>${copy[purpose + '_name']}</span></label>
          <p role="status" data-purpose-state="${purpose}"></p>
          <button type="button" hidden data-purpose-retry="${purpose}">${copy.retry}</button>
          <button type="button" hidden data-purpose-latest="${purpose}">${copy.latest}</button>
          <details><summary>Technical details</summary><p data-purpose-detail="${purpose}"></p></details>`).join('')}
          <p data-purpose-site></p><p data-purpose-legacy></p>
        </fieldset><script type="application/json" data-purpose-messages>${JSON.stringify(copy).replace(/</g, '\\u003c')}</script>
        <button type="submit">Create</button><p data-result></p><button type="button" hidden data-registration-check>Check</button>
        <a hidden data-registration-continue href="/identity/account">Continue</a></form></section></html>`;
      try {
        await page.route('**/*', async route => {
          const request = route.request(), url = new URL(request.url());
          const json = (body, status = 200) => route.fulfill({status, contentType:'application/json', body:JSON.stringify(body)});
          if (url.pathname === '/fixture') return route.fulfill({contentType:'text/html; charset=utf-8', body:html});
          if (url.pathname === '/api/v3/measurement/purposes/context') return json({schema_version:3, policy_version:'registration-purpose-r1', notice_version:'c-purpose-choice-only-r1-v1', is_test:true,
            site_session:{choice:'deny', effective:'off', reason:'SITE_OPTOUT'}, purposes:{account_link:account, advertising}, legacy_advertising:{choice:'unknown'}});
          if (url.pathname === '/api/v1/auth/register') { registrations++; return json({identity:{viewer}, redirect_to:'/identity/account'}); }
          if (url.pathname === '/api/v1/auth/csrf') { order.push('csrf'); return json({csrf_token:'synthetic-p01-token'}); }
          if (url.pathname === '/api/v1/auth/me') { order.push('me'); return json({viewer}); }
          if (url.pathname === '/api/v3/measurement/purposes/preferences') {
            expect(request.method()).toBe('PATCH');
            expect(request.headers()['x-csrf-token']).toBe('synthetic-p01-token');
            expect(order.slice(-2)).toEqual(['csrf', 'me']);
            const command = request.postDataJSON(); commands.push(command); order.push('patch');
            expect(Object.keys(command).sort()).toEqual(['choice', 'expected_revision', 'notice_version', 'operation_id', 'purpose']);
            if (command.purpose === 'advertising' && ++advertisingCalls === 1) return json({}, 503);
            const saved = state(command.choice, command.expected_revision + 1);
            if (command.purpose === 'account_link') account = saved; else advertising = saved;
            return json({schema_version:3, purpose:command.purpose, status:'saved', state:saved});
          }
          throw new Error('Unexpected fixture request: ' + url.pathname);
        });
        await page.goto('http://127.0.0.1:18602/fixture'); // Every request is routed above; no listener is started.
        await page.addScriptTag({content:source});
        const input = page.locator('[data-registration-purpose="advertising"]');
        const output = page.locator('[data-purpose-state="advertising"]');
        const accountOutput = page.locator('[data-purpose-state="account_link"]');
        await expect(input).toBeEnabled();
        if (desired === 'deny') { await expect(input).toBeChecked(); await expect(output).toContainText(copy.allow); }
        await page.locator('[data-registration-purpose="account_link"]').check();
        if (desired === 'allow') await input.check(); else await input.uncheck();
        observations.beforeSubmit = await output.textContent();
        await expect(output).toContainText(`${copy.draft}: ${expectedIntent}`);
        await expect(output).not.toContainText(`${copy.draft}: ${copy[desired]}`);
        await page.getByRole('button', {name:'Create', exact:true}).click();
        await expect(output).toHaveAttribute('data-status', 'unconfirmed');
        await expect(accountOutput).toHaveAttribute('data-status', 'saved');
        await expect(accountOutput).toContainText(copy.allow); // Existing saved wording is unchanged.
        await expect(output).toContainText(`${copy.draft}: ${expectedIntent}`);
        await expect(output).toContainText(copy.unconfirmed);
        observations.partial = await output.textContent();
        expect(commands.length).toBe(2); expect(registrations).toBe(1);
        expect(commands.map(command => command.purpose)).toEqual(['account_link', 'advertising']);
        expect(commands[1].choice).toBe(desired);
        const pending = {...commands[1]};
        await page.locator('[data-purpose-retry="advertising"]').click();
        await expect(output).toHaveAttribute('data-status', 'saved');
        await expect(output).toContainText(copy[desired]);
        await expect(output).not.toContainText(`${copy.draft}:`);
        observations.saved = await output.textContent();
        expect(commands.length).toBe(3); expect(commands[2]).toEqual(pending); // Same operation/revision/notice/intent, no replay of the successful item.
        expect(advertising.effective).toBe(false); expect(account.effective).toBe(false);
        expect(advertising.choice).toBe(desired);
        await page.locator('form').evaluate(form => form.dispatchEvent(new Event('submit', {bubbles:true, cancelable:true})));
        expect(registrations).toBe(1); expect(commands.length).toBe(3);
        outcomes.push({locale, desired, status:'passed', expectedIntent, registrationPosts:registrations, purposeCommands:commands.length, operationRetained:true, observations});
      } catch (error) {
        outcomes.push({locale, desired, status:'failed', expectedIntent, registrationPosts:registrations, purposeCommands:commands.length, observations, error:error.message});
      } finally { await context.close(); }
    }
    const passed = outcomes.every(outcome => outcome.status === 'passed');
    console.log(JSON.stringify({status:passed ? 'passed' : 'failed', boundary:'Real identity.js and complete bilingual PHP translations; intercepted HTTP and in-memory synthetic states only, no database/site/provider', outcomes}, null, 2));
    if (!passed) process.exitCode = 1;
  } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
