'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {test, before, after} = require('node:test');
const {chromium} = require(process.env.H5_PLAYWRIGHT_MODULE || 'playwright');
const repo = path.resolve(__dirname, '../../../..');
const runtime = path.join(repo, '.runtime/audience');
const publicDir = path.join(repo, 'h5-platform/public');
const drama = '00000000-0000-4000-8000-000000000001', episode = '00000000-0000-4000-8000-000000000002';
const permissionId = '00000000-0000-4000-8000-000000000099';
const origin = 'https://analytics-browser.test';
let browser;
before(async () => { browser = await chromium.launch({executablePath: process.env.H5_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true}); });
after(async () => { await browser?.close(); });
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
async function eventually(predicate, timeout = 8000) {
  const deadline = Date.now() + timeout;
  while (!predicate() && Date.now() < deadline) await pause(50);
  assert.ok(predicate(), 'Expected observation before deadline');
}

async function fixture({consent = 'unknown', advertising = 'unknown', locale = 'en', media = false, hidden = false, enabled = true,
  clickQuery = '', cookieProbe = null, pendingLanding = true, landingDelay = 0, landingConfirmed = true, consentResponse = null, contextDelay = 0} = {}) {
  const ctx = await browser.newContext({viewport: {width: 390, height: 844}});
  let server = {enabled, policy_version: 'analytics-v1', consent: {analytics: consent, advertising}, pending_landing: pendingLanding};
  const observations = {posts: [], events: new Map(), landing: [], consent: [], external: [], errors: [], playback: 0, timeline: [], referrers: [], contextStarted: 0, contextFinished: 0};
  const fault = {events: 0, status: 503, consent: 0, contextDelay, contextStatus: 200, consentDelay: 0};
  let scope = 'synthetic-browser-scope', member = true;
  if (cookieProbe !== null) await ctx.addInitScript(cookieValue => {
    window.syntheticCookieReads = 0; window.syntheticCookieWrites = 0;
    Object.defineProperty(document, 'cookie', {
      configurable: true,
      get() { window.syntheticCookieReads++; return cookieValue; },
      set() { window.syntheticCookieWrites++; },
    });
  }, cookieProbe);
  if (hidden) await ctx.addInitScript(() => {
    let visible = false;
    Object.defineProperty(document, 'visibilityState', {get: () => visible ? 'visible' : 'hidden'});
    window.syntheticVisible = () => { visible = true; document.dispatchEvent(new Event('visibilitychange')); };
  });
  await ctx.route('**/*', async route => {
    const req = route.request(), url = new URL(req.url());
    if (url.origin !== origin) { observations.external.push(url.origin); return route.abort(); }
    if (url.pathname.startsWith('/api/v1/tracking/')) observations.referrers.push(req.headers().referer || '');
    const json = body => route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify(body)});
    if (url.pathname === '/api/v1/tracking/context') {
      observations.contextStarted++;
      const snapshot = JSON.parse(JSON.stringify(server)), status = fault.contextStatus;
      if (fault.contextDelay) await pause(fault.contextDelay);
      observations.contextFinished++;
      return status === 200 ? json(snapshot) : route.fulfill({status});
    }
    if (url.pathname === '/api/v1/tracking/consent') {
      const p = req.postDataJSON(); observations.consent.push(p);
      if (fault.consentDelay) await pause(fault.consentDelay);
      if (fault.consent-- > 0) return route.fulfill({status: 503});
      if (consentResponse) return json(consentResponse);
      server.consent = {analytics: p.analytics ? 'granted' : 'denied', advertising: p.advertising ? 'granted' : 'denied'};
      return json(server);
    }
    if (url.pathname === '/api/v1/tracking/landing') {
      observations.landing.push(req.postDataJSON()); observations.timeline.push('landing_started');
      assert.ok(Buffer.byteLength(req.postData()) <= 4096);
      if (landingDelay) await pause(landingDelay);
      if (landingConfirmed) server.pending_landing = false;
      observations.timeline.push(landingConfirmed ? 'landing_confirmed' : 'landing_unconfirmed');
      return json({confirmed: landingConfirmed});
    }
    if (url.pathname === '/api/v1/tracking/events') {
      const events = req.postDataJSON().events; observations.posts.push(events);
      observations.timeline.push('events');
      assert.ok(events.length <= 20 && Buffer.byteLength(req.postData()) <= 32768);
      if (fault.events-- > 0) return route.fulfill({status: fault.status});
      for (const event of events) {
        if (observations.events.has(event.event_id)) assert.deepEqual(observations.events.get(event.event_id), event);
        observations.events.set(event.event_id, event);
      }
      return json({accepted: events.length, rejected: 0});
    }
    if (url.pathname.endsWith('/playback')) {
      observations.playback++;
      return json(member ? {allowed: true, play_session_id: permissionId, short_lived_url: origin+'/synthetic.mp4', expires_at: new Date(Date.now()+3600000).toISOString()}
        : {allowed: false, reason: 'MEMBERSHIP_REQUIRED'});
    }
    if (url.pathname === '/synthetic.mp4') {
      const body = fs.readFileSync(path.join(runtime, 'analytics-test.mp4'));
      const range = req.headers().range?.match(/^bytes=(\d+)-(\d*)$/);
      if (range) {
        const from = Number(range[1]), until = range[2] ? Math.min(Number(range[2]), body.length-1) : body.length-1;
        return route.fulfill({status: 206, contentType: 'video/mp4', headers: {'Accept-Ranges': 'bytes', 'Content-Range': `bytes ${from}-${until}/${body.length}`}, body: body.subarray(from, until+1)});
      }
      return route.fulfill({status: 200, contentType: 'video/mp4', headers: {'Accept-Ranges': 'bytes'}, body});
    }
    const asset = path.join(publicDir, url.pathname);
    if (['/identity.css', '/identity.js', '/audience/site.css', '/audience/site.js', '/audience/billing.js', '/audience/analytics.js', '/audience/analytics.css', '/audience/player.js'].includes(url.pathname)) {
      return route.fulfill({status: 200, contentType: url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css', body: fs.readFileSync(asset)});
    }
    let html = fs.readFileSync(path.join(runtime, 'analytics-'+locale+'.html'), 'utf8').replace(/data-buffer-scope="[^"]+"/, 'data-buffer-scope="'+scope+'"');
    if (media) {
      html = html.replace('<main id="main">', `<main id="main"><section data-player data-drama="${drama}" data-episode="${episode}" data-duration="20" data-viewer=""><video muted playsinline controls preload="none"></video><button data-play>Play</button><p data-player-status></p><p data-progress-status></p></section>`);
      html = html.replace('</body>', '<script id="audience-messages" type="application/json">'+JSON.stringify({loading: 'Checking', playing: 'Playing', media_error: 'Media error', network: 'Network', MEMBERSHIP_REQUIRED: 'Membership required', guest_progress: 'Saved'})+'</script><script src="/audience/player.js" defer></script></body>');
    }
    return route.fulfill({status: 200, contentType: 'text/html', body: html});
  });
  const page = await ctx.newPage();
  page.on('pageerror', e => observations.errors.push(e.message));
  if (media) await page.addInitScript(() => {
    const original = HTMLMediaElement.prototype.canPlayType;
    HTMLMediaElement.prototype.canPlayType = function (type) { return /mpegurl/.test(type) ? 'probably' : original.call(this, type); };
  });
  const url = origin+'/dramas/'+drama+(media ? '/episodes/'+episode : '')+'?token=NEVER-COLLECT-ME&lang='+locale+clickQuery;
  await page.goto(url);
  await page.waitForFunction(() => !!window.H5AudienceAnalytics);
  return {ctx, page, observations, fault, server, url, setScope: value => { scope = value; }, setMember: value => { member = value; }};
}

