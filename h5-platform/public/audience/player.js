(() => {
  'use strict';
  const root = document.querySelector('[data-player]');
  if (!root) return;
  const video = root.querySelector('video'), button = root.querySelector('[data-play]'), status = root.querySelector('[data-player-status]');
  const text = JSON.parse(document.getElementById('audience-messages').textContent);
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const duration = Number(root.dataset.duration);
  const clamp = value => Math.max(0, Math.min(duration, Number.isFinite(Number(value)) ? Number(value) : 0));
  let session = null, timer = null, hls = null, busy = false, stopped = false;
  let position = clamp(new URL(location.href).searchParams.get('position'));
  let generation = 0;
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
    const path = location.pathname + '?position=' + Math.floor(clamp(video.currentTime || position));
    root.querySelectorAll('[data-watch-login]').forEach(a => a.href = '/identity/login?return_to=' + encodeURIComponent(path));
    document.querySelectorAll('[data-audience-login]').forEach(a => { if (!root.dataset.viewer) a.href = '/identity/login?return_to=' + encodeURIComponent(path); });
    root.querySelector('[data-watch-membership]').href = '/membership?return_to=' + encodeURIComponent(path);
  }
  function halt(code) {
    stopped = true; generation++; clearTimeout(timer); video.pause();
    if (hls) { hls.destroy(); hls = null; }
    video.querySelectorAll('track').forEach(track => track.remove()); video.removeAttribute('src'); video.load();
    button.hidden = false; button.textContent = text.retry; message(code);
  }
  async function authorize(renew = false) {
    if (busy) return;
    busy = true; button.disabled = true; message('loading');
    const target = '/api/v1/episodes/' + root.dataset.episode + '/playback-grants';
    const resumeAt = clamp(video.currentTime || position), resumePlaying = !renew || !video.paused;
    try {
      const response = await fetch(target, {method:'POST', credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({play_session_id:renew ? session : null})});
      const grant = await response.json();
      if (!response.ok || !grant.allowed) { halt(grant.error?.code || grant.reason); if (response.status === 409) session = null; return; }
      session = grant.play_session_id; stopped = false; const run = ++generation;
      // Keep the returned short-lived URL only in this player. Never derive a signature or persist it.
      const url = new URL(grant.short_lived_url, location.href);
      if (!['http:', 'https:'].includes(url.protocol)) { halt('media_error'); return; }
      if (hls) { hls.destroy(); hls = null; }
      video.querySelectorAll('track').forEach(track => track.remove());
      const ready = () => {
        video.currentTime = Math.min(resumeAt, Number.isFinite(video.duration) ? video.duration : duration);
        if (resumePlaying) video.play().catch(() => { button.hidden = false; button.textContent = text.play; });
      };
      video.addEventListener('loadedmetadata', ready, {once:true});
      if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = url.href;
        nativeCaptions(url.href, run);
        // preload=none intentionally waits for play(); waiting for metadata first would deadlock.
        if (resumePlaying) video.play().catch(() => { button.hidden = false; button.textContent = text.play; });
      }
      else if (window.Hls?.isSupported()) {
        hls = new Hls({autoStartLoad:false}); hls.loadSource(url.href); hls.attachMedia(video);
        // authorize() starts only after the Play click, or for this same continuous playback.
        hls.startLoad();
        hls.on(Hls.Events.ERROR, (_, data) => { if (data.fatal && !busy) halt(navigator.onLine ? 'media_error' : 'network'); });
      } else { video.removeEventListener('loadedmetadata', ready); halt('unsupported'); return; }
      button.hidden = true;
      clearTimeout(timer);
      timer = setTimeout(() => authorize(true), Math.max(1000, Date.parse(grant.expires_at) - Date.now() - 20000));
    } catch (_) { halt('network'); }
    finally { busy = false; button.disabled = false; }
  }
  button.addEventListener('click', () => {
    if (video.currentSrc && !stopped) video.play().catch(() => message('media_error'));
    else { session = null; authorize(); }
  });
  video.addEventListener('playing', () => { button.hidden = true; message('playing'); });
  video.addEventListener('pause', () => { if (!stopped && !busy) message('paused'); });
  video.addEventListener('timeupdate', () => { if (!stopped) position = clamp(video.currentTime); links(); });
  video.addEventListener('error', () => { if (!busy && !stopped) halt(navigator.onLine ? 'media_error' : 'network'); });
  window.addEventListener('offline', () => halt('network'));
  window.addEventListener('pagehide', () => { clearTimeout(timer); if (hls) hls.destroy(); });
  links();
})();
