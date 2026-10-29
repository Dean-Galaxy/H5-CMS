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

async function fixture({consent = 'unknown', advertising = 'unknown', locale = 'en', media = false, hidden = false, enabled = true} = {}) {
  const ctx = await browser.newContext({viewport: {width: 390, height: 844}});
  let server = {enabled, policy_version: 'analytics-v1', consent: {analytics: consent, advertising}, pending_landing: true};
  const observations = {posts: [], events: new Map(), landing: [], consent: [], external: [], errors: [], playback: 0};
  const fault = {events: 0, status: 503, consent: 0};
  let scope = 'synthetic-browser-scope', member = true;
  if (hidden) await ctx.addInitScript(() => {
    let visible = false;
    Object.defineProperty(document, 'visibilityState', {get: () => visible ? 'visible' : 'hidden'});
    window.syntheticVisible = () => { visible = true; document.dispatchEvent(new Event('visibilitychange')); };
  });
  await ctx.route('**/*', async route => {
    const req = route.request(), url = new URL(req.url());
    if (url.origin !== origin) { observations.external.push(url.origin); return route.abort(); }
    const json = body => route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify(body)});
    if (url.pathname === '/api/v1/tracking/context') return json(server);
    if (url.pathname === '/api/v1/tracking/consent') {
      const p = req.postDataJSON(); observations.consent.push(p);
      if (fault.consent-- > 0) return route.fulfill({status: 503});
      server.consent = {analytics: p.analytics ? 'granted' : 'denied', advertising: p.advertising ? 'granted' : 'denied'};
      return json(server);
    }
    if (url.pathname === '/api/v1/tracking/landing') {
      observations.landing.push(req.postDataJSON()); server.pending_landing = false; return json({confirmed: true});
    }
    if (url.pathname === '/api/v1/tracking/events') {
      const events = req.postDataJSON().events; observations.posts.push(events);
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
  const url = origin+'/dramas/'+drama+(media ? '/episodes/'+episode : '')+'?token=NEVER-COLLECT-ME&lang='+locale;
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
