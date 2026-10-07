(() => {
  'use strict';
  const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
  document.querySelectorAll('[data-continue-drama]').forEach(async link => {
    const drama = link.dataset.continueDrama;
    try {
      let saved;
      if (link.dataset.viewer) {
        const response = await fetch('/api/v1/me/progress/' + drama, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
        if (!response.ok) return;
        saved = await response.json();
      } else saved = JSON.parse(localStorage.getItem('audience.progress.' + drama) || 'null');
      if (!saved || !uuid.test(saved.episode_id) || !Number.isSafeInteger(saved.position_seconds) || saved.position_seconds < 0) return;
      link.href = '/dramas/' + drama + '/episodes/' + saved.episode_id + '?position=' + saved.position_seconds;
      link.hidden = false;
    } catch (_) { /* Unavailable storage must not block browsing or bypass current playback checks. */ }
  });
})();