test('hidden/denied pages emit nothing; independent advertising uses only landing API; withdrawal clears offline buffer', async () => {
  const f = await fixture({hidden: true}); const {page, observations: o} = f;
  try {
    await page.waitForTimeout(200); assert.equal(o.posts.length, 0);
    await page.evaluate(() => window.syntheticVisible());
    await page.locator('[data-privacy-dialog]').waitFor({state: 'visible'});
    await page.locator('[data-privacy-reject]').click();
    await eventually(() => o.consent.length === 1); await page.waitForTimeout(100);
    assert.deepEqual(o.consent[0], {analytics: false, advertising: false, policy_version: 'analytics-v1'});
    await page.locator('[data-privacy-open]').click();
    await page.locator('input[name=advertising]').check();
    await page.locator('[data-privacy-form] button[type=submit]').click();
    await eventually(() => o.landing.length === 1);
    assert.equal(o.posts.length, 0);
    assert.deepEqual(Object.keys(o.landing[0]).sort(), ['drama_id', 'page_instance_id', 'path']);
    await page.locator('[data-privacy-open]').click();
    await page.locator('input[name=analytics]').check();
    await page.locator('[data-privacy-form] button[type=submit]').click();
    await eventually(() => o.events.size >= 2);
    const before = o.events.size;
    await f.ctx.setOffline(true);
    await page.evaluate(() => window.H5AudienceAnalytics.emit('subscribe_click'));
    assert.ok(await page.evaluate(() => !!sessionStorage.getItem('h5.analytics.buffer.v1')));
    await page.locator('[data-privacy-open]').click();
    await page.locator('[data-privacy-withdraw]').click();
    assert.equal(await page.evaluate(() => sessionStorage.getItem('h5.analytics.buffer.v1')), null);
    await f.ctx.setOffline(false);
    await eventually(() => o.consent.length >= 4);
    await page.waitForTimeout(300);
    assert.equal(o.events.size, before);
    assert.equal(o.external.length, 0); assert.deepEqual(o.errors, []);
  } finally { await f.ctx.close(); }
});

