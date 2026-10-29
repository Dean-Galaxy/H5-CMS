(() => {
  'use strict';
  const root = document.querySelector('[data-player]');
  if (!root) return;
  const video = root.querySelector('video'), button = root.querySelector('[data-play]'), status = root.querySelector('[data-player-status]');
  const text = JSON.parse(document.getElementById('audience-messages').textContent);
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const duration = Number(root.dataset.duration), viewer = root.dataset.viewer;
  const progressStatus = root.querySelector('[data-progress-status]');
  const storageKey = 'audience.progress.' + root.dataset.drama;
  const clamp = value => Math.max(0, Math.min(duration, Number.isFinite(Number(value)) ? Number(value) : 0));
  const observe = detail => {
    try { root.dispatchEvent(new CustomEvent('audience:playback', {detail})); } catch (_) { /* Optional analytics never changes playback. */ }
  };
  let session = null, timer = null, hls = null, busy = false, stopped = false, mediaPending = false;
  let requestEpoch = 0, requestController = null, requestTimeout = null, disposed = false, needsRefresh = false, metadataListener = null;
  let position = clamp(new URL(location.href).searchParams.get('position'));
  const currentPosition = () => clamp(stopped || mediaPending || !video.currentSrc ? position : video.currentTime);
  let generation = 0, progressEpoch = 0, progressSequence = 0, guestGeneration = 0, hasPlayed = false, playingEventSeen = false, saveBusy = false, progressStopped = false;
  let lastSavedPosition = -1, playingFrom = 0, lastSaveTime = 0;
  const explicitPosition = new URL(location.href).searchParams.has('position');
  async function restore() {
    if (explicitPosition) return;
    try {
      let saved;
      if (viewer) {
        const abort = new AbortController(); let deadline;
        try {
          // Settle independently of fetch cancellation: a stalled read must not
          // keep every explicit Play retry waiting on the same promise forever.
          saved = await Promise.race([
            fetch('/api/v1/me/progress/' + root.dataset.drama, {headers:{Accept:'application/json'}, credentials:'same-origin', cache:'no-store', signal:abort.signal})
              .then(response => response.ok ? response.json() : null),
            new Promise(resolve => { deadline = setTimeout(() => { abort.abort(); resolve(null); }, 15000); })
          ]);
        } finally { clearTimeout(deadline); }
      } else saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
      if (!disposed && saved?.episode_id === root.dataset.episode) position = clamp(saved.position_seconds);
    } catch (_) { /* A bookmark read never grants access or imports other guest records. */ }
  }
  const restored = restore();
  async function reserveGuest() {
    const reserve = () => {
      const key = 'audience.generation.' + root.dataset.drama;
      const previous = Number(localStorage.getItem(key)) || 0;
      guestGeneration = Math.max(Date.now(), previous + 1);
      localStorage.setItem(key, String(guestGeneration));
    };
    try {
      if (navigator.locks) await navigator.locks.request(storageKey, reserve);
      else reserve();
    } catch (_) { guestGeneration = Date.now(); }
  }
  async function saveProgress() {
    if (!hasPlayed || !session || stopped || mediaPending || busy || progressStopped || saveBusy) return;
    const current = Math.floor(currentPosition());
    if (current === lastSavedPosition) return;
    const sequence = ++progressSequence, epoch = progressEpoch, requestSession = session;
    saveBusy = true; lastSaveTime = Date.now();
    try {
      if (viewer) {
        const response = await fetch('/api/v1/me/progress/' + root.dataset.drama, {method:'PUT',credentials:'same-origin',cache:'no-store',keepalive:true,
          headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},
          body:JSON.stringify({episode_id:root.dataset.episode,position_seconds:current,play_session_id:requestSession,sequence})});
        const saved = await response.json();
        if (epoch !== progressEpoch || requestSession !== session) return;
        if (!response.ok) {
          if (response.status === 401 || response.status === 419) { progressStopped = true; halt('AUTHENTICATION_REQUIRED'); }
          throw new Error('save_failed');
        }
        if (saved.play_session_id !== requestSession) { progressStopped = true; throw new Error('progress_conflict'); }
      } else {
        const persist = () => {
          if (epoch !== progressEpoch) return;
          const old = JSON.parse(localStorage.getItem(storageKey) || 'null');
          if (old && old.generation > guestGeneration) { progressStopped = true; throw new Error('progress_conflict'); }
          // Guest storage contains a bookmark only, never Content's playback session or media URL.
          localStorage.setItem(storageKey, JSON.stringify({drama_id:root.dataset.drama,episode_id:root.dataset.episode,
            position_seconds:current,generation:guestGeneration,sequence,saved_at:new Date().toISOString()}));
        };
        if (navigator.locks) await navigator.locks.request(storageKey, persist); else persist();
      }
      if (epoch !== progressEpoch) return;
      lastSavedPosition = current;
      progressStatus.textContent = text[viewer ? 'account_progress' : 'guest_progress'];
    } catch (error) { if (epoch === progressEpoch) progressStatus.textContent = text[error.message] || text.save_failed; }
    finally { if (epoch === progressEpoch) saveBusy = false; }
  }
  async function nativeCaptions(url, run) {
    // Some native HLS implementations play video but omit its WebVTT rendition.
    // Follow only the actual, signed rendition URLs supplied in Content's manifests.
    try {
      const master = await fetch(url, {credentials:'same-origin', cache:'no-store', signal:AbortSignal.timeout(15000)});
      if (!master.ok) return;
      const line = (await master.text()).split('\n').find(line => line.startsWith('#EXT-X-MEDIA:') && line.includes('TYPE=SUBTITLES'));
      if (!line) return;
      const uri = line.match(/URI="([^"]+)"/)?.[1];
      if (!uri) return;
      const captionUrl = new URL(uri, url);
      if (!['http:', 'https:'].includes(captionUrl.protocol)) return;
      const captions = await fetch(captionUrl, {credentials:'same-origin', cache:'no-store', signal:AbortSignal.timeout(15000)});
      if (!captions.ok) return;
      const target = (await captions.text()).split('\n').find(line => line.trim() && !line.startsWith('#'));
      if (!target || run !== generation || video.textTracks.length) return;
      const source = new URL(target, captionUrl);
      if (!['http:', 'https:'].includes(source.protocol)) return;
      const track = document.createElement('track'); track.kind = 'subtitles'; track.src = source.href;
      track.srclang = line.match(/LANGUAGE="([^"]+)"/)?.[1] || 'und';
      track.label = line.match(/NAME="([^"]+)"/)?.[1] || track.srclang;
      track.default = true; video.append(track);
    } catch (_) { /* Video can continue; no translated or invented subtitle is substituted. */ }
  }
  function message(code) { status.textContent = text[code] || text.media_error; }
  function links() {
    const path = location.pathname + '?position=' + Math.floor(currentPosition());
    root.querySelectorAll('[data-watch-login]').forEach(a => a.href = '/identity/login?return_to=' + encodeURIComponent(path));
    document.querySelectorAll('[data-audience-login]').forEach(a => { if (!root.dataset.viewer) a.href = '/identity/login?return_to=' + encodeURIComponent(path); });
    document.querySelectorAll('[data-watch-membership], [data-audience-membership]').forEach(a => a.href = '/membership?return_to=' + encodeURIComponent(path));
    document.querySelectorAll('.language a').forEach(a => {
      const target = new URL(a.href); target.searchParams.set('position', Math.floor(currentPosition())); a.href = target.href;
    });
  }
  function halt(code) {
    if (code) observe({type: 'error', code});
    position = currentPosition();
    requestEpoch++; requestController?.abort(); requestController = null; clearTimeout(requestTimeout);
    busy = false; button.disabled = false;
    stopped = true; mediaPending = false; generation++; progressEpoch++; saveBusy = false; hasPlayed = false; playingEventSeen = false; clearTimeout(timer); video.pause();
    if (metadataListener) { video.removeEventListener('loadedmetadata', metadataListener); metadataListener = null; }
    if (hls) { hls.destroy(); hls = null; }
    video.querySelectorAll('track').forEach(track => track.remove()); video.removeAttribute('src'); video.load();
    needsRefresh = ['AUTHENTICATION_REQUIRED', 'CSRF_MISMATCH'].includes(code);
    button.hidden = false; button.textContent = needsRefresh ? text.refresh : text.retry; if (code) message(code);
  }
  async function authorize(renew = false) {
    if (busy || disposed) return;
    if (!renew) observe({type: 'request'});
    const attempt = ++requestEpoch;
    const active = () => attempt === requestEpoch && !disposed;
    const abort = new AbortController(); requestController = abort;
    busy = true; button.disabled = true; message('loading');
    const deadline = setTimeout(() => { if (active()) halt('network'); }, 15000);
    requestTimeout = deadline;
    try {
      await restored;
      if (!active()) return;
      if (!renew) { progressEpoch++; progressSequence = 0; progressStopped = false; hasPlayed = false; playingEventSeen = false; saveBusy = false; lastSavedPosition = -1; if (!viewer) await reserveGuest(); }
      if (!active()) return;
      const target = '/audience/dramas/' + root.dataset.drama + '/episodes/' + root.dataset.episode + '/playback';
      const resumeAt = currentPosition(), resumePlaying = !renew || !video.paused;
      const response = await fetch(target, {method:'POST', credentials:'same-origin', cache:'no-store', signal:abort.signal, headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({play_session_id:renew ? session : null})});
      const grant = await response.json();
      if (!active()) return;
      if (!response.ok || !grant.allowed) { halt([401,419].includes(response.status) ? 'AUTHENTICATION_REQUIRED' : grant.error?.code || grant.reason); if (response.status === 409) session = null; return; }
      session = grant.play_session_id; stopped = false; const run = ++generation;
      // Keep the returned short-lived URL only in this player. Never derive a signature or persist it.
      const url = new URL(grant.short_lived_url, location.href);
      if (!['http:', 'https:'].includes(url.protocol)) { halt('media_error'); return; }
      // A paused native player with preload=none has no metadata after a source
      // renewal. Preserve its handoff until the next explicit Play loads it.
      position = resumeAt; mediaPending = true;
      if (hls) { hls.destroy(); hls = null; }
      video.querySelectorAll('track').forEach(track => track.remove());
      const ready = () => {
        if (!active()) return;
        video.currentTime = Math.min(resumeAt, Number.isFinite(video.duration) ? video.duration : duration);
        position = clamp(video.currentTime); mediaPending = false;
        // The return position is a one-time handoff. Future reloads use the latest saved bookmark.
        const currentUrl = new URL(location.href); currentUrl.searchParams.delete('position');
        window.history?.replaceState(null, '', currentUrl.pathname + currentUrl.search);
        if (resumePlaying) video.play().catch(() => { if (active()) { button.hidden = false; button.textContent = text.play; } });
      };
      if (metadataListener) video.removeEventListener('loadedmetadata', metadataListener);
      metadataListener = ready;
      video.addEventListener('loadedmetadata', ready, {once:true});
      if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = url.href;
        nativeCaptions(url.href, run);
        // preload=none intentionally waits for play(); waiting for metadata first would deadlock.
        if (resumePlaying) video.play().catch(() => { if (active()) { button.hidden = false; button.textContent = text.play; } });
      }
      else if (window.Hls?.isSupported()) {
        hls = new Hls({autoStartLoad:false}); hls.loadSource(url.href); hls.attachMedia(video);
        // authorize() starts only after the Play click, or for this same continuous playback.
        hls.startLoad();
        hls.on(Hls.Events.ERROR, (_, data) => { if (active() && data.fatal) halt(navigator.onLine ? 'media_error' : 'network'); });
      } else { video.removeEventListener('loadedmetadata', ready); halt('unsupported'); return; }
      button.hidden = resumePlaying;
      if (!resumePlaying) { button.textContent = text.play; message('paused'); }
      clearTimeout(timer);
      timer = setTimeout(() => { if (active()) authorize(true); }, Math.max(1000, Date.parse(grant.expires_at) - Date.now() - 20000));
    } catch (_) { if (active()) halt('network'); }
    finally { clearTimeout(deadline); if (active()) { requestController = null; busy = false; button.disabled = false; } }
  }
  button.addEventListener('click', () => {
    if (needsRefresh) { location.assign(location.pathname + '?position=' + Math.floor(currentPosition())); return; }
    if (video.currentSrc && !stopped) video.play().catch(() => message('media_error'));
    else { session = null; authorize(); }
  });
  video.addEventListener('playing', () => { playingEventSeen = true; playingFrom = video.currentTime; button.hidden = true; message('playing'); });
  video.addEventListener('waiting', () => { playingEventSeen = false; playingFrom = video.currentTime; });
  video.addEventListener('seeking', () => { playingEventSeen = false; playingFrom = video.currentTime; });
  video.addEventListener('pause', () => { playingEventSeen = false; if (!stopped && !busy) { message('paused'); saveProgress(); } });
  video.addEventListener('timeupdate', () => {
    if (!stopped && !mediaPending) position = clamp(video.currentTime);
    // Only advancing, actually playing video can establish a real viewing point.
    if (!busy && playingEventSeen && !video.paused && !video.seeking && video.currentTime > playingFrom + 0.25) hasPlayed = true;
    if (!busy && hasPlayed && Date.now() - lastSaveTime > 5000) saveProgress();
    links();
  });
  video.addEventListener('ended', () => { clearTimeout(timer); saveProgress(); });
  video.addEventListener('error', () => { if (!busy && !stopped) halt(navigator.onLine ? 'media_error' : 'network'); });
  window.addEventListener('offline', () => halt('network'));
  document.addEventListener('visibilitychange', () => { if (document.hidden) saveProgress(); });
  window.addEventListener('pagehide', () => { saveProgress(); disposed = true; halt(null); });
  // Returning from a bfcache entry must obtain fresh current-identity permission.
  window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
  document.querySelectorAll('a').forEach(a => a.addEventListener('click', () => { links(); saveProgress(); }));
  links();
})();
