'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

// Execute the actual shipped player against controlled browser events and delayed
// HTTP responses. No exported internals, server, database, or real media are used.
const playerSource = fs.readFileSync(path.resolve(__dirname, '../../../public/audience/player.js'), 'utf8');
const dramaId = '00000000-0000-4000-8000-000000000001';
const firstEpisode = '00000000-0000-4000-8000-000000000002';
const otherEpisode = '00000000-0000-4000-8000-000000000003';
const sessionA = '00000000-0000-4000-8000-000000000004';
const sessionB = '00000000-0000-4000-8000-000000000005';

class Events {
  constructor() { this.listeners = new Map(); }
  addEventListener(name, callback, options = {}) {
    const listeners = this.listeners.get(name) || [];
    listeners.push({callback, once: options.once}); this.listeners.set(name, listeners);
  }
  removeEventListener(name, callback) {
    this.listeners.set(name, (this.listeners.get(name) || []).filter(item => item.callback !== callback));
  }
  emit(name, fields = {}) {
    for (const item of [...(this.listeners.get(name) || [])]) {
      if (item.once) this.removeEventListener(name, item.callback);
      item.callback({type: name, target: this, preventDefault() {}, ...fields});
    }
  }
}

function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return {promise, resolve, reject};
}

async function settle() {
  // Browser event handlers intentionally don't return their pending async work.
  for (let turn = 0; turn < 4; turn++) await new Promise(resolve => setImmediate(resolve));
}

