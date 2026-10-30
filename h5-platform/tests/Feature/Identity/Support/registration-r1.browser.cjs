// Isolated browser fixtures for the actual client script. Every request is intercepted;
// no running application, account, real credential or network provider is used.
const fs = require('node:fs');
const path = require('node:path');
const {chromium, expect} = require(process.env.H5_PLAYWRIGHT_TEST || '/Users/hejunxiang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/test');
const script = fs.readFileSync(path.resolve(__dirname, '../../../../public/identity.js'), 'utf8');
const outcomes = [];
(async () => {
  const browser = await chromium.launch({executablePath: process.env.H5_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true});
  try {
    for (const scenario of ['lost-response-anonymous', 'lost-response-authenticated', 'malformed-response', 'validation-retry', 'known-success', 'legacy-network-failure']) {
      const context = await browser.newContext();
      const page = await context.newPage();
      let posts = 0, checks = 0, target = '';
      const r1 = scenario !== 'legacy-network-failure';
      const html = `<form method="post" action="/api/v1/auth/register" data-identity-form ${r1 ? 'data-registration-r1 data-unknown="Result unconfirmed; do not register again" data-confirmed="Signed in"' : ''}>
        <input name="email" value="synthetic@example.test"><input type="password" value="OnlySynthetic123"><button type="submit">Create</button><p role="status" data-result></p>
        ${r1 ? '<button type="button" data-registration-check hidden>Check account status</button>' : ''}</form>`;
      await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname === '/fixture') return route.fulfill({contentType:'text/html',body:html});
        if (url.pathname === '/api/v1/auth/register') {
          posts++;
          if (scenario === 'validation-retry' && posts === 1) return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({error:{message:'Validation failed'}})});
          if (scenario === 'known-success' || scenario === 'validation-retry') return route.fulfill({contentType:'application/json',body:JSON.stringify({redirect_to:'/identity/account'})});
          if (scenario === 'malformed-response') return route.fulfill({contentType:'text/html',body:'incomplete transport response'});
          return route.abort('connectionreset');
        }
        if (url.pathname === '/api/v1/auth/me') {
          checks++;
          return route.fulfill({contentType:'application/json',body:JSON.stringify({viewer:scenario === 'lost-response-authenticated' ? {id:'synthetic',email:'synthetic@example.test'} : null})});
        }
        if (url.pathname === '/identity/account') { target = url.pathname; return route.fulfill({contentType:'text/html',body:'Account page'}); }
        return route.abort();
      });
      await page.goto('http://identity.invalid/fixture');
      await page.addScriptTag({content:script});
      await page.getByRole('button',{name:'Create',exact:true}).click();
      if (scenario === 'known-success' || scenario === 'lost-response-authenticated') {
        await page.waitForURL('**/identity/account');
        expect(target).toBe('/identity/account'); expect(posts).toBe(1);
        if (scenario === 'lost-response-authenticated') expect(checks).toBe(1);
      } else if (scenario === 'validation-retry') {
        await expect(page.getByRole('status')).toHaveText('Validation failed');
        await expect(page.getByRole('button',{name:'Create',exact:true})).toBeEnabled();
        await page.getByRole('button',{name:'Create',exact:true}).click(); await page.waitForURL('**/identity/account'); expect(posts).toBe(2);
      } else if (scenario === 'legacy-network-failure') {
        await expect(page.getByRole('button',{name:'Create',exact:true})).toBeEnabled(); expect(checks).toBe(0);
      } else {
        await expect(page.getByRole('status')).toHaveText('Result unconfirmed; do not register again');
        await expect(page.getByRole('button',{name:'Create',exact:true})).toBeDisabled();
        await expect(page.locator('input[type=password]')).toHaveValue('');
        await expect.poll(()=>checks).toBe(1);
        await page.getByRole('button',{name:'Check account status',exact:true}).click(); await expect.poll(()=>checks).toBe(2);
        // Even a synthetic duplicate submit performs only another safe identity read.
        await page.locator('form').evaluate(form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
        await expect.poll(()=>checks).toBe(3); expect(posts).toBe(1);
      }
      outcomes.push({scenario,status:'passed',registrationPosts:posts,identityReads:checks});
      await context.close();
    }
    console.log(JSON.stringify({status:'passed',boundary:'intercepted client fault fixtures only; no real service or full R1 integration',outcomes},null,2));
  } finally { await browser.close(); }
})().catch(error=>{console.error(error.message);process.exit(1)});