test('503/refresh retries retain event UUID and content; session boundary and 24h expiry discard stale buffers', async () => {
  const f = await fixture({consent: 'granted'}); const {page, observations: o} = f;
  try {
    f.fault.events = 1;
    await eventually(() => o.posts.length === 1);
    const original = o.posts[0];
    await page.reload();
    await eventually(() => o.posts.length >= 2);
    for (const event of original) assert.deepEqual(o.posts[1].find(x => x.event_id === event.event_id), event);
    await page.waitForFunction(() => !sessionStorage.getItem('h5.analytics.buffer.v1'));
    assert.equal(JSON.stringify([...o.events.values()]).includes('NEVER-COLLECT-ME'), false);
    await f.ctx.setOffline(true);
    await page.evaluate(() => window.H5AudienceAnalytics.emit('subscribe_click'));
    const stale = await page.evaluate(() => {
      const key = 'h5.analytics.buffer.v1', p = JSON.parse(sessionStorage.getItem(key));
      sessionStorage.setItem('analytics-test-expire-once', '1'); return p.queue.map(x => x.event.event_id);
    });
    await page.addInitScript(() => {
      if (sessionStorage.getItem('analytics-test-expire-once') === '1') {
        const key = 'h5.analytics.buffer.v1', p = JSON.parse(sessionStorage.getItem(key));
        if (p) { p.queue.forEach(x => x.created = Date.now()-86400001); sessionStorage.setItem(key, JSON.stringify(p)); }
        sessionStorage.removeItem('analytics-test-expire-once');
      }
    });
    await page.reload(); await f.ctx.setOffline(false);
    await page.waitForTimeout(1400);
    assert.ok(stale.every(id => !o.events.has(id)));
    await f.ctx.setOffline(true);
    await page.evaluate(() => window.H5AudienceAnalytics.emit('subscribe_click'));
    const other = await page.evaluate(() => JSON.parse(sessionStorage.getItem('h5.analytics.buffer.v1')).queue.map(x => x.event.event_id));
    f.setScope('new-account-session');
    await page.reload(); await f.ctx.setOffline(false); await page.waitForTimeout(1400);
    assert.ok(other.every(id => !o.events.has(id)));
    assert.deepEqual(o.errors, []);
  } finally { await f.ctx.close(); }
});

test('actual decoded playback starts after movement, seek adds no watch time, and membership denial is observed without media credentials', async () => {
  const f = await fixture({consent: 'granted', media: true}); const {page, observations: o} = f;
  try {
    await page.waitForTimeout(1300);
    assert.equal([...o.events.values()].filter(x => x.event_name === 'play_started').length, 0);
    await page.locator('[data-play]').click();
    await page.waitForFunction(() => document.querySelector('video').currentTime > 0.5);
    await page.locator('video').evaluate(v => { v.playbackRate = 2; });
    await eventually(() => [...o.events.values()].some(x => x.event_name === 'play_started'));
    await page.waitForFunction(() => document.querySelector('video').currentTime > 17, null, {timeout: 15000});
    await page.locator('video').evaluate(v => { v.currentTime = 19; });
    await page.waitForFunction(() => document.querySelector('video').ended);
    await page.waitForTimeout(1300);
    const events = [...o.events.values()];
    assert.equal(events.filter(x => x.event_name === 'play_started').length, 1);
    assert.ok(events.some(x => x.event_name === 'player_startup'));
    const watch = events.filter(x => x.event_name === 'watch_progress');
    assert.ok(watch.length > 0 && watch.every(x => x.properties.watched_seconds <= 18), JSON.stringify(events));
    assert.equal(events.filter(x => x.event_name === 'episode_completed').length, 0);
    const quality = await page.locator('video').evaluate(v => v.getVideoPlaybackQuality().totalVideoFrames);
    assert.ok(quality > 0);
    f.setMember(false); await page.reload(); await page.locator('[data-play]').click();
    await eventually(() => [...o.events.values()].some(x => x.event_name === 'paywall_view'));
    assert.ok([...o.events.values()].some(x => x.event_name === 'player_error' && x.properties.error_code === 'membership'));
    assert.equal(JSON.stringify([...o.events.values()]).includes(permissionId), false);
    assert.equal(o.external.length, 0); assert.deepEqual(o.errors, []);
  } finally { await f.ctx.close(); }
});