function browser({viewer = true, episode = firstEpisode, position = 0, storage = new Map(), clock = 1800000000000, deferProgressRead = false, nativeHls = false} = {}) {
  let now = clock;
  const requests = [], timers = new Map(), hlsInstances = [];
  const effects = {attachedSources: 0, playCalls: 0, mediaLoads: 0};
  let nextTimer = 0;
  function setTimer(callback, delay = 0) { const id = ++nextTimer; timers.set(id, {callback, due: now + delay}); return id; }
  const video = Object.assign(new Events(), {
    currentTime: 0, currentSrc: '', paused: true, seeking: false, ended: false,
    readyState: 0, duration: 100, textTracks: [],
    canPlayType() { return nativeHls ? 'probably' : ''; },
    play() { effects.playCalls++; this.paused = false; return Promise.resolve(); },
    pause() { this.paused = true; this.emit('pause'); },
    removeAttribute(name) { if (name === 'src') this.currentSrc = ''; },
    load() { this.currentTime = 0; this.readyState = 0; },
    querySelectorAll() { return []; },
  });
  Object.defineProperty(video, 'src', {
    get() { return this.currentSrc; },
    set(value) {
      // Native source replacement with preload=none can reset the media clock
      // before a later Play supplies metadata for restoring the saved position.
      effects.attachedSources++; this.currentSrc = String(value);
      this.currentTime = 0; this.readyState = 0; this.paused = true;
    },
  });
  const button = Object.assign(new Events(), {hidden: false, disabled: false, textContent: ''});
  const status = {textContent: ''}, progressStatus = {textContent: ''};
  const membership = Object.assign(new Events(), {href: '/membership', dataset: {watchMembership: ''}});
  const login = Object.assign(new Events(), {href: '/identity/login', dataset: {watchLogin: ''}});
  const controls = new Map([
    ['video', video], ['[data-play]', button], ['[data-player-status]', status],
    ['[data-progress-status]', progressStatus], ['[data-watch-membership]', membership],
  ]);
  const root = {
    dataset: {drama: dramaId, episode, duration: '100', viewer: viewer ? 'local-test-viewer' : ''},
    querySelector(selector) { return controls.get(selector); },
    querySelectorAll(selector) { return selector.includes('watch-login') ? [login] : selector.includes('membership') ? [membership] : []; },
  };
  const document = Object.assign(new Events(), {
    hidden: false,
    querySelector(selector) { return selector === '[data-player]' ? root : selector === 'meta[name=csrf-token]' ? {content: 'test-csrf'} : null; },
    querySelectorAll(selector) {
      if (selector === 'a') return [membership, login];
      if (selector.includes('membership')) return [membership];
      if (selector.includes('audience-login')) return [login];
      return [];
    },
    getElementById() { return {textContent: JSON.stringify({
      play: 'play', retry: 'retry', loading: 'loading', playing: 'playing', paused: 'paused',
      guest_progress: 'guest_saved', account_progress: 'account_saved', progress_conflict: 'progress_conflict',
      save_failed: 'save_failed', media_error: 'media_error', network: 'network', timeout: 'timeout',
      MEMBERSHIP_REQUIRED: 'membership_required',
    })}; },
  });
  class FakeDate extends Date {
    constructor(...args) { super(...(args.length ? args : [now])); }
    static now() { return now; }
  }
  // This records the non-native adapter boundary, not real hls.js decoding or
  // browser compatibility. Event callbacks can outlive destroy() in a race.
  class Hls {
    static Events = {ERROR: 'error'};
    static isSupported() { return true; }
    constructor(options) {
      this.options = options; this.calls = []; this.callbacks = new Map(); this.destroyed = false;
      hlsInstances.push(this);
    }
    loadSource(source) { this.calls.push('loadSource'); this.source = source; }
    attachMedia(media) { this.calls.push('attachMedia'); effects.attachedSources++; media.currentSrc = this.source; }
    startLoad() { this.calls.push('startLoad'); effects.mediaLoads++; }
    on(event, callback) { this.callbacks.set(event, callback); }
    emit(event, data) { this.callbacks.get(event)?.(event, data); }
    destroy() { this.calls.push('destroy'); this.destroyed = true; }
  }
  const window = Object.assign(new Events(), {Hls});
  const location = {
    href: `http://127.0.0.1/dramas/${dramaId}/episodes/${episode}${position === null ? '' : '?position=' + position}`,
    pathname: `/dramas/${dramaId}/episodes/${episode}`, reload() {},
  };
  const context = {
    document, window, location, navigator: {onLine: true, locks: {request: async (_name, callback) => callback()}},
    localStorage: {getItem(key) { return storage.get(key) ?? null; }, setItem(key, value) { storage.set(key, String(value)); }},
    URL, Date: FakeDate, Math, JSON, Number, Promise, AbortController, AbortSignal, DOMException, Hls,
    setTimeout: setTimer, clearTimeout(id) { timers.delete(id); },
    fetch(url, options = {}) {
      const pending = deferred();
      const request = {url: String(url), options, body: options.body ? JSON.parse(options.body) : null, ignoreAbort: false, ...pending};
      requests.push(request);
      const abort = () => {
        if (!request.ignoreAbort) pending.reject(options.signal.reason || new DOMException('Aborted', 'AbortError'));
      };
      if (options.signal?.aborted) abort();
      else options.signal?.addEventListener('abort', abort, {once: true});
      if ((!options.method || options.method === 'GET') && !deferProgressRead) pending.resolve({ok: true, status: 200, json: async () => null, text: async () => ''});
      return pending.promise;
    },
  };
  vm.runInNewContext(playerSource, context, {filename: 'public/audience/player.js'});
  const saves = () => requests.filter(item => item.options.method === 'PUT');
  const authorizations = () => requests.filter(item => item.url.endsWith('/playback'));
  const beginAuthorization = async ({ignoreAbort = false} = {}) => {
    const count = authorizations().length; button.emit('click'); await settle();
    assert.equal(authorizations().length, count + 1, 'An explicit Play must request current Content authorization.');
    const request = authorizations().at(-1); request.ignoreAbort = ignoreAbort; return request;
  };
  const finishAuthorization = async (request, session, {metadata = true} = {}) => {
    request.resolve({ok: true, status: 200, json: async () => ({
      allowed: true, play_session_id: session, short_lived_url: '/protected-test.m3u8',
      expires_at: new Date(now + 120000).toISOString(),
    })});
    await settle();
    if (metadata) { video.emit('loadedmetadata'); await settle(); }
  };
  return {
    video, button, status, progressStatus, window, membership, storage, saves, effects, hlsInstances, authorizations, beginAuthorization, finishAuthorization,
    progressReads() { return requests.filter(item => (!item.options.method || item.options.method === 'GET') && item.url.includes('/progress/')); },
    activeTimerCount() { return timers.size; },
    timerCallbacks() { return [...timers.values()].map(timer => timer.callback); },
    async elapse(ms) {
      const target = now + ms; let limit = 1000;
      while (true) {
        const entry = [...timers].filter(([, timer]) => timer.due <= target).sort((a, b) => a[1].due - b[1].due)[0];
        if (!entry) break;
        assert.ok(limit-- > 0, 'A timer must not retry forever.');
        now = entry[1].due; timers.delete(entry[0]); entry[1].callback(); await settle();
      }
      now = target; await settle();
    },
    advanceClock(ms = 6000) { now += ms; },
    async authorize(session) { await finishAuthorization(await beginAuthorization(), session); },
    startPlaying(at = video.currentTime) {
      video.currentTime = at; video.paused = false; video.seeking = false; video.readyState = 3;
      video.emit('playing');
    },
    async advancePlayback(to) { video.currentTime = to; video.emit('timeupdate'); await settle(); },
    async acceptSave(request, session = request.body.play_session_id) {
      request.resolve({ok: true, status: 200, json: async () => ({play_session_id: session})}); await settle();
    },
  };
}

