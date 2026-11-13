'use strict';
// Real Chrome + actual analytics.js/privacy markup; explicit in-memory HTTP/session double only.
// Regression: controlled ad-only watch confirmation; ordinary analytics/landing_view scope stays unchanged.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {chromium} = require(process.env.H5_PLAYWRIGHT_MODULE || '/Users/hejunxiang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.resolve(__dirname, '../../../../..');
const origin = 'https://promotion-watch-client.test';
const drama = '00000000-0000-4000-8000-000000000001', episode = '00000000-0000-4000-8000-000000000002';
const dramaPath = '/dramas/'+drama, watchPath = dramaPath+'/episodes/'+episode;
const scriptPath = path.resolve(process.env.H5_ANALYTICS_SCRIPT || path.join(root, 'h5-platform/public/audience/analytics.js'));
const script = fs.readFileSync(scriptPath);
// Preserve the existing control/selector structure; translated strings and HMAC are explicit fixture text.
const privacy = fs.readFileSync(path.join(root, 'h5-platform/resources/views/audience/partials/tracking.blade.php'), 'utf8')
  .replace(/@(?:endonce|once)/g, '').replace(/\{\{[\s\S]*?\}\}/g, 'Explicit fixture');
const state = {enabled: true, policy_version: 'analytics-v1', advertising_test_only: true, analytics_available: false,
  pending_landing: false, consent: {analytics: 'unknown', advertising: 'unknown'}};
const seen = {navigation: [], consent: [], landing: [], events: [], external: [], errors: []};
let functionalProof = false;
(async () => {
  const browser = await chromium.launch({executablePath: process.env.H5_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true});
  try {
    const context = await browser.newContext();
    await context.route('**/*', async route => {
      const r = route.request(), u = new URL(r.url());
      if (u.origin !== origin) {seen.external.push(u.origin); return route.abort();}
      const json = body => route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify(body)});
      if (u.pathname === '/r/explicit-synthetic-link') {
        seen.navigation.push('/r'); state.pending_landing = true;
        // Browser navigation double only; actual PHP redirect/session is a separate HTTP-chain test.
        return route.fulfill({contentType: 'text/html', body: '<script>location.replace('+JSON.stringify(dramaPath)+')</script>'});
      }
      if (u.pathname === '/api/v1/tracking/context') return json(state);
      if (u.pathname === '/api/v1/tracking/consent') {
        const p = r.postDataJSON(); seen.consent.push(p);
        state.consent = {analytics: p.analytics ? 'granted' : 'denied', advertising: p.advertising ? 'granted' : 'denied'};
        return json(state);
      }
      if (u.pathname === '/api/v1/tracking/landing') {
        const p = r.postDataJSON(); seen.landing.push(p);
        const confirmed = functionalProof && state.pending_landing && state.consent.advertising === 'granted' && p.drama_id === drama && p.path === watchPath;
        if (confirmed) state.pending_landing = false;
        return json({confirmed});
      }
      if (u.pathname === '/api/v1/tracking/events') {
        const p = r.postDataJSON(); seen.events.push(p);
        return json({accepted: p.events.length, rejected: 0});
      }
      if (u.pathname === '/audience/analytics.js') return route.fulfill({contentType: 'text/javascript', body: script});
      if (u.pathname === '/audience/analytics.css') return route.fulfill({contentType: 'text/css', body: ''});
      if (u.pathname === dramaPath || u.pathname === watchPath) {
        seen.navigation.push(u.pathname === dramaPath ? 'drama' : 'watch');
        if (u.pathname === dramaPath) functionalProof = true; // Explicit server proof double, NOT actual PHP evidence.
        return route.fulfill({contentType: 'text/html', body: '<!doctype html><html><head><meta name="csrf-token" content="EXPLICIT_FAKE_CSRF"></head><body><a data-watch href="'+watchPath+'">Watch</a>'+privacy+'</body></html>'});
      }
      return route.fulfill({status: 404, body: ''});
    });
    const page = await context.newPage();
    page.on('pageerror', e => seen.errors.push(e.message));
    await page.goto(origin+'/r/explicit-synthetic-link');
    await page.locator('[data-privacy-dialog]').waitFor({state: 'visible'});
    assert.equal(seen.landing.length, 0);
    await page.locator('[data-privacy-close]').click();
    await page.locator('[data-watch]').click();
    await page.locator('[data-privacy-dialog]').waitFor({state: 'visible'});
    assert.equal(new URL(page.url()).pathname, watchPath);
    assert.equal(functionalProof, true);
    await page.locator('input[name=advertising]').check();
    await page.locator('[data-privacy-form] button[type=submit]').click();
    await page.locator('[data-privacy-status][data-state=saved]').waitFor({state: 'attached'});
    // applyContext confirmation runs immediately after the consent response; wait only for that bounded interaction.
    await page.waitForTimeout(250);
    assert.deepEqual(seen.navigation, ['/r', 'drama', 'watch']);
    assert.deepEqual(seen.consent, [{analytics: false, advertising: true, policy_version: 'analytics-v1'}]);
    assert.equal(seen.events.length, 0, 'ad-only must not expand analytics/landing_view');
    assert.deepEqual(seen.errors, []);
    assert.deepEqual(seen.external, []);
    assert.equal(seen.landing.length, 1, 'same-page ad consent on watch must request confirmation; server must validate existing drama GET proof');
    assert.equal(seen.landing[0].path, watchPath);
    assert.equal(seen.landing[0].drama_id, drama);
    const payload = JSON.parse(JSON.stringify(seen.landing[0]));
    assert.deepEqual(Object.keys(payload).sort(), ['drama_id', 'page_instance_id', 'path']);
    assert.match(payload.page_instance_id, /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i);
    await page.evaluate(() => {window.dispatchEvent(new Event('online')); document.dispatchEvent(new Event('visibilitychange'));});
    await page.waitForTimeout(250);
    assert.equal(seen.landing.length, 1, 'foreground repeats must not confirm again');
    assert.deepEqual(seen.landing[0], payload, 'the accepted page_instance/drama/watch payload stays stable');
    await page.locator('[data-privacy-open]').click();
    await page.locator('[data-privacy-withdraw]').click();
    await page.locator('[data-privacy-status][data-state=saved]').waitFor({state: 'attached'});
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await page.waitForTimeout(250);
    assert.equal(seen.landing.length, 1, 'withdrawal must not emit a new landing');
    assert.deepEqual(seen.consent[1], {analytics: false, advertising: false, policy_version: 'analytics-v1'});
    // Control: ordinary (non ad-test-only) watch behavior must retain the old no-confirm rule.
    delete state.advertising_test_only; delete state.analytics_available;
    state.pending_landing = true; state.consent = {analytics: 'denied', advertising: 'granted'};
    await page.goto(origin+watchPath);
    await page.waitForFunction(() => !!window.H5AudienceAnalytics);
    await page.waitForTimeout(250);
    assert.equal(seen.landing.length, 1, 'ordinary watch must not gain landing confirmation');
    assert.equal(seen.events.length, 0);
    assert.deepEqual(seen.external, []); assert.deepEqual(seen.errors, []);
    console.log('PASS: /r -> drama -> watch -> ad save confirms once with stable payload; repeats/withdrawal/ordinary watch do not confirm; analytics/external/errors 0 (Chrome/HTTP doubles).');
  } finally {
    console.log(JSON.stringify({fixture: 'Chrome + actual shared client; explicit HTTP double', functionalProof, observations: seen}));
    await browser.close();
  }
})().catch(e => {console.error(e); process.exitCode = 1;});