test('Chinese privacy controls fit small screens; oversized/invalid batch response terminates retry', async () => {
  const f = await fixture({consent: 'granted', locale: 'zh_CN'}); const {page, observations: o} = f;
  try {
    f.fault.events = 100; f.fault.status = 422;
    await eventually(() => o.posts.length === 1); await page.waitForTimeout(2200);
    assert.equal(o.posts.length, 1);
    assert.equal(await page.evaluate(() => sessionStorage.getItem('h5.analytics.buffer.v1')), null);
    await page.locator('[data-privacy-open]').click();
    for (const width of [320, 390]) {
      await page.setViewportSize({width, height: 844});
      assert.ok(await page.getByRole('heading', {name: '隐私选择', exact: true}).isVisible());
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      await page.screenshot({path: path.join(runtime, 'analytics-privacy-'+width+'.png'), fullPage: true});
    }
    assert.deepEqual(o.errors, []);
  } finally { await f.ctx.close(); }
});

test('decoded full episode emits one completion and a cumulative progress value; duplicate ended does not double count', async () => {
  const f = await fixture({consent: 'granted', media: true});
  try {
    await f.page.locator('[data-play]').click();
    await f.page.waitForFunction(() => document.querySelector('video').currentTime > 0.5);
    await f.page.locator('video').evaluate(v => { v.playbackRate = 2; });
    await f.page.waitForFunction(() => document.querySelector('video').ended, null, {timeout: 15000});
    await f.page.locator('video').evaluate(v => { v.dispatchEvent(new Event('ended')); });
    await eventually(() => [...f.observations.events.values()].some(x => x.event_name === 'episode_completed'));
    const completions = [...f.observations.events.values()].filter(x => x.event_name === 'episode_completed');
    assert.equal(completions.length, 1);
    assert.ok(completions[0].properties.watched_seconds >= 18 && completions[0].properties.watched_seconds <= 20);
    assert.deepEqual(f.observations.errors, []);
  } finally { await f.ctx.close(); }
});

test('buffer count/bytes stay bounded and disabled analytics never blocks actual playback', async () => {
  const f = await fixture({consent: 'granted'});
  try {
    await f.ctx.setOffline(true);
    await f.page.evaluate(() => { for (let i = 0; i < 500; i++) window.H5AudienceAnalytics.emit('subscribe_click', {viewer_id: 'forbidden', token: 'secret'}); });
    const buffer = await f.page.evaluate(() => JSON.parse(sessionStorage.getItem('h5.analytics.buffer.v1')));
    assert.ok(buffer.queue.length <= 100 && Buffer.byteLength(JSON.stringify(buffer.queue)) <= 65536);
    assert.ok(buffer.queue.every(x => !('viewer_id' in x.event.properties) && !('token' in x.event.properties)));
  } finally { await f.ctx.close(); }
  const disabled = await fixture({enabled: false, media: true});
  try {
    await disabled.page.locator('[data-play]').click();
    await disabled.page.waitForFunction(() => document.querySelector('video').currentTime > 0.5);
    assert.equal(disabled.observations.posts.length, 0);
    assert.equal(disabled.observations.landing.length, 0);
    assert.ok(disabled.observations.playback > 0);
    assert.deepEqual(disabled.observations.errors, []);
  } finally { await disabled.ctx.close(); }
});

const clicks = {fbclid: 'SYNTHETIC_FB_CLICK_ABC-123', ttclid: 'E.C.P.SYNTHETIC_06-CLICK',
  fbc: 'fb.1.1770000000000.SYNTHETIC_OLD_FB_CLICK', fbp: 'fb.1.1770000000000.1234567890', ttp: 'SYNTHETIC.TTP_COOKIE-ABC123'};