test('metadata, resume seek and buffering do not save before real playing advances', async () => {
  const page = browser({position: 27});
  await page.authorize(sessionA);
  // play() has set paused=false, and the restoration seek has completed, but
  // no playing event has occurred: data may still be buffering.
  await page.advancePlayback(27);
  assert.equal(page.saves().length, 0, 'A resume seek is not a real viewing point.');
  page.startPlaying(27);
  await page.advancePlayback(27.1);
  assert.equal(page.saves().length, 0, 'Insufficient playback advancement must not save.');
  await page.advancePlayback(27.5);
  assert.equal(page.saves().length, 1);
  assert.equal(page.saves()[0].body.position_seconds, 27);
  await page.acceptSave(page.saves()[0]);
});

test('a delayed save from the old playback cannot stop the newly authorized playback', async () => {
  const page = browser();
  await page.authorize(sessionA);
  page.startPlaying(0); await page.advancePlayback(1);
  const oldSave = page.saves()[0]; assert.ok(oldSave);
  page.window.emit('offline');
  await page.authorize(sessionB);
  page.advanceClock(); page.startPlaying(1); await page.advancePlayback(3);
  const newSave = page.saves().find(item => item.body.play_session_id === sessionB);
  assert.ok(newSave, 'An unresolved old save cannot hold the new session busy.');
  await page.acceptSave(oldSave);
  assert.notEqual(page.progressStatus.textContent, 'progress_conflict', 'Ignore old success without changing the new session.');
  await page.acceptSave(newSave);
  page.advanceClock(); await page.advancePlayback(7);
  assert.equal(page.saves().at(-1).body.play_session_id, sessionB);
  assert.equal(page.saves().at(-1).body.position_seconds, 7);
  await page.acceptSave(page.saves().at(-1));
});

test('an intentional rewatch from zero saves zero rather than the former resume position', async () => {
  const page = browser({position: 27});
  await page.authorize(sessionA);
  page.startPlaying(27); await page.advancePlayback(28);
  await page.acceptSave(page.saves()[0]);
  page.video.seeking = true; page.video.emit('seeking'); page.video.currentTime = 0;
  page.video.seeking = false; page.video.emit('seeked');
  page.advanceClock(); page.startPlaying(0); await page.advancePlayback(0.5);
  assert.equal(page.saves().at(-1).body.position_seconds, 0);
  assert.equal(page.saves().at(-1).body.play_session_id, sessionA);
  await page.acceptSave(page.saves().at(-1));
});

test('a previous guest tab cannot replace the newer valid guest viewing point', async () => {
  const storage = new Map();
  const oldPage = browser({viewer: false, storage, episode: otherEpisode, clock: 1800000000000});
  await oldPage.authorize(sessionA); oldPage.startPlaying(0); await oldPage.advancePlayback(1);
  const newPage = browser({viewer: false, storage, episode: firstEpisode, clock: 1800000000010});
  await newPage.authorize(sessionB); newPage.startPlaying(0); await newPage.advancePlayback(2);
  const key = 'audience.progress.' + dramaId;
  const latest = JSON.parse(storage.get(key));
  assert.equal(latest.episode_id, firstEpisode);
  assert.equal(latest.position_seconds, 2);
  oldPage.advanceClock(); await oldPage.advancePlayback(50);
  assert.equal(storage.get(key), JSON.stringify(latest), 'An older tab must not overwrite a deliberate earlier-episode rewatch.');
  assert.equal(oldPage.progressStatus.textContent, 'progress_conflict');
  assert.equal(oldPage.saves().length + newPage.saves().length, 0, 'Guests never write account progress.');
});


