(() => {
  'use strict';
  const mount = document.querySelector('[data-audience-tracking]');
  if (!mount || window.H5AudienceAnalytics) return;
  const POLICY = 'analytics-v1', TTL = 86400000, MAX_EVENTS = 100, MAX_BYTES = 65536, MAX_ATTEMPTS = 5;
  const KEY = 'h5.analytics.buffer.v1', BLOCK = 'h5.analytics.pause.v1', NOTICE = 'h5.analytics.change.v1';
  const scope = mount.dataset.bufferScope;
  const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
  const uuid = () => crypto.randomUUID();
  const pageId = uuid(), path = location.pathname;
  const knownPath = /^(?:\/$|\/catalog$|\/membership$|\/billing\/return$|\/identity\/(?:login|register|account)$|\/dramas\/[0-9a-f-]{36}(?:\/episodes\/[0-9a-f-]{36})?$)/i.test(path);
  const routeIds = path.match(/^\/dramas\/([0-9a-f-]{36})(?:\/episodes\/([0-9a-f-]{36}))?$/i);
  const common = {page_instance_id: pageId, ...(knownPath ? {path} : {})};
  if (routeIds && uuidPattern.test(routeIds[1])) common.drama_id = routeIds[1];
  if (routeIds?.[2] && uuidPattern.test(routeIds[2])) common.episode_id = routeIds[2];
  const schema = {
    page_view: [], landing_view: [], drama_view: [], play_requested: [], play_started: ['position_seconds', 'duration_seconds'],
    watch_progress: ['position_seconds', 'duration_seconds', 'watched_seconds'], episode_completed: ['duration_seconds', 'watched_seconds'],
    player_startup: ['startup_ms'], player_stall: ['stall_ms'], player_error: ['error_code'], paywall_view: [], subscribe_click: [],
    registration_view: [], registration_submit: [], verification_view: [], checkout_click: [], payment_return_view: [],
  };
  const errorMap = {network: 'network', media_error: 'media', unsupported: 'media', AUTHENTICATION_REQUIRED: 'authorization', CSRF_MISMATCH: 'authorization',
    UNPUBLISHED: 'unavailable', NOT_FOUND: 'unavailable', REGION_BLOCKED: 'region', MEDIA_NOT_READY: 'unavailable', MEMBERSHIP_REQUIRED: 'membership',
    BILLING_PENDING: 'payment_pending', DEPENDENCY_NOT_READY: 'unavailable', STALE_VERSION: 'invalid', NOT_AUTHORIZED: 'authorization'};
  const errors = new Set([...Object.values(errorMap), 'unknown']);
  const bytes = value => new TextEncoder().encode(JSON.stringify(value)).length;
  const read = (storage, key) => {
    try {
      const raw = storage.getItem(key);
      if (raw && raw.length > MAX_BYTES + 1024) { storage.removeItem(key); return null; }
      return JSON.parse(raw || 'null');
    } catch (_) { return null; }
  };
  const write = (storage, key, value) => { try { value === null ? storage.removeItem(key) : storage.setItem(key, JSON.stringify(value)); } catch (_) {} };
  let context = null, queue = [], timer = null, sending = false, contextPromise = null, disposed = false, consentBusy = false;
  let generation = 0, retryRound = 0, requests = [], requestTotal = 0, locallyPaused = false, choiceVersion = 0;
  let landingBusy = false, landingConfirmed = false, landingAttempts = 0;
  const once = new Set();
  const dialog = mount.querySelector('[data-privacy-dialog]'), form = mount.querySelector('[data-privacy-form]');
  const status = mount.querySelector('[data-privacy-status]');
  let savedBuffer = read(sessionStorage, KEY);
  const blocked = () => locallyPaused || read(localStorage, BLOCK) !== null;
  const granted = () => context?.enabled === true && context?.policy_version === POLICY && context?.consent?.analytics === 'granted' && !blocked();
  const landingGranted = () => context?.enabled === true && context?.policy_version === POLICY && context?.consent?.advertising === 'granted' && !blocked();
  const canSend = granted;
  function prune() {
    queue = queue.filter(item => item.created > Date.now() - TTL && item.attempts < MAX_ATTEMPTS).slice(-MAX_EVENTS);
    while (queue.length && bytes(queue) > MAX_BYTES) queue.shift();
  }
  function persist() { prune(); write(sessionStorage, KEY, queue.length ? {scope, queue} : null); }
  function clear() { generation++; queue = []; savedBuffer = null; clearTimeout(timer); timer = null; write(sessionStorage, KEY, null); resetConsentPlayback(); }
  function budget() {
    requests = requests.filter(at => at > Date.now() - 60000);
    if (requests.length >= 10 || requestTotal >= 120) return false;
    requests.push(Date.now()); requestTotal++; return true;
  }
  async function request(url, payload) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 5000);
    try {
      const response = await fetch(url, {method: payload ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: {Accept: 'application/json', ...(payload ? {'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''} : {})},
        ...(payload ? {body: JSON.stringify(payload)} : {})});
      if (!response.ok) { const error = new Error('tracking_unavailable'); error.status = response.status; throw error; }
      return await response.json();
    } finally { clearTimeout(timeout); }
  }
  function validContext(value) {
    return value && typeof value.enabled === 'boolean' && value.policy_version === POLICY
      && ['unknown', 'granted', 'denied'].includes(value.consent?.analytics) && ['unknown', 'granted', 'denied'].includes(value.consent?.advertising);
  }
  function applyContext(value) {
    if (!validContext(value)) throw new Error('tracking_unavailable');
    context = value;
    if (!canSend()) clear();
    if (savedBuffer && canSend()) {
      if (savedBuffer.scope === scope && Array.isArray(savedBuffer.queue)) {
        // Validate the entire persisted envelope; never forward arbitrary storage fields.
        queue = savedBuffer.queue.filter(item => validStored(item) && (granted() || item.event.event_name === 'landing_view')).slice(-MAX_EVENTS);
        prune();
      }
      savedBuffer = null;
    }
    form.elements.analytics.checked = value.consent.analytics === 'granted';
    form.elements.advertising.checked = value.consent.advertising === 'granted';
    if (value.enabled && value.consent.analytics === 'unknown' && value.consent.advertising === 'unknown' && !blocked() && !dialog.open) dialog.showModal();
    pageEvents();
    confirmLanding();
  }
  async function confirmLanding() {
    if (!landingGranted() || !context?.pending_landing || !routeIds || routeIds[2] || document.visibilityState !== 'visible'
      || landingBusy || landingConfirmed || landingAttempts >= 3 || !budget()) return;
    landingBusy = true; landingAttempts++;
    const epoch = generation;
    try {
      const result = await request('/api/v1/tracking/landing', {page_instance_id: pageId, drama_id: common.drama_id, path});
      if (epoch === generation && result.confirmed === true) landingConfirmed = true;
    } catch (_) { /* Retry only after a foreground/network/context interaction, at most three times per page. */ }
    finally { landingBusy = false; }
  }
  async function loadContext() {
    if (contextPromise) return contextPromise;
    const epoch = generation;
    contextPromise = request('/api/v1/tracking/context').then(value => { if (epoch === generation && !disposed) applyContext(value); return value; }).catch(() => {
      if (epoch === generation) { context = null; resetViewing(); } return null;
    }).finally(() => { contextPromise = null; });
    return contextPromise;
  }
  function properties(name, input) {
    if (!Object.hasOwn(schema, name)) return null;
    const result = {...common};
    if (input.play_instance_id && uuidPattern.test(input.play_instance_id)) result.play_instance_id = input.play_instance_id;
    for (const key of schema[name]) {
      const value = input[key];
      if (key === 'error_code') { if (errors.has(value)) result[key] = value; }
      else if (Number.isSafeInteger(value) && value >= 0 && value <= (key.endsWith('_ms') ? 600000 : key === 'watched_seconds' ? 604800 : 86400)) result[key] = value;
    }
    return result;
  }
  function validStored(item) {
    if (!item || !uuidPattern.test(item.event?.event_id) || !Object.hasOwn(schema, item.event?.event_name)
      || Object.keys(item.event).sort().join(',') !== 'event_id,event_name,properties'
      || !Number.isFinite(item.created) || item.created > Date.now() || !Number.isInteger(item.attempts) || item.attempts < 0 || bytes(item) > 4096) return false;
    const p = item.event.properties;
    if (!p || !uuidPattern.test(p.page_instance_id)) return false;
    const keys = new Set(['page_instance_id', 'play_instance_id', 'path', 'drama_id', 'episode_id', ...schema[item.event.event_name]]);
    return Object.keys(p).every(key => keys.has(key)) && (!p.path || /^\/(?:$|catalog$|membership$|billing\/return$|identity\/[a-z-]+$|dramas\/[0-9a-f-]{36}(?:\/episodes\/[0-9a-f-]{36})?$)/i.test(p.path))
      && ['drama_id', 'episode_id', 'play_instance_id'].every(key => !p[key] || uuidPattern.test(p[key]))
      && schema[item.event.event_name].every(key => !(key in p) || (key === 'error_code' ? errors.has(p[key]) : Number.isSafeInteger(p[key]) && p[key] >= 0 && p[key] <= (key.endsWith('_ms') ? 600000 : key === 'watched_seconds' ? 604800 : 86400)));
  }
  function emit(name, input = {}, onceKey = null, finalProgress = false) {
    if (!granted() || disposed || (document.visibilityState !== 'visible' && !(finalProgress && name === 'watch_progress')) || (onceKey && once.has(onceKey))) return;
    const p = properties(name, input);
    if (!p) return;
    const event = {event_id: uuid(), event_name: name, properties: p};
    if (bytes(event) > 2048) return;
    if (onceKey) once.add(onceKey);
    queue.push({event, created: Date.now(), attempts: 0}); persist(); schedule(1000);
  }
  function schedule(delay) {
    if (disposed || timer || !queue.length || !canSend()) return;
    timer = setTimeout(() => { timer = null; flush(); }, delay);
  }
  async function flush() {
    if (sending || disposed || !queue.length || navigator.onLine === false || !canSend()) return;
    if (!budget()) return;
    sending = true; const epoch = generation;
    try {
      // Revalidate server consent after refresh, tab changes and before sending a batch.
      await loadContext();
      if (epoch !== generation || !canSend()) return;
      if (!granted()) queue = queue.filter(item => item.event.event_name === 'landing_view');
      prune();
      const batch = queue.filter(item => item.attempts < MAX_ATTEMPTS).slice(0, 20);
      while (batch.length && bytes({events: batch.map(item => item.event)}) > 32768) batch.pop();
      if (!batch.length) return;
      batch.forEach(item => item.attempts++); persist();
      const result = await request('/api/v1/tracking/events', {events: batch.map(item => item.event)});
      if (epoch !== generation || disposed) return;
      if (!Number.isInteger(result.accepted) || !Number.isInteger(result.rejected) || result.accepted < 0 || result.rejected < 0
        || result.accepted + result.rejected !== batch.length) throw new Error('tracking_unavailable');
      const ids = new Set(batch.map(item => item.event.event_id));
      queue = queue.filter(item => !ids.has(item.event.event_id)); retryRound = 0; persist();
    } catch (error) {
      // One finite retry budget, stable UUID/body, then wait for an explicit foreground/network event.
      if ([413, 422].includes(error.status)) { queue = []; persist(); retryRound = 5; }
      else retryRound++;
    } finally {
      sending = false;
      if (epoch === generation && retryRound <= 4) schedule([1000, 2000, 8000, 30000, 120000][retryRound]);
    }
  }
  function pageEvents() {
    if (!knownPath) return;
    emit('page_view', {}, 'page');
    if (context?.pending_landing && routeIds && !routeIds[2]) emit('landing_view', {}, 'landing');
    if (routeIds) emit('drama_view', {}, 'drama');
    if (path === '/identity/register') emit('registration_view', {}, 'registration');
    if (path === '/identity/account' && document.querySelector('form[action="/api/v1/auth/email/verification-notification"]')) emit('verification_view', {}, 'verification');
    if (path === '/billing/return') emit('payment_return_view', {}, 'payment_return');
  }
  async function saveChoices(analytics, advertising) {
    const version = ++choiceVersion;
    clear(); context = null; locallyPaused = true;
    const desired = {analytics, advertising, policy_version: POLICY};
    write(localStorage, BLOCK, desired);
    write(localStorage, NOTICE, uuid());
    if (consentBusy) return;
    consentBusy = true;
    status.textContent = '';
    try {
      const value = await request('/api/v1/tracking/consent', desired);
      if (version !== choiceVersion) return;
      if (!validContext(value) || value.consent.analytics !== (analytics ? 'granted' : 'denied')
        || value.consent.advertising !== (advertising ? 'granted' : 'denied')) throw new Error('tracking_unavailable');
      locallyPaused = false; write(localStorage, BLOCK, null); applyContext(value); write(localStorage, NOTICE, uuid());
      status.textContent = status.dataset.saved; dialog.close(); schedule(0);
    } catch (_) { status.textContent = status.dataset.unavailable; }
    finally {
      consentBusy = false;
      if (version !== choiceVersion) {
        const pending = read(localStorage, BLOCK);
        if (pending) saveChoices(pending.analytics, pending.advertising);
      }
    }
  }
  mount.querySelector('[data-privacy-open]').addEventListener('click', () => { status.textContent = ''; dialog.showModal(); loadContext(); });
  mount.querySelector('[data-privacy-close]').addEventListener('click', () => dialog.close());
  form.addEventListener('submit', event => { event.preventDefault(); saveChoices(form.elements.analytics.checked, form.elements.advertising.checked); });
  mount.querySelector('[data-privacy-reject]').addEventListener('click', () => saveChoices(false, false));
  mount.querySelector('[data-privacy-withdraw]').addEventListener('click', () => saveChoices(false, false));
  document.addEventListener('submit', event => {
    const action = event.target.getAttribute('action');
    if (['/api/v1/auth/logout', '/api/v1/auth/login'].includes(action)) { clear(); context = null; write(localStorage, NOTICE, uuid()); }
    if (action === '/api/v1/auth/register') emit('registration_submit');
    if (action === '/membership/checkout' && !event.target.querySelector('input[name=recheck]')) emit('checkout_click');
  }, true);
  document.addEventListener('click', event => {
    const link = event.target.closest?.('a[href]');
    if (link && new URL(link.href, location.href).origin === location.origin && new URL(link.href, location.href).pathname === '/membership') emit('subscribe_click');
  });

  // Playback observation is independent of the permission/bookmark logic and never changes media state.
  const root = document.querySelector('[data-player]'), video = root?.querySelector('video');
  let paywallObserver = null;
  let playId = null, activePlay = false, started = false, completed = false, watched = 0, lastProgress = 0, playingSeen = false;
  let anchor = null, requestedAt = null, stallAt = null, errorCount = 0, frameAt = null, startupSent = false;
  function resetViewing() { anchor = null; }
  function resetConsentPlayback() {
    paywallObserver?.disconnect(); paywallObserver = null;
    resetViewing();
    if (playId !== null) playId = uuid();
    watched = 0; lastProgress = 0; started = false; completed = false;
    requestedAt = null; stallAt = null; frameAt = null; startupSent = false;
  }
  const snapshot = () => ({play_instance_id: playId, position_seconds: Math.max(0, Math.floor(video?.currentTime || 0)), duration_seconds: Math.max(0, Math.floor(Number(root?.dataset.duration) || 0)), watched_seconds: Math.floor(watched)});
  function progress(final = false) {
    if (started && Math.floor(watched) > Math.floor(lastProgress) && (final || watched - lastProgress >= 15)) {
      emit('watch_progress', snapshot(), null, final); lastProgress = watched;
    }
  }
  function startup() {
    if (started && !startupSent && requestedAt !== null && (frameAt !== null || !video.requestVideoFrameCallback)) {
      startupSent = true;
      emit('player_startup', {play_instance_id: playId, startup_ms: Math.min(600000, Math.max(0, Math.round((frameAt ?? performance.now()) - requestedAt)))});
    }
  }
  function watchPaywall(id) {
    const target = root.querySelector('[data-player-status]');
    if (!target) return;
    const expose = () => {
      const rect = target.getBoundingClientRect();
      if (id !== playId || !granted() || document.visibilityState !== 'visible' || !target.textContent.trim()
        || rect.width <= 0 || rect.height <= 0 || rect.bottom <= 0 || rect.top >= innerHeight
        || getComputedStyle(target).visibility !== 'visible') return false;
      emit('paywall_view', {play_instance_id: id}, 'paywall:'+id); return true;
    };
    paywallObserver?.disconnect();
    if (!expose() && window.IntersectionObserver) {
      const observer = new IntersectionObserver(() => { if (expose()) { observer.disconnect(); if (paywallObserver === observer) paywallObserver = null; } });
      paywallObserver = observer; observer.observe(target);
    }
  }
  if (video) {
    root.addEventListener('audience:playback', event => {
      const detail = event.detail || {};
      if (detail.type === 'request') {
        paywallObserver?.disconnect(); paywallObserver = null;
        playId = uuid(); activePlay = true; started = false; completed = false; watched = 0; lastProgress = 0;
        requestedAt = granted() ? performance.now() : null; stallAt = null; errorCount = 0; playingSeen = false; frameAt = null; startupSent = false; resetViewing();
        if (video.requestVideoFrameCallback) {
          const id = playId;
          video.requestVideoFrameCallback(() => { if (id === playId && activePlay) { frameAt = performance.now(); startup(); } });
        }
        emit('play_requested', {play_instance_id: playId});
      } else if (detail.type === 'error' && playId !== null && ++errorCount <= 5) {
        emit('player_error', {play_instance_id: playId, error_code: errorMap[detail.code] || 'unknown'});
        if (detail.code === 'MEMBERSHIP_REQUIRED') {
          const id = playId;
          requestAnimationFrame(() => {
            if (id === playId) watchPaywall(id);
          });
        }
        activePlay = false; resetViewing();
      }
    });
    video.addEventListener('playing', () => {
      playingSeen = true;
      if (stallAt !== null && started) emit('player_stall', {play_instance_id: playId, stall_ms: Math.min(600000, Math.max(0, Math.round(performance.now() - stallAt)))});
      stallAt = null;
      anchor = granted() && document.visibilityState === 'visible' ? {at: performance.now(), position: video.currentTime} : null;
    });
    video.addEventListener('waiting', () => { if (activePlay && !video.paused && started && stallAt === null) stallAt = performance.now(); playingSeen = false; resetViewing(); });
    video.addEventListener('seeking', () => { playingSeen = false; resetViewing(); });
    video.addEventListener('seeked', () => { anchor = null; });
    video.addEventListener('pause', () => { progress(true); playingSeen = false; resetViewing(); stallAt = null; });
    video.addEventListener('timeupdate', () => {
      const now = performance.now(), position = video.currentTime;
      if (!activePlay || !playingSeen || !granted() || document.visibilityState !== 'visible' || video.paused || video.seeking || video.readyState < 2) { resetViewing(); return; }
      if (anchor) {
        const delta = position - anchor.position, elapsed = (now - anchor.at) / 1000;
        const rate = Math.max(0, Math.min(2, video.playbackRate || 1));
        if (delta > 0 && delta <= elapsed * rate + 0.25 && elapsed <= 3) {
          watched += Math.min(delta, elapsed * rate);
          if (!started && watched >= 0.25) {
            started = true; emit('play_started', snapshot());
            startup();
          }
          progress();
        }
      }
      anchor = {at: now, position};
    });
    video.addEventListener('ended', () => {
      const duration = Number(root.dataset.duration);
      progress(true);
      if (started && !completed && duration > 0 && watched >= duration * 0.9 && video.currentTime >= duration - 1) {
        completed = true; emit('watch_progress', snapshot()); emit('episode_completed', snapshot());
      }
      activePlay = false; resetViewing();
    });
  }
  async function foreground() {
    if (document.visibilityState !== 'visible' || disposed) { resetViewing(); return; }
    const pending = read(localStorage, BLOCK);
    if (pending && typeof pending.analytics === 'boolean' && typeof pending.advertising === 'boolean') await saveChoices(pending.analytics, pending.advertising);
    else await loadContext();
    pageEvents(); if (paywallObserver && playId) watchPaywall(playId); schedule(0);
  }
  document.addEventListener('visibilitychange', () => { if (document.visibilityState !== 'visible') { progress(true); stallAt = null; } foreground(); });
  window.addEventListener('online', foreground);
  window.addEventListener('storage', event => {
    if ([BLOCK, NOTICE].includes(event.key)) { clear(); context = null; if (!blocked()) foreground(); }
  });
  window.addEventListener('pagehide', () => { progress(true); persist(); disposed = true; clearTimeout(timer); paywallObserver?.disconnect(); resetViewing(); });
  window.addEventListener('pageshow', event => { if (event.persisted) { disposed = false; foreground(); } });
  window.H5AudienceAnalytics = Object.freeze({emit});
  foreground();
})();