const clickQuery = '&fbclid='+clicks.fbclid+'&ttclid='+clicks.ttclid+'&keep=normal#chapter';
const cookieProbe = '_fbc='+clicks.fbc+'; _fbp='+clicks.fbp+'; _ttp='+clicks.ttp+'; private_other=NEVER-READ-INTO-CONTEXT';
async function storedValues(page) {
  return page.evaluate(() => JSON.stringify({local: {...localStorage}, session: {...sessionStorage}}));
}

test('consented slow landing sends only whitelisted ephemeral click context before page events, then cleans only click parameters', async () => {
  const f = await fixture({consent: 'granted', advertising: 'granted', clickQuery, cookieProbe, landingDelay: 1700});
  try {
    await eventually(() => f.observations.landing.length === 1);
    await f.page.evaluate(({query, origin, drama}) => {
      document.querySelector('.language a').href = origin+'/dramas/'+drama+'?lang=zh_CN'+query;
      document.querySelector('[data-audience-login]').href = origin+'/identity/login?return_to='+encodeURIComponent('/dramas/'+drama+'?lang=en'+query);
    }, {query: clickQuery, origin, drama});
    await f.page.evaluate(() => window.H5AudienceAnalytics.emit('subscribe_click', {fbclid: 'DO-NOT-COPY'}));
    await f.page.waitForTimeout(1100);
    assert.equal(f.observations.posts.length, 0);
    assert.ok(f.page.url().includes('fbclid='));
    await eventually(() => f.observations.events.size > 0);
    const payload = f.observations.landing[0];
    assert.deepEqual(payload.click_context, clicks);
    assert.deepEqual(Object.keys(payload).sort(), ['click_context', 'drama_id', 'page_instance_id', 'path']);
    assert.ok(f.observations.timeline.indexOf('landing_confirmed') < f.observations.timeline.indexOf('events'));
    const current = new URL(f.page.url());
    assert.equal(current.searchParams.has('fbclid'), false); assert.equal(current.searchParams.has('ttclid'), false);
    assert.equal(current.searchParams.get('lang'), 'en'); assert.equal(current.searchParams.get('keep'), 'normal'); assert.equal(current.hash, '#chapter');
    const links = await f.page.locator('.language a, [data-audience-login]').evaluateAll(nodes => nodes.map(node => node.href));
    assert.ok(links.every(href => !decodeURIComponent(href).includes(clicks.fbclid) && !decodeURIComponent(href).includes(clicks.ttclid)));
    assert.equal(new URL(links.find(href => new URL(href).pathname.startsWith('/dramas/'))).searchParams.get('lang'), 'zh_CN');
    const general = JSON.stringify(f.observations.posts), storage = await storedValues(f.page);
    for (const value of Object.values(clicks)) { assert.equal(general.includes(value), false); assert.equal(storage.includes(value), false); }
    assert.ok(f.observations.referrers.every(value => !value));
    assert.equal(await f.page.evaluate(() => window.syntheticCookieWrites), 0);
    assert.deepEqual(f.observations.errors, []);
  } finally { await f.ctx.close(); }
});

test('only advertising consent permits reading cookies; independent advertising sends no analytics events', async () => {
  for (const state of ['unknown', 'denied']) {
    const f = await fixture({consent: 'granted', advertising: state, clickQuery, cookieProbe});
    try {
      await f.page.waitForTimeout(1200);
      assert.equal(await f.page.evaluate(() => window.syntheticCookieReads), 0);
      assert.equal(f.observations.landing.length, 0);
      assert.ok(f.page.url().includes('fbclid='));
      assert.equal(JSON.stringify(f.observations.posts).includes(clicks.fbclid), false);
    } finally { await f.ctx.close(); }
  }
  const adOnly = await fixture({consent: 'denied', advertising: 'granted', clickQuery, cookieProbe});
  try {
    await eventually(() => adOnly.observations.landing.length === 1);
    assert.deepEqual(adOnly.observations.landing[0].click_context, clicks);
    assert.equal(adOnly.observations.posts.length, 0);
    assert.ok(await adOnly.page.evaluate(() => window.syntheticCookieReads > 0));
    const storage = await storedValues(adOnly.page);
    for (const value of Object.values(clicks)) assert.equal(storage.includes(value), false);
  } finally { await adOnly.ctx.close(); }
});

test('disabled, non-pending and hidden pages do not read matching cookies even with advertising consent', async () => {
  for (const extra of [{enabled: false}, {pendingLanding: false}, {hidden: true}]) {
    const f = await fixture({consent: 'granted', advertising: 'granted', clickQuery, cookieProbe, ...extra});
    try {
      await f.page.waitForTimeout(250);
      assert.equal(await f.page.evaluate(() => window.syntheticCookieReads), 0);
      assert.equal(f.observations.landing.length, 0);
    } finally { await f.ctx.close(); }
  }
});