for (const exit of ['offline', 'pagehide']) {
  test(`${exit} aborts pending authorization and ignores its late allowed result`, async () => {
    const page = browser();
    // Model the completion race as well as transport cancellation: an adapter
    // already decoding a response can finish after abort has been requested.
    const request = await page.beginAuthorization({ignoreAbort: true});
    page.window.emit(exit); await settle();
    assert.ok(request.options.signal?.aborted, 'Leaving playback must abort its pending permission request.');
    const stoppedStatus = page.status.textContent;
    await page.finishAuthorization(request, sessionA);
    assert.equal(page.video.currentSrc, '', 'An abandoned grant must never become the video source.');
    assert.deepEqual(page.effects, {attachedSources: 0, playCalls: 0, mediaLoads: 0});
    assert.equal(page.status.textContent, stoppedStatus);
    assert.equal(page.activeTimerCount(), 0, 'No timeout or renewal may survive playback exit.');
    await page.elapse(180000);
    assert.equal(page.authorizations().length, 1, 'A late grant must not restart the renewal loop.');
    if (exit === 'pagehide') {
      page.button.emit('click'); await settle();
      assert.equal(page.authorizations().length, 1, 'A disposed document cannot request another grant.');
    }
  });

  test(`${exit} ignores a late authorization failure without changing the stopped UI`, async () => {
    const page = browser();
    const request = await page.beginAuthorization({ignoreAbort: true});
    page.window.emit(exit); await settle();
    assert.ok(request.options.signal?.aborted);
    const stoppedStatus = page.status.textContent, disabled = page.button.disabled;
    request.reject(new Error('Delayed transport failure')); await settle();
    assert.equal(page.status.textContent, stoppedStatus, 'A stale catch must not replace the current status.');
    assert.equal(page.button.disabled, disabled, 'A stale finally must not rewrite controls.');
    assert.equal(page.video.currentSrc, '');
    assert.equal(page.activeTimerCount(), 0);
  });
}

for (const completion of ['success', 'failure']) {
  test(`old authorization ${completion} cannot unlock a newer permission request`, async () => {
    const page = browser();
    const oldRequest = await page.beginAuthorization({ignoreAbort: true});
    page.window.emit('offline'); await settle();
    const newRequest = await page.beginAuthorization();
    assert.equal(page.button.disabled, true);
    if (completion === 'success') await page.finishAuthorization(oldRequest, sessionA, {metadata: false});
    else { oldRequest.reject(new Error('Late old request failure')); await settle(); }
    assert.equal(page.button.disabled, true, 'Only the currently pending request owns the busy control.');
    assert.equal(page.status.textContent, 'loading');
    assert.equal(page.video.currentSrc, '');
    // Emit directly as an adversarial double-click: the request guard must also
    // reject it rather than depending only on disabled button presentation.
    page.button.emit('click'); await settle();
    assert.equal(page.authorizations().length, 2, 'Old finally must not allow a third concurrent request.');
    assert.equal(newRequest.options.signal.aborted, false);
    await page.finishAuthorization(newRequest, sessionB);
    assert.equal(page.effects.attachedSources, 1);
    assert.equal(page.effects.playCalls, 1);
    assert.equal(page.button.disabled, false);
  });
}

test('authorization times out within 15 seconds and only an explicit retry can restart it', async () => {
  const page = browser();
  const timedOut = await page.beginAuthorization();
  await page.elapse(14999);
  assert.equal(timedOut.options.signal?.aborted, false, 'The request remains valid before its deadline.');
  assert.equal(page.button.disabled, true);
  await page.elapse(1);
  assert.equal(timedOut.options.signal.aborted, true, 'The pending request must be canceled at 15 seconds.');
  assert.equal(page.button.disabled, false);
  assert.equal(page.button.hidden, false);
  assert.equal(page.button.textContent, 'retry');
  assert.notEqual(page.status.textContent, 'loading', 'Timeout needs a recoverable user-facing state.');
  assert.equal(page.video.currentSrc, '');
  assert.equal(page.activeTimerCount(), 0);
  await page.elapse(60000);
  assert.equal(page.authorizations().length, 1, 'Timeout must never automatically request another permission.');
  await page.authorize(sessionB);
  assert.equal(page.effects.attachedSources, 1);
  assert.equal(page.effects.playCalls, 1);
  page.startPlaying(0); await page.advancePlayback(1);
  assert.equal(page.saves()[0].body.play_session_id, sessionB);
  await page.acceptSave(page.saves()[0]);
});


