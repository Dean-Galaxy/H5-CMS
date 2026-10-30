(() => {
  'use strict';
  const root = document.querySelector('[data-c-purpose-manager]');
  if (!root) return;
  const dialog = root.closest('dialog');
  const words = JSON.parse(document.getElementById('purpose-r1-messages').textContent);
  const NOTICE = 'c-purpose-choice-only-r1-v1';
  const purposes = ['account_link', 'advertising'];
  const safeReasons = new Set(['ASSOCIATION_NOT_READY', 'EXTERNAL_DISABLED', 'PURPOSE_OPTOUT', 'SITE_OPTOUT', 'LEGACY_DENIED', 'POLICY_UNRESOLVED', 'DEPENDENCY_NOT_READY', 'FEATURE_DISABLED']);
  const items = Object.fromEntries(purposes.map(name => [name, {node: root.querySelector('[data-purpose="' + name + '"]'), state: null, draft: null, operation: null, busy: false, result: '', confirmed: false, until: 0, timer: null, requireRead: false}]));
  let generation = 0, reading = false, loginNeeded = false;
  const requests = new Set();
  const validState = value => value && ['unknown', 'allow', 'deny'].includes(value.choice) && value.effective === false
    && ['unavailable', 'choice_only'].includes(value.capability) && value.choice_scope === 'current_browser'
    && Number.isSafeInteger(value.revision) && value.revision >= 0 && typeof value.reason === 'string';
  const validContext = value => value && value.schema_version === 3 && value.policy_version === 'registration-purpose-r1'
    && value.notice_version === NOTICE && value.is_test === true && ['eligible', 'unknown', 'ineligible'].includes(value.eligibility)
    && purposes.every(name => validState(value.purposes?.[name])) && ['unknown', 'allow', 'deny'].includes(value.legacy_advertising?.choice)
    && ['unset', 'allow', 'deny'].includes(value.site_session?.choice) && ['on', 'off'].includes(value.site_session?.effective);
  async function request(path, body) {
    const controller = new AbortController(); requests.add(controller);
    const timeout = setTimeout(() => controller.abort(), 6000);
    try {
      const response = await fetch('/api/v3/measurement/purposes/' + path, {
        method: body ? 'PATCH' : 'GET', credentials: 'same-origin', cache: 'no-store', referrerPolicy: 'no-referrer', signal: controller.signal,
        headers: {'Accept': 'application/json', ...(body ? {'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''} : {})},
        ...(body ? {body: JSON.stringify(body)} : {}),
      });
      let value;
      try { value = await response.json(); } catch (_) { throw Object.assign(new Error('shape'), {status: response.status}); }
      if (response.status !== 200) throw Object.assign(new Error('request'), {status: response.status, value, retryAfter: Number(response.headers.get('Retry-After'))});
      return value;
    } finally { clearTimeout(timeout); requests.delete(controller); }
  }
  function paint(name) {
    const item = items[name], state = item.state;
    item.node.querySelector('[data-purpose-choice]').textContent = item.confirmed && state ? words[state.choice === 'unknown' ? 'unknown' : 'saved_' + state.choice] : words.unconfirmed;
    item.node.querySelector('[data-purpose-capability]').textContent = item.confirmed && state ? words[state.capability] : words.unconfirmed;
    item.node.querySelector('[data-purpose-effective]').textContent = item.confirmed && state ? words.not_running : words.unconfirmed;
    item.node.querySelector('[data-purpose-result]').textContent = words[item.result] || '';
    item.node.querySelector('[data-purpose-technical]').textContent = [NOTICE, item.http ? 'HTTP ' + item.http : '', item.confirmed && state ? 'revision ' + state.revision : '', item.confirmed && safeReasons.has(state?.reason) ? state.reason : ''].filter(Boolean).join(' · ');
    item.node.dataset.purposeStatus = item.result;
    for (const input of item.node.querySelectorAll('input')) {
      input.checked = (item.draft ?? (item.confirmed ? state?.choice : null)) === input.value;
      input.disabled = reading || item.busy || (input.value === 'allow' && state?.capability === 'unavailable');
    }
    const save = item.node.querySelector('[data-purpose-save]');
    save.textContent = words[['failed', 'conflict'].includes(item.result) ? 'retry' : 'save'];
    save.disabled = reading || item.busy || item.draft === null || item.requireRead || Date.now() < item.until;
    item.node.querySelector('[data-purpose-deny]').disabled = reading || item.busy || item.requireRead || Date.now() < item.until;
    root.querySelector('[data-purpose-refresh]').disabled = reading || purposes.some(purpose => items[purpose].busy);
    item.node.querySelector('[data-purpose-login]').hidden = !(item.draft === 'allow' && (root.dataset.signedIn !== '1' || loginNeeded));
  }
  function discard() {
    generation++; requests.forEach(controller => controller.abort()); requests.clear(); reading = false;
    loginNeeded = false;
    for (const name of purposes) {
      clearTimeout(items[name].timer);
      Object.assign(items[name], {state: null, draft: null, operation: null, busy: false, result: '', confirmed: false, until: 0, requireRead: false, http: null}); paint(name);
    }
    root.querySelector('[data-purpose-legacy]').textContent = words.unconfirmed;
  }
  async function read() {
    discard(); reading = true;
    root.querySelector('[data-purpose-reading]').textContent = words.reading;
    purposes.forEach(paint);
    const epoch = generation;
    try {
      const value = await request('context');
      if (epoch !== generation) return;
      if (!validContext(value)) throw new Error('shape');
      purposes.forEach(name => { items[name].state = value.purposes[name]; items[name].confirmed = true; items[name].http = 200; });
      root.querySelector('[data-purpose-legacy]').textContent = words[value.legacy_advertising.choice === 'unknown' ? 'unknown' : 'legacy_' + value.legacy_advertising.choice];
      root.querySelector('[data-purpose-reading]').textContent = '';
    } catch (_) {
      if (epoch === generation) root.querySelector('[data-purpose-reading]').textContent = words.read_failed;
    } finally {
      if (epoch === generation) { reading = false; purposes.forEach(paint); }
    }
  }
  async function save(name) {
    const item = items[name];
    if (reading || item.busy || item.draft === null || item.requireRead || Date.now() < item.until) return;
    if (item.draft === 'allow' && (root.dataset.signedIn !== '1' || loginNeeded)) { item.result = 'login_required'; paint(name); return; }
    // Retrying an unknown result reuses the exact operation, revision and choice. No automatic replay.
    item.operation ??= {purpose: name, choice: item.draft, notice_version: NOTICE, expected_revision: item.state?.revision ?? 0, operation_id: crypto.randomUUID()};
    const operation = item.operation, epoch = generation;
    item.busy = true; item.result = 'saving'; paint(name);
    try {
      const value = await request('preferences', operation);
      if (epoch !== generation) return;
      if (value.schema_version !== 3 || value.purpose !== name || !['saved', 'unchanged'].includes(value.status) || !validState(value.state)) throw new Error('shape');
      item.state = value.state; item.confirmed = true; item.operation = null; item.http = 200;
      if (value.state.choice === operation.choice) { item.draft = null; item.result = 'saved'; }
      else item.result = 'conflict';
    } catch (error) {
      if (epoch !== generation) return;
      item.confirmed = false; item.result = 'failed'; item.http = Number.isInteger(error.status) && error.status >= 100 && error.status <= 599 ? error.status : null;
      if ([401, 403, 419].includes(error.status) || (error.status === 409 && error.value?.error?.code === 'OPERATION_CONFLICT')) {
        // Identity/operation scope is no longer trustworthy. Never carry another account's draft forward.
        discard(); loginNeeded = true; root.dataset.signedIn = '0';
        purposes.forEach(purpose => { items[purpose].result = 'login_required'; paint(purpose); });
        root.querySelectorAll('[data-purpose-login]').forEach(link => { link.hidden = false; });
        return;
      }
      if (error.status === 409) {
        item.operation = null; item.result = 'conflict';
        if (error.value?.schema_version === 3 && error.value.purpose === name && validState(error.value.state)) { item.state = error.value.state; item.confirmed = true; }
        if (error.value?.error?.code === 'NOTICE_CONFLICT') { item.requireRead = true; item.result = 'notice_changed'; }
      }
      if (error.status === 429) {
        item.result = 'limited';
        const seconds = Number.isFinite(error.retryAfter) && error.retryAfter > 0 ? Math.min(86400, error.retryAfter) : 1;
        item.until = Date.now() + seconds * 1000;
        item.timer = setTimeout(() => { if (epoch === generation) paint(name); }, seconds * 1000 + 1);
      }
    } finally { if (epoch === generation) { item.busy = false; paint(name); } }
  }
  for (const name of purposes) {
    const item = items[name];
    item.node.querySelectorAll('input').forEach(input => input.addEventListener('change', () => {
      item.draft = input.value; item.operation = null; item.result = input.value === 'allow' && (root.dataset.signedIn !== '1' || loginNeeded) ? 'login_required' : 'draft'; paint(name);
    }));
    item.node.querySelector('[data-purpose-save]').addEventListener('click', () => save(name));
    item.node.querySelector('[data-purpose-deny]').addEventListener('click', () => {
      if (item.draft !== 'deny') item.operation = null;
      item.draft = 'deny'; save(name);
    });
  }
  document.addEventListener('click', event => {
    if (event.target.closest('[data-c-open],[data-c-details]') && dialog.open) read();
  });
  root.querySelector('[data-purpose-refresh]').addEventListener('click', read);
  dialog.addEventListener('close', discard);
  dialog.addEventListener('keydown', event => {
    if (event.key !== 'Tab') return;
    const targets = [...dialog.querySelectorAll('button,input,a[href],summary,[tabindex]')]
      .filter(node => !node.disabled && node.tabIndex >= 0 && node.getClientRects().length && getComputedStyle(node).visibility !== 'hidden');
    if (!targets.length) return;
    const first = targets[0], last = targets.at(-1);
    if (!targets.includes(document.activeElement) || (!event.shiftKey && document.activeElement === last) || (event.shiftKey && document.activeElement === first)) {
      event.preventDefault(); (event.shiftKey ? last : first).focus();
    }
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') discard();
    else if (dialog.open) read();
  });
  window.addEventListener('pagehide', discard);
  purposes.forEach(paint);
})();