test('oversized, script/URL and ambiguous duplicate click/cookie input never enters a landing payload', async () => {
  for (const malicious of [
    {clickQuery: '&fbclid='+('A'.repeat(513))+'&ttclid='+encodeURIComponent('<script>alert(1)</script>'), cookieProbe: '_fbc='+encodeURIComponent('fb.1.1770000000000.<script>')+'; _fbp=fb.1.1770000000000.https://evil.invalid; _ttp='+('B'.repeat(513))},
    {clickQuery: '&fbclid=ONE&fbclid=TWO&ttclid='+encodeURIComponent('https://evil.invalid/?x=y'), cookieProbe: '_fbc=fb.1.1770000000000.A; _fbc=fb.1.1770000000000.B; _fbp=fb.1.1770000000000.123; _fbp=fb.1.1770000000000.456; _ttp='+encodeURIComponent('<img src=x onerror=alert(1)>')},
    {clickQuery: '&fbclid='+encodeURIComponent('mail@example.test')+'&ttclid='+encodeURIComponent('line\nbreak'), cookieProbe: '_fbc=fb.123.1770000000000.A; _fbp=fb.1.1770000000000.'+('1'.repeat(31))+'; _ttp=one%2Ftwo'},
    {clickQuery: '&ttclid=E.C.P.'+('A'.repeat(507)), cookieProbe: ''},
    {clickQuery: '&ttclid='+encodeURIComponent('E.C.P.SYNTHETIC_06-CLICK\n'), cookieProbe: ''},
  ]) {
    const f = await fixture({consent: 'denied', advertising: 'granted', ...malicious});
    try {
      await eventually(() => f.observations.landing.length === 1);
      assert.equal('click_context' in f.observations.landing[0], false);
      assert.equal(f.observations.posts.length, 0);
      assert.equal(await f.page.evaluate(() => window.syntheticCookieWrites), 0);
    } finally { await f.ctx.close(); }
  }
});

test('unconfirmed landing never lets generic landing_view race to fix missing context and does not clear click URL', async () => {
  const f = await fixture({consent: 'granted', advertising: 'granted', clickQuery, cookieProbe, landingConfirmed: false});
  try {
    await eventually(() => f.observations.posts.length > 0);
    assert.ok(f.page.url().includes('fbclid='+clicks.fbclid));
    assert.equal([...f.observations.events.values()].some(x => x.event_name === 'landing_view'), false);
    assert.ok(f.observations.landing.length <= 3);
    for (const payload of f.observations.landing) assert.deepEqual(payload.click_context, clicks);
  } finally { await f.ctx.close(); }
});

test('disabled or mismatched consent response preserves choices and dialog with conservative local pause', async () => {
  for (const response of [
    {enabled: false, policy_version: 'analytics-v1', consent: {analytics: 'granted', advertising: 'granted'}, pending_landing: true},
    {enabled: true, policy_version: 'analytics-v1', consent: {analytics: 'granted', advertising: 'denied'}, pending_landing: true},
  ]) {
    const f = await fixture({clickQuery, cookieProbe, consentResponse: response});
    try {
      await f.page.locator('[data-privacy-dialog]').waitFor({state: 'visible'});
      await f.page.locator('input[name=analytics]').check(); await f.page.locator('input[name=advertising]').check();
      await f.page.locator('[data-privacy-form] button[type=submit]').click();
      await f.page.getByText('Your choices could not be saved. Optional collection is paused in this browser. Retry when connected.', {exact: true}).waitFor({state: 'visible'});
      assert.equal(await f.page.locator('[data-privacy-dialog]').isVisible(), true);
      assert.equal(await f.page.locator('input[name=analytics]').isChecked(), true);
      assert.equal(await f.page.locator('input[name=advertising]').isChecked(), true);
      assert.ok(await f.page.evaluate(() => !!localStorage.getItem('h5.analytics.pause.v1')));
      assert.equal(await f.page.evaluate(() => window.syntheticCookieReads), 0);
      assert.equal(f.observations.posts.length, 0); assert.equal(f.observations.landing.length, 0);
      await f.page.locator('[data-privacy-close]').click(); await f.page.locator('[data-privacy-open]').click();
      await f.page.waitForTimeout(100);
      assert.equal(await f.page.locator('input[name=analytics]').isChecked(), true);
      assert.equal(await f.page.locator('input[name=advertising]').isChecked(), true);
      assert.deepEqual(f.observations.errors, []);
    } finally { await f.ctx.close(); }
  }
});