test('a stalled bookmark read allows an explicit retry and ignores its late position', async () => {
  const page = browser({position: null, deferProgressRead: true});
  page.button.emit('click'); await settle();
  assert.equal(page.progressReads().length, 1);
  const bookmark = page.progressReads()[0];
  // A response already being decoded may complete despite transport cancellation.
  bookmark.ignoreAbort = true;
  assert.equal(page.authorizations().length, 0, 'Restoring a known bookmark may precede authorization.');
  await page.elapse(15000);
  assert.equal(bookmark.options.signal?.aborted, true, 'Bookmark restoration must also have a finite deadline.');
  assert.equal(page.button.disabled, false, 'An unavailable bookmark must not leave Play permanently busy.');
  const initialRequests = page.authorizations().length;
  assert.ok(initialRequests <= 1, 'The restore deadline may release at most the initial permission request.');
  for (const request of page.authorizations()) {
    assert.equal(request.options.signal?.aborted, true, 'Any permission request at the shared deadline must be canceled.');
  }
  assert.equal(page.video.currentSrc, '');
  await page.elapse(60000);
  assert.equal(page.authorizations().length, initialRequests, 'Waiting after timeout cannot restart playback automatically.');
  // Permission still comes from a fresh explicit Play. A late restored position
  // must not overwrite the position chosen after the bookmark deadline.
  const retried = await page.beginAuthorization();
  assert.equal(page.authorizations().length, initialRequests + 1);
  bookmark.resolve({ok: true, json: async () => ({episode_id: firstEpisode, position_seconds: 88})});
  await settle();
  await page.finishAuthorization(retried, sessionB);
  assert.equal(page.video.currentTime, 0, 'A stale bookmark must not seek the new playback session.');
  assert.equal(page.effects.attachedSources, 1);
  assert.equal(page.effects.playCalls, 1);
});


test('non-native HLS constructs and starts loading only after current Content authorization', async () => {
  const page = browser();
  assert.equal(page.video.canPlayType('application/vnd.apple.mpegurl'), '');
  assert.equal(page.hlsInstances.length, 0, 'Rendering the page cannot instantiate a media loader.');
  const request = await page.beginAuthorization();
  assert.equal(page.hlsInstances.length, 0, 'The Play click still needs permission before loading media.');
  assert.deepEqual(page.effects, {attachedSources: 0, playCalls: 0, mediaLoads: 0});
  await page.finishAuthorization(request, sessionA);
  assert.equal(page.hlsInstances.length, 1);
  const hls = page.hlsInstances[0];
  assert.equal(hls.options.autoStartLoad, false, 'Only the authorized path may explicitly start loading.');
  assert.equal(hls.source, 'http://127.0.0.1/protected-test.m3u8');
  assert.deepEqual(hls.calls, ['loadSource', 'attachMedia', 'startLoad']);
  assert.deepEqual(page.effects, {attachedSources: 1, playCalls: 1, mediaLoads: 1});
  assert.equal(page.saves().length, 0, 'Loading media alone never records a real viewing point.');
});

test('a fatal non-native HLS error destroys the loader, clears the source and stops renewal', async () => {
  const page = browser();
  await page.authorize(sessionA);
  const hls = page.hlsInstances[0];
  hls.emit('error', {fatal: false}); await settle();
  assert.notEqual(page.video.currentSrc, '', 'A recoverable hls.js event does not terminate the player.');
  hls.emit('error', {fatal: true}); await settle();
  assert.equal(hls.destroyed, true);
  assert.equal(page.video.currentSrc, '');
  assert.equal(page.video.paused, true);
  assert.equal(page.status.textContent, 'media_error');
  assert.equal(page.button.hidden, false);
  assert.equal(page.button.disabled, false);
  assert.equal(page.button.textContent, 'retry');
  assert.equal(page.activeTimerCount(), 0);
  await page.elapse(180000);
  assert.equal(page.authorizations().length, 1, 'A failed decoder must not continue requesting new grants.');
});

