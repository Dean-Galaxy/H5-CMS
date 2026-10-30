(() => {
  'use strict';
  const mount = document.querySelector('[data-measurement-c]');
  if (!mount || window.H5AudienceAnalytics) return;
  const text = JSON.parse(document.getElementById('measurement-c-messages').textContent);
  const notice = document.querySelector('[data-c-delivery]');
  const dialog = mount.querySelector('[data-c-dialog]');
  const VERSION = 'c-notice-c-core-1';
  const PAUSE_KEY = 'h5.c.site-session.paused'; // Necessary preference only. Never stores events or identifiers.
  const TTL = 30 * 60 * 1000;
  const uuid = () => crypto.randomUUID();
  const pageTypes = new Set(['home', 'catalog', 'drama', 'player', 'membership', 'registration', 'login', 'verification', 'checkout', 'payment_return', 'account']);
  const names = new Set(['page_view', 'drama_view', 'play_requested', 'play_started', 'watch_progress', 'episode_completed', 'player_startup', 'player_stall', 'player_error', 'paywall_view', 'subscribe_click', 'registration_view', 'registration_submit', 'verification_view', 'checkout_click', 'payment_return_view']);
  const errors = new Set(['network', 'media', 'region', 'membership', 'authorization', 'timeout', 'unavailable', 'invalid', 'validation', 'credentials', 'rate_limited', 'unknown', 'payment_pending', 'payment_cancelled', 'payment_failed']);
  const errorMap = {network: 'network', media_error: 'media', unsupported: 'media', AUTHENTICATION_REQUIRED: 'authorization', CSRF_MISMATCH: 'authorization',
    UNPUBLISHED: 'unavailable', NOT_FOUND: 'unavailable', REGION_BLOCKED: 'region', MEDIA_NOT_READY: 'unavailable', MEMBERSHIP_REQUIRED: 'membership',
    BILLING_PENDING: 'payment_pending', DEPENDENCY_NOT_READY: 'unavailable', STALE_VERSION: 'invalid', NOT_AUTHORIZED: 'authorization'};
  const isUUID = value => typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
  let context = null, known = false, active = false, epoch = 0, page = null, queue = [], timer, expiryTimer;
  let state = 'checking', pendingDeny = false, storageFailed = false, visibleNotice = false;
  let startedAt = 0, lastActivity = 0, starting = false, attempted = false, flushing = false, saving = false;
  let minute = 0, batches = 0;
  let suspended = false;
  try { pendingDeny = localStorage.getItem(PAUSE_KEY) === '1'; } catch (_) { storageFailed = true; }
  const valid = value => value && value.schema_version === 2 && value.policy_version === 'first-party-measurement-c-v1'
    && value.notice_version === VERSION && typeof value.enabled === 'boolean'
    && ['eligible', 'ineligible', 'unknown'].includes(value.eligibility)
    && ['policy_permitted', 'none'].includes(value.basis_kind)
    && ['unset', 'allow', 'deny'].includes(value.site_choice) && ['on', 'off'].includes(value.site_effective)
    && Number.isSafeInteger(value.revision) && value.revision >= 0;
  const permitted = value => valid(value) && value.enabled && value.eligibility === 'eligible'
    && value.basis_kind === 'policy_permitted' && value.site_choice !== 'deny'
    && [null, 'NOTICE_NOT_READY'].includes(value.reason);
  function paint() {
    const key = state === 'active' && context?.site_choice === 'allow' ? 'active_allowed' : state;
    document.querySelectorAll('[data-c-status]').forEach(node => { node.textContent = text[node.hasAttribute('data-c-notice-status') ? 'notice_' + key : key] || text[key]; });
    mount.querySelector('[data-c-choice]').textContent = known ? text[context.site_choice] : text.unknown;
    mount.querySelector('[data-c-processing]').textContent = known ? text[context.site_effective === 'on' ? 'on' : 'disabled'] : text.unknown;
    mount.querySelector('[data-c-local]').textContent = active && !suspended ? text.collecting : text.waiting;
    mount.querySelector('[data-c-storage]').hidden = !storageFailed;
    mount.querySelector('[data-c-signal]').hidden = !(known && context.advertising?.opt_out_signal === true);
    document.querySelectorAll('[data-c-off]').forEach(node => { node.disabled = saving; });
    mount.dataset.state = state;
  }
  function pause(next) {
    epoch++; active = false; page = null; queue = []; clearTimeout(timer); clearTimeout(expiryTimer); timer = null; starting = false; suspended = false;
    resetPlay(); state = next; paint();
  }
  function rememberPause() {
    pendingDeny = true;
    try { localStorage.setItem(PAUSE_KEY, '1'); } catch (_) { storageFailed = true; }
  }
  async function api(endpoint, method = 'GET', body) {
    const abort = new AbortController();
    const timeout = setTimeout(() => abort.abort(), 6000);
    try {
      let csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
      if (body && mount.hasAttribute('data-c-registration')) {
        const prepared = await fetch('/api/v1/auth/csrf', {credentials: 'same-origin', cache: 'no-store', referrerPolicy: 'no-referrer', signal: abort.signal, headers: {'Accept': 'application/json'}});
        const token = await prepared.json();
        if (prepared.status !== 200 || typeof token.csrf_token !== 'string' || !/^[a-zA-Z0-9]{40}$/.test(token.csrf_token)) throw new Error('csrf');
        csrf = token.csrf_token;
      }
      const response = await fetch('/api/v2/measurement/' + endpoint, {
        method, credentials: 'same-origin', cache: 'no-store', referrerPolicy: 'no-referrer', signal: abort.signal,
        headers: {'Accept': 'application/json', ...(body ? {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf} : {})},
        ...(body ? {body: JSON.stringify(body)} : {}),
      });
      const value = await response.json();
      if (!response.ok) throw Object.assign(new Error('request'), {status: response.status, value});
      return value;
    } finally { clearTimeout(timeout); }
  }
  function contextState() {
    if (context.site_choice === 'deny') return 'off';
    if (context.reason === 'LEGACY_DENIED') return 'previous';
    return pendingDeny ? 'save_failed' : 'unavailable';
  }
  async function read(start = true) {
    const generation = epoch;
    const priorRevision = context?.revision;
    try {
      const value = await api('context');
      if (generation !== epoch) return false;
      if (!valid(value)) throw new Error('shape');
      context = value; known = true;
      if (!permitted(value) || pendingDeny) {
        pause(contextState());
      } else if (start) {
        if (active && page && value.site_effective === 'on' && value.revision === priorRevision
          && Date.now() - lastActivity < TTL && Date.now() - startedAt < 24 * 60 * 60 * 1000) {
          // Same DOM + still-valid server context: retain page/play IDs and cumulative visible watch time.
          suspended = false; state = 'active'; paint();
          if (video && !video.paused && !video.ended) beginPlay(false);
          if (queue.length && !timer) timer = setTimeout(flush, 700);
        } else {
          if (active) { pause('checking'); attempted = false; }
          suspended = false; state = 'checking'; paint(); await startIfVisible();
        }
      }
      return true;
    } catch (_) {
      if (generation === epoch) { known = false; pause(pendingDeny ? 'save_failed' : 'read_failed'); }
      return false;
    }
  }
  async function startIfVisible() {
    if (!visibleNotice || dialog.open || document.visibilityState !== 'visible' || active || pendingDeny || starting || attempted || !known || !permitted(context)) return;
    starting = true; attempted = true;
    const generation = epoch;
    try {
      // Start requires a UUID. This request nonce is discarded; event/page instances exist only after success.
      const value = await api('start', 'POST', {notice_version: VERSION, page_instance_id: uuid()});
      if (generation !== epoch) return;
      if (!valid(value)) throw new Error('shape');
      context = value; known = true;
      if (!permitted(value) || value.site_effective !== 'on') { pause(contextState()); return; }
      page = uuid(); active = true; startedAt = lastActivity = Date.now(); state = 'active'; paint();
      emit('page_view');
      if (drama) emit('drama_view', {drama_id: drama});
      // Existing playback may continue, but no pre-start time or startup is backfilled.
      if (video && !video.paused && !video.ended) beginPlay(false);
    } catch (_) {
      if (generation === epoch) { known = false; pause('read_failed'); }
    } finally { if (generation === epoch) starting = false; }
  }
  async function turnOff() {
    if (saving) return;
    rememberPause(); pause('saving'); saving = true; paint();
    const generation = epoch;
    try {
      // A failed/limited read must not prevent a necessary off request. Retain the last validated revision;
      // zero is only an optimistic initial revision, never a forced write. The server still compares it.
      const revision = valid(context) ? context.revision : 0;
      const value = await api('preferences', 'PATCH', {site_session: 'deny', notice_version: VERSION, expected_revision: revision});
      if (generation !== epoch) return;
      if (!valid(value) || value.site_choice !== 'deny' || value.site_effective !== 'off') throw new Error('unconfirmed');
      context = value; known = true; state = 'off';
    } catch (error) {
      if (generation !== epoch) return;
      known = false; state = error.status === 409 ? 'conflict' : 'save_failed';
      // A valid conflict body supplies current facts without spending the read budget.
      // Keep the off draft paused; only another explicit user action may submit the new revision.
      if (error.status === 409 && valid(error.value)) { context = error.value; known = true; }
    } finally { saving = false; paint(); }
  }
  function open(details = false) {
    if (!dialog.open) {
      dialog.showModal(); // Only explicit user handlers call this function.
      mount.querySelector('.measurement-c-body').scrollTop = 0;
      if (mount.hasAttribute('data-c-registration')) read(false).then(paint);
    }
    if (details) { const use = mount.querySelector('[data-c-use]'); use.open = true; use.scrollIntoView({block: 'start'}); }
  }
  document.querySelectorAll('[data-c-open]').forEach(node => node.addEventListener('click', () => open()));
  mount.querySelector('[data-c-details]').addEventListener('click', () => open(true));
  mount.querySelector('[data-c-close]').addEventListener('click', () => dialog.close());
  if (!mount.hasAttribute('data-c-registration')) dialog.addEventListener('close', startIfVisible);
  document.querySelectorAll('[data-c-off]').forEach(node => node.addEventListener('click', turnOff));
  mount.querySelector('[data-c-retry]').addEventListener('click', async () => {
    if (saving) return;
    if (pendingDeny) { await turnOff(); return; }
    attempted = false; pause('checking'); await read();
  });
  window.addEventListener('storage', event => {
    if (event.key === PAUSE_KEY && event.newValue === '1') { pendingDeny = true; known = false; pause('paused'); read(false); }
  });
  function canEmit() {
    if (!active || suspended || pendingDeny || document.visibilityState !== 'visible') return false;
    if (Date.now() - lastActivity >= TTL || Date.now() - startedAt >= 24 * 60 * 60 * 1000) { pause('unavailable'); return false; }
    return true;
  }
  function expireMemory() {
    clearTimeout(expiryTimer);
    queue = queue.filter(item => Date.now() - item.at < TTL && item.attempts < 5);
    if (queue.length) expiryTimer = setTimeout(expireMemory, Math.max(1, queue[0].at + TTL - Date.now()));
  }
  function emit(name, supplied = {}) {
    if (!names.has(name) || !canEmit()) return;
    const properties = {page_instance_id: page, page_type: pageTypes.has(mount.dataset.pageType) ? mount.dataset.pageType : 'home', locale: mount.dataset.locale === 'zh_CN' ? 'zh_CN' : 'en'};
    for (const key of ['drama_id', 'episode_id', 'play_instance_id']) if (isUUID(supplied[key])) properties[key] = supplied[key];
    const metrics = ({watch_progress: ['position_seconds', 'duration_seconds', 'watched_seconds'], episode_completed: ['position_seconds', 'duration_seconds', 'watched_seconds'], play_requested: ['position_seconds', 'duration_seconds'], play_started: ['position_seconds', 'duration_seconds'], player_startup: ['startup_ms'], player_stall: ['stall_ms']})[name] || [];
    for (const [key, cap] of Object.entries({position_seconds: 86400, duration_seconds: 86400, watched_seconds: 604800, startup_ms: 600000, stall_ms: 600000})) {
      if (metrics.includes(key) && typeof supplied[key] === 'number' && Number.isFinite(supplied[key]) && supplied[key] >= 0 && supplied[key] <= cap) properties[key] = supplied[key];
    }
    if (name === 'player_error' && errors.has(supplied.error_code)) properties.error_code = supplied.error_code;
    if (['play_requested', 'play_started', 'watch_progress', 'episode_completed', 'player_startup', 'player_stall', 'player_error'].includes(name)
      && !['drama_id', 'episode_id', 'play_instance_id'].every(key => properties[key])) return;
    if (name === 'play_started' && !(properties.position_seconds > 0)) return;
    const event = {event_id: uuid(), event_name: name, properties};
    const entry = {event, at: Date.now(), attempts: 0};
    const bytes = value => new TextEncoder().encode(JSON.stringify(value)).length;
    if (queue.length >= 100 || bytes([...queue.map(item => item.event), event]) > 65536) return;
    queue.push(entry); lastActivity = Date.now();
    expireMemory();
    if (!timer && !flushing) timer = setTimeout(flush, 700);
  }
  async function flush() {
    timer = null;
    if (flushing || !canEmit()) return;
    expireMemory();
    if (!queue.length) return;
    const nowMinute = Math.floor(Date.now() / 60000);
    if (minute !== nowMinute) { minute = nowMinute; batches = 0; }
    if (batches >= 10) { timer = setTimeout(flush, 6000); return; }
    const generation = epoch;
    const batch = [];
    for (const item of queue.slice(0, 20)) {
      if (new TextEncoder().encode(JSON.stringify({events: [...batch.map(row => row.event), item.event]})).length > 32768) break;
      batch.push(item);
    }
    flushing = true;
    let retry = false;
    try {
      const value = await api('context');
      if (generation !== epoch) return;
      if (!valid(value)) throw new Error('shape');
      if (!permitted(value) || value.site_effective !== 'on' || value.revision !== context.revision) {
        context = value; known = true; pause(contextState()); return;
      }
      context = value; known = true;
      batches++; batch.forEach(item => item.attempts++);
      try {
        const result = await api('events', 'POST', {events: batch.map(item => item.event)});
        if (generation !== epoch) return;
        if (!Number.isInteger(result.accepted) || !Number.isInteger(result.rejected)) throw new Error('shape');
        queue = queue.filter(item => !batch.includes(item));
        if (result.accepted === 0) { await read(false); }
      } catch (error) {
        if (generation !== epoch) return;
        if (error.status && error.status < 500) queue = queue.filter(item => !batch.includes(item));
        else retry = true;
      }
    } catch (_) {
      if (generation === epoch) { known = false; pause('read_failed'); }
    } finally {
      flushing = false;
      expireMemory();
      if (active && queue.length && !timer) timer = setTimeout(flush, retry ? 3000 : 700);
    }
  }
  window.H5AudienceAnalytics = Object.freeze({emit});
  const player = document.querySelector('[data-player]');
  const video = player?.querySelector('video');
  const drama = player?.dataset.drama || document.querySelector('[data-continue-drama]')?.dataset.continueDrama;
  let play = null, requestedAt = null, sampling = null, stalledAt = null;
  function resetPlay() { play = null; requestedAt = null; sampling = null; stalledAt = null; }
  function references() { return {drama_id: player.dataset.drama, episode_id: player.dataset.episode, play_instance_id: play?.id}; }
  function newPlay(startup = null) { return {id: uuid(), started: false, complete: false, watched: 0, startup, lastReport: 0}; }
  function beginPlay(withStartup = true) {
    if (!canEmit() || !video) return;
    if (!play) play = newPlay(withStartup ? requestedAt : null);
    sampling = {position: video.currentTime, at: performance.now()};
  }
  if (video) {
    player.addEventListener('audience:playback', event => {
      if (!canEmit()) return;
      if (event.detail?.type === 'request') {
        resetPlay(); requestedAt = performance.now();
        play = newPlay(requestedAt); emit('play_requested', references());
      } else if (event.detail?.type === 'error') {
        if (!play) play = newPlay();
        emit('player_error', {...references(), error_code: errorMap[event.detail.code] || (errors.has(event.detail.code) ? event.detail.code : 'unknown')});
      }
    });
    video.addEventListener('playing', () => {
      if (stalledAt !== null && play && canEmit()) emit('player_stall', {...references(), stall_ms: Math.min(600000, performance.now() - stalledAt)});
      stalledAt = null; beginPlay();
    });
    video.addEventListener('seeking', () => { sampling = null; });
    video.addEventListener('seeked', () => { if (!video.paused && !video.seeking) beginPlay(false); });
    video.addEventListener('waiting', () => { sampling = null; if (play?.started && canEmit() && stalledAt === null) stalledAt = performance.now(); });
    const sample = () => {
      if (!canEmit() || !play || !sampling || video.paused || video.seeking) return;
      const position = video.currentTime, at = performance.now();
      const delta = position - sampling.position, wall = (at - sampling.at) / 1000;
      sampling = {position, at};
      if (!(delta > 0) || delta > wall * Math.min(2, Math.max(0.1, video.playbackRate || 1)) + 0.35) return;
      const duration = Number.isFinite(video.duration) && video.duration > 0 ? video.duration : Number(player.dataset.duration);
      if (!(duration > 0 && duration <= 86400)) return;
      play.watched += Math.min(delta, wall * 2);
      if (!play.started && position > 0 && play.watched > 0) {
        play.started = true;
        emit('play_started', {...references(), position_seconds: position, duration_seconds: duration});
        if (play.startup !== null) emit('player_startup', {...references(), startup_ms: Math.min(600000, at - play.startup)});
      }
      if (play.watched - play.lastReport >= 5 || video.ended) {
        emit('watch_progress', {...references(), position_seconds: Math.min(position, duration), duration_seconds: duration, watched_seconds: play.watched});
        play.lastReport = play.watched;
      }
      if (!play.complete && play.watched >= duration * 0.9) {
        play.complete = true; emit('episode_completed', {...references(), position_seconds: Math.min(position, duration), duration_seconds: duration, watched_seconds: play.watched});
      }
    };
    video.addEventListener('timeupdate', sample);
    video.addEventListener('ended', () => { sample(); sampling = null; });
    video.addEventListener('pause', () => { sampling = null; });
    video.addEventListener('error', () => { if (play && canEmit()) emit('player_error', {...references(), error_code: 'media'}); });
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') {
      // Suspend sampling, not the logical page/play. Old responses cannot change the resumed generation.
      epoch++; suspended = true; known = false; starting = false; attempted = false;
      sampling = null; stalledAt = null; clearTimeout(timer); timer = null; state = 'paused'; paint();
    } else { state = 'checking'; paint(); read(); }
  });
  const observer = new IntersectionObserver(entries => {
    visibleNotice = entries.some(entry => entry.isIntersecting && entry.intersectionRatio >= 0.98);
    if (visibleNotice) startIfVisible();
  }, {threshold: [0, 0.98, 1]});
  if (notice) observer.observe(notice);
  paint(); read();
})();