test('a late landing acknowledgment after withdrawal cannot resume collection or clean the newer privacy state', async () => {
  const f = await fixture({consent: 'granted', advertising: 'granted', clickQuery, cookieProbe, landingDelay: 1700});
  try {
    await eventually(() => f.observations.landing.length === 1);
    const reads = await f.page.evaluate(() => window.syntheticCookieReads);
    await f.page.locator('[data-privacy-open]').click();
    await f.page.locator('[data-privacy-withdraw]').click();
    await eventually(() => f.observations.consent.length === 1);
    await eventually(() => f.observations.timeline.includes('landing_confirmed'));
    await f.page.waitForTimeout(400);
    assert.equal(f.observations.posts.length, 0);
    assert.equal(await f.page.evaluate(() => window.syntheticCookieReads), reads);
    assert.equal(await f.page.evaluate(() => sessionStorage.getItem('h5.analytics.buffer.v1')), null);
    assert.ok(f.page.url().includes('fbclid='+clicks.fbclid));
    assert.deepEqual(f.observations.errors, []);
  } finally { await f.ctx.close(); }
});

test('slow open GET cannot overwrite a draft edited after the request started', async () => {
  const f = await fixture({consent: 'granted'});
  try {
    await eventually(() => f.observations.events.size >= 2);
    await f.page.waitForFunction(() => !sessionStorage.getItem('h5.analytics.buffer.v1'));
    f.fault.contextDelay = 700;
    const reads = f.observations.contextFinished;
    await f.page.locator('[data-privacy-open]').click();
    await f.page.locator('input[name=analytics]').uncheck();
    await f.page.locator('input[name=advertising]').check();
    await eventually(() => f.observations.contextFinished > reads); await f.page.waitForTimeout(100);
    assert.equal(await f.page.locator('input[name=analytics]').isChecked(), false);
    assert.equal(await f.page.locator('input[name=advertising]').isChecked(), true);
  } finally { await f.ctx.close(); }
});

test('background event-flush GET cannot overwrite an open dirty privacy draft', async () => {
  const f = await fixture({consent: 'granted'});
  try {
    await eventually(() => f.observations.events.size >= 2);
    await f.page.waitForFunction(() => !sessionStorage.getItem('h5.analytics.buffer.v1'));
    await f.page.locator('[data-privacy-open]').click();
    await f.page.waitForTimeout(100);
    await f.page.locator('input[name=analytics]').uncheck(); await f.page.locator('input[name=advertising]').check();
    const reads = f.observations.contextFinished; f.fault.contextDelay = 700;
    await f.page.evaluate(() => window.H5AudienceAnalytics.emit('subscribe_click'));
    await eventually(() => f.observations.contextFinished > reads); await f.page.waitForTimeout(100);
    assert.equal(await f.page.locator('input[name=analytics]').isChecked(), false);
    assert.equal(await f.page.locator('input[name=advertising]').isChecked(), true);
    await f.page.locator('[data-privacy-close]').click(); await f.page.locator('[data-privacy-open]').click();
    assert.equal(await f.page.locator('input[name=advertising]').isChecked(), true);
  } finally { await f.ctx.close(); }
});

test('privacy GET and save progress/failure are visible and keep the failed draft editable', async () => {
  const f = await fixture({consent: 'granted', contextDelay: 900});
  try {
    await f.page.locator('[data-privacy-open]').click();
    assert.equal(await f.page.locator('[data-privacy-status]').textContent(), 'Loading your privacy choices…');
    await eventually(() => f.observations.contextFinished > 0); await f.page.waitForTimeout(100);
    await f.page.locator('input[name=advertising]').check();
    f.fault.consentDelay = 900; f.fault.consent = 1;
    await f.page.locator('[data-privacy-form] button[type=submit]').click();
    assert.equal(await f.page.locator('[data-privacy-status]').textContent(), 'Saving your privacy choices…');
    assert.equal(await f.page.locator('[data-privacy-form] button[type=submit]').isDisabled(), true);
    assert.equal(await f.page.locator('[data-privacy-close]').isEnabled(), true);
    await f.page.locator('[data-privacy-form]').evaluate(form => {
      form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
      form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    });
    await f.page.getByText('Your choices could not be saved. Optional collection is paused in this browser. Retry when connected.', {exact: true}).waitFor({state: 'visible'});
    assert.equal(await f.page.locator('input[name=advertising]').isChecked(), true);
    assert.equal(await f.page.locator('[data-privacy-form] button[type=submit]').isEnabled(), true);
    assert.equal(f.observations.consent.length, 1);
    await f.page.locator('input[name=advertising]').uncheck();
    await f.page.evaluate(() => window.dispatchEvent(new Event('online')));
    await f.page.waitForTimeout(1000);
    assert.equal(f.observations.consent.length, 1, 'Foreground retry must not save the superseded failed draft');
    assert.equal(await f.page.locator('input[name=advertising]').isChecked(), false);
    f.fault.consentDelay = 0;
    await f.page.locator('[data-privacy-form] button[type=submit]').click();
    await f.page.locator('[data-privacy-dialog]').waitFor({state: 'hidden'});
    assert.deepEqual(f.observations.consent[1], {analytics: true, advertising: false, policy_version: 'analytics-v1'});
    assert.deepEqual(f.observations.errors, []);
  } finally { await f.ctx.close(); }
});