test('renewal denial clears non-native HLS and old callbacks cannot restore it', async () => {
  const page = browser();
  const initial = await page.beginAuthorization();
  await page.finishAuthorization(initial, sessionA, {metadata: false});
  const hls = page.hlsInstances[0];
  const oldMetadata = page.video.listeners.get('loadedmetadata')[0].callback;
  const oldRenewal = page.timerCallbacks()[0];
  page.video.emit('loadedmetadata'); await settle();
  page.startPlaying(30);
  await page.elapse(100000); // The 120-second grant renews 20 seconds before expiry.
  assert.equal(page.authorizations().length, 2);
  const renewal = page.authorizations()[1];
  assert.equal(renewal.body.play_session_id, sessionA, 'Renewal must check the current playback session.');
  renewal.resolve({ok: true, status: 200, json: async () => ({allowed: false, reason: 'MEMBERSHIP_REQUIRED'})});
  await settle();
  assert.equal(hls.destroyed, true);
  assert.equal(page.video.currentSrc, '');
  assert.equal(page.video.paused, true);
  assert.equal(page.status.textContent, 'membership_required');
  assert.equal(page.button.textContent, 'retry');
  assert.equal(page.activeTimerCount(), 0);
  const effectsAtDenial = {...page.effects};
  // Dispatch already-queued callbacks even though halt removed their listeners
  // and timer. The actual player must independently reject their stale epoch.
  oldMetadata(); oldRenewal(); hls.emit('error', {fatal: true}); await settle();
  await page.elapse(180000);
  assert.equal(page.video.currentSrc, '');
  assert.equal(page.status.textContent, 'membership_required');
  assert.deepEqual(page.effects, effectsAtDenial, 'No stale callback may attach, load or play the revoked source.');
  assert.equal(page.authorizations().length, 2, 'A denied renewal cannot silently request another grant.');
});


test('paused native HLS renewal preserves position and return links before metadata arrives', async () => {
  const page = browser({nativeHls: true, position: 37});
  await page.authorize(sessionA);
  assert.equal(page.hlsInstances.length, 0, 'Native playback must not instantiate the non-native adapter.');
  page.startPlaying(37); await page.advancePlayback(38);
  assert.equal(page.saves().at(-1).body.position_seconds, 38);
  await page.acceptSave(page.saves().at(-1));
  page.video.pause(); await settle();
  assert.equal(page.status.textContent, 'paused');
  const savedBeforeRenewal = page.saves().length;
  const playsBeforeRenewal = page.effects.playCalls;
  await page.elapse(100000);
  assert.equal(page.authorizations().length, 2);
  const renewal = page.authorizations()[1];
  assert.equal(renewal.body.play_session_id, sessionA);
  await page.finishAuthorization(renewal, sessionA, {metadata: false});
  assert.equal(page.video.currentTime, 0, 'The native adapter deliberately models the temporary reset clock.');
  assert.equal(page.video.paused, true);
  assert.equal(page.effects.playCalls, playsBeforeRenewal, 'Renewal must preserve the user pause.');
  assert.equal(page.status.textContent, 'paused', 'Waiting for metadata while paused is not perpetual loading.');
  assert.equal(page.button.hidden, false);
  assert.equal(page.button.disabled, false);
  assert.equal(page.button.textContent, 'play');
  page.video.emit('timeupdate'); await settle();
  assert.equal(page.saves().length, savedBeforeRenewal, 'An uninitialized native clock cannot save zero over the bookmark.');
  const returnTo = () => new URL(page.membership.href, 'http://127.0.0.1').searchParams.get('return_to');
  assert.equal(returnTo(), `/dramas/${dramaId}/episodes/${firstEpisode}?position=38`);
  page.button.emit('click'); await settle();
  page.video.emit('timeupdate'); await settle();
  assert.equal(page.saves().length, savedBeforeRenewal, 'Play cannot save the reset clock before metadata restoration.');
  assert.equal(returnTo(), `/dramas/${dramaId}/episodes/${firstEpisode}?position=38`);
  page.video.emit('loadedmetadata'); await settle();
  assert.equal(page.video.currentTime, 38);
  assert.equal(page.video.paused, false);
  assert.ok(page.effects.playCalls > playsBeforeRenewal);
  assert.equal(page.authorizations().length, 2, 'Resuming the still-valid source does not create another authorization.');
  page.startPlaying(38); await page.advancePlayback(39);
  assert.equal(page.saves().at(-1).body.position_seconds, 39);
  await page.acceptSave(page.saves().at(-1));
});