test('disabled and failed reads have distinct bilingual status without losing editable choices', async () => {
  for (const [locale, messages] of Object.entries({
    en: {reading: 'Loading your privacy choices…', disabled: 'Optional tracking is not enabled. You can continue using the site.', failed: 'Your saved choices could not be loaded. Your edits are kept. Close and reopen to retry.'},
    zh_CN: {reading: '正在读取隐私选择…', disabled: '可选跟踪尚未启用，你仍可正常使用网站。', failed: '已保存的选择读取失败，当前修改已保留。可关闭后重开重试。'},
  })) {
    const f = await fixture({enabled: false, locale, contextDelay: 700});
    try {
      await f.page.locator('[data-privacy-open]').click();
      assert.equal(await f.page.locator('[data-privacy-status]').textContent(), messages.reading);
      await f.page.getByText(messages.disabled, {exact: true}).waitFor({state: 'visible'});
      await f.page.locator('input[name=analytics]').check(); await f.page.locator('input[name=advertising]').check();
      await f.page.locator('[data-privacy-close]').click();
      f.fault.contextStatus = 503; f.fault.contextDelay = 250;
      await f.page.locator('[data-privacy-open]').click();
      await f.page.getByText(messages.failed, {exact: true}).waitFor({state: 'visible'});
      assert.equal(await f.page.locator('input[name=analytics]').isChecked(), true);
      assert.equal(await f.page.locator('input[name=advertising]').isChecked(), true);
      assert.equal(await f.page.locator('[data-privacy-close]').isEnabled(), true);
      assert.equal(f.observations.consent.length, 0);
    } finally { await f.ctx.close(); }
  }
});

test('320 by 500 short-screen dialogs scroll to reject, withdraw and close in both languages', async () => {
  for (const locale of ['en', 'zh_CN']) {
    const f = await fixture({consent: 'denied', advertising: 'denied', locale});
    try {
      await f.page.setViewportSize({width: 320, height: 500});
      await f.page.locator('[data-privacy-open]').click();
      for (const selector of ['[data-privacy-reject]', '[data-privacy-withdraw]', '[data-privacy-close]']) {
        await f.page.locator(selector).scrollIntoViewIfNeeded();
        const fits = await f.page.locator(selector).evaluate(button => {
          const rect = button.getBoundingClientRect(), panel = button.closest('dialog').getBoundingClientRect();
          return rect.top >= panel.top && rect.bottom <= panel.bottom && rect.top >= 0 && rect.bottom <= innerHeight;
        });
        assert.equal(fits, true);
      }
      assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      await f.page.screenshot({path: path.join(runtime, 'privacy-short-'+locale+'-320x500.png')});
      f.fault.consentDelay = 500;
      await f.page.locator('[data-privacy-reject]').click();
      assert.equal(await f.page.locator('[data-privacy-status]').textContent(), locale === 'en' ? 'Saving your privacy choices…' : '正在保存隐私选择…');
      await f.page.locator('[data-privacy-dialog]').waitFor({state: 'hidden'});
      await f.page.locator('[data-privacy-open]').click();
      await f.page.locator('[data-privacy-withdraw]').click();
      await f.page.locator('[data-privacy-dialog]').waitFor({state: 'hidden'});
      await f.page.locator('[data-privacy-open]').click(); await f.page.locator('[data-privacy-close]').click();
      assert.equal(f.observations.consent.length, 2);
      assert.ok(f.observations.consent.every(p => p.analytics === false && p.advertising === false));
      assert.deepEqual(f.observations.errors, []);
    } finally { await f.ctx.close(); }
  }
});
