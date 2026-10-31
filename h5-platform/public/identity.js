document.querySelectorAll('[data-identity-form]').forEach(form => {
  const r1 = form.hasAttribute('data-registration-r1');
  const result = form.querySelector('[data-result]');
  const button = form.querySelector('button[type="submit"],button:not([type])');
  const check = form.querySelector('[data-registration-check]');
  const purposes = r1 && form.querySelector('[data-registration-purposes]') ? registrationPurposes(form) : null;
  async function checkAccount() {
    if (!r1) return;
    check.disabled = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch('/api/v1/auth/me', {headers: {'Accept': 'application/json'}, credentials: 'same-origin', signal: controller.signal});
      const body = await response.json();
      const email = form.querySelector('[name="email"]').value.trim().toLowerCase();
      if (response.ok && body.viewer && body.viewer.email === email) {
        result.textContent = form.dataset.confirmed;
        if (purposes) {
          form.dataset.registrationComplete = 'true';
          check.hidden = true;
          // A status read proves the current sign-in, not creation by this registration POST.
          await purposes.complete(body.viewer);
        } else window.location.assign('/identity/account');
      }
    } catch (_) {
      // No identity result is inferred from an unavailable/anonymous status check.
    } finally { clearTimeout(timeout); check.disabled = false; }
  }
  async function unconfirmed() {
    form.dataset.registrationUnconfirmed = 'true';
    button.disabled = true;
    form.querySelectorAll('input[type="password"]').forEach(input => { input.value = ''; });
    result.textContent = form.dataset.unknown;
    check.hidden = false;
    await checkAccount();
  }
  if (check) check.addEventListener('click', checkAccount);
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (r1 && form.dataset.registrationComplete === 'true') return;
    if (r1 && form.dataset.registrationUnconfirmed === 'true') { await checkAccount(); return; }
    button.disabled = true; result.textContent = '';
    const controller = r1 ? new AbortController() : null;
    const timeout = r1 ? setTimeout(() => controller.abort(), 15000) : null;
    try {
      const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {'Accept': 'application/json'}, credentials: 'same-origin', ...(controller ? {signal: controller.signal} : {})});
      const body = await response.json();
      if (r1 && response.status >= 500) { await unconfirmed(); return; }
      if (!response.ok) {
        const error = body.error || {};
        result.textContent = [error.message, ...Object.values(error.fields || {}).flat(), !r1 && error.request_id ? 'Request ID: '+error.request_id : ''].filter(Boolean).join('\n');
        const detail = form.querySelector('[data-registration-error-detail]');
        if (detail) detail.textContent = `HTTP ${response.status}` + (typeof error.request_id === 'string' && /^[0-9a-f-]{36}$/i.test(error.request_id) ? ` · ${error.request_id}` : '');
        const retry = Number(response.headers.get('Retry-After'));
        if (response.status === 429 && retry) cooldown(button, retry); else button.disabled = false;
        return;
      }
      if (body.redirect_to) {
        if (purposes) {
          form.dataset.registrationComplete = 'true';
          form.querySelectorAll('input[type="password"]').forEach(input => { input.value = ''; });
          await purposes.complete(body.identity?.viewer, true);
        } else window.location.assign(body.redirect_to);
        return;
      }
      if (r1) { await unconfirmed(); return; }
      result.textContent = body.message || 'OK';
      if (button.dataset.cooldown) cooldown(button, Number(button.dataset.cooldown)); else button.disabled = false;
    } catch (_) {
      if (r1) { await unconfirmed(); return; }
      result.textContent = document.documentElement.lang.startsWith('zh') ? '请求未完成，请稍后重试。' : 'The request could not be completed. Please retry.';
      button.disabled = false;
    } finally { if (timeout !== null) clearTimeout(timeout); }
  });
});
function cooldown(button, seconds) { const title = button.textContent; let left = Math.ceil(seconds); button.disabled = true; button.textContent = title + ' ('+left+'s)'; const timer = setInterval(() => { left--; if (left <= 0) {clearInterval(timer); button.disabled=false;button.textContent=title;} else button.textContent=title+' ('+left+'s)'; },1000); }

// Registration-only consumer of the public v3 HTTP contract. No provider internals or storage.
function registrationPurposes(form) {
  const text = JSON.parse(form.querySelector('[data-purpose-messages]').textContent);
  const notice = 'c-purpose-choice-only-r1-v1';
  const items = new Map();
  const proceed = form.querySelector('[data-registration-continue]');
  let context = null, expectedViewer = null, complete = false, stopped = false;
  const validState = s => s && ['unknown','allow','deny'].includes(s.choice) && s.effective === false && ['choice_only','unavailable'].includes(s.capability)
    && s.choice_scope === 'current_browser' && Number.isInteger(s.revision) && s.revision >= 0 && s.revision < 2147483647
    && (s.reason === null || typeof s.reason === 'string' && /^[A-Z_]{1,80}$/.test(s.reason));
  async function http(url, options = {}) {
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch(url, {credentials:'same-origin', ...options, signal:controller.signal, headers:{Accept:'application/json', ...options.headers}});
      return {response, body:await response.json()};
    } finally { clearTimeout(timeout); }
  }
  function render(item) {
    const unconfirmedChoice = item.status === 'unconfirmed'
      ? (validState(item.state) ? `${text.last_confirmed_choice}: ${text[item.state.choice]}` : text.saved_choice_unconfirmed)
      : null;
    const label = item.status === 'unconfirmed' ? `${unconfirmedChoice}\n${text.off}`
      : item.state ? `${text[item.state.choice]}\n${text.off}` : text.unavailable;
    item.output.textContent = `${item.title}: ${label} — ${text[item.problem || item.status] || text.unconfirmed}` + (item.dirty ? ` · ${text.draft}: ${text['draft_' + item.desired]}` : '');
    item.output.dataset.status = item.status;
    item.retry.hidden = !complete || stopped || !['unconfirmed','conflict'].includes(item.status);
    item.retry.disabled = item.busy || Date.now() < item.retryAfter;
    item.latest.hidden = stopped || item.status !== 'conflict';
    item.latest.disabled = item.busy;
    item.detail.textContent = [item.state?.reason, item.http ? `HTTP ${item.http}` : ''].filter(Boolean).join(' · ');
    item.input.disabled = complete || stopped || item.state?.capability !== 'choice_only';
    if (!item.dirty) item.input.checked = item.state?.choice === 'allow';
  }
  function discard(message) {
    stopped = true; context = null; expectedViewer = null;
    for (const item of items.values()) { item.dirty = false; item.command = null; item.desired = null; item.state = null; item.status = 'not_requested'; render(item); }
    form.querySelector('[data-result]').textContent = message;
  }
  for (const input of form.querySelectorAll('[data-registration-purpose]')) {
    const purpose = input.dataset.registrationPurpose;
    const item = {purpose,input,title:text[purpose + '_name'] || input.closest('label').textContent.trim(),output:form.querySelector(`[data-purpose-state="${purpose}"]`),retry:form.querySelector(`[data-purpose-retry="${purpose}"]`),latest:form.querySelector(`[data-purpose-latest="${purpose}"]`),detail:form.querySelector(`[data-purpose-detail="${purpose}"]`),
      state:null,desired:null,dirty:false,status:'not_requested',command:null,busy:false,retryAfter:0,problem:null,http:null};
    item.retry.setAttribute('aria-label', `${text.retry}: ${item.title}`);
    items.set(purpose,item);
    input.addEventListener('change', () => { item.desired = input.checked ? 'allow' : 'deny'; item.dirty = item.desired !== item.state?.choice; render(item); });
    item.latest.addEventListener('click', () => { if (!stopped && !item.busy) { item.dirty = false; item.desired = null; item.command = null; item.problem = null; item.status = 'not_requested'; render(item); } });
    item.retry.addEventListener('click', async () => {
      if (item.busy || stopped || !complete || Date.now() < item.retryAfter) return;
      // Conflict rebasing is an explicit user action, never a response to a slow GET/409.
      if (item.status === 'conflict') item.command = null;
      await submit(item);
    });
  }
  async function read() {
    const {response,body} = await http('/api/v3/measurement/purposes/context');
    if (!response.ok || body.schema_version !== 3 || body.policy_version !== 'registration-purpose-r1' || body.notice_version !== notice || body.is_test !== true
      || ![...items.keys()].every(p => validState(body.purposes?.[p]))) throw new Error('Purpose context unavailable');
    if (stopped) return;
    context = body;
    for (const item of items.values()) { item.state = body.purposes[item.purpose]; render(item); }
    const site = body.site_session;
    // When present, the existing C controller owns the live status and independent off control.
    if (!document.querySelector('[data-measurement-c]')) form.querySelector('[data-purpose-site]').textContent = site?.effective === 'on' ? text.site_on : site?.effective === 'off' ? text.site_off : text.site_unknown;
    const legacy = body.legacy_advertising?.choice;
    form.querySelector('[data-purpose-legacy]').textContent = `${text.legacy}: ${text[legacy] || text.unknown}`;
  }
  const ready = read().catch(() => { for (const item of items.values()) render(item); });
  async function sameAccount() {
    try {
      const {response,body} = await http('/api/v1/auth/me');
      if (response.status === 401 || (response.ok && body.viewer === null)) { discard(text.account_unknown); return false; }
      if (!response.ok || !body.viewer) throw new Error('Identity unavailable');
      if (!expectedViewer || body.viewer.id !== expectedViewer.id || body.viewer.email !== expectedViewer.email) { discard(text.account_changed); return false; }
      return true;
    } catch (_) { // A temporary identity read failure pauses; it never authorizes a PATCH.
      form.querySelector('[data-result]').textContent = text.account_unknown;
      return false;
    }
  }
  async function submit(item) {
    if (stopped || !item.dirty || item.busy) return;
    item.busy = true; item.problem = null; render(item);
    try {
      if (!context || !item.state) { await read(); }
      const {response:csrfResponse,body:csrf} = await http('/api/v1/auth/csrf');
      if (!csrfResponse.ok || typeof csrf.csrf_token !== 'string') throw new Error('CSRF unavailable');
      const fixedCsrf = csrf.csrf_token;
      // Bind this attempt to the token obtained BEFORE checking the current necessary identity.
      // A switch during token lookup is detected below; a later switch must fail with this old token.
      if (!await sameAccount()) { if (!stopped) item.status = 'unconfirmed'; return; }
      if (!item.command) {
        item.command = {purpose:item.purpose,choice:item.desired,notice_version:context.notice_version,expected_revision:item.state.revision,operation_id:crypto.randomUUID()};
      }
      const {response,body} = await http('/api/v3/measurement/purposes/preferences', {method:'PATCH',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':fixedCsrf},body:JSON.stringify(item.command)});
      item.http = response.status;
      if (response.status === 429) {
        const seconds = Number(response.headers.get('Retry-After'));
        if (Number.isFinite(seconds) && seconds > 0) { const delay = Math.min(2147483000, seconds * 1000); item.retryAfter = Date.now() + delay; setTimeout(() => render(item), delay + 1); }
      }
      if (response.status === 409) {
        let current = body.schema_version === 3 && body.purpose === item.purpose && validState(body.state);
        if (current) item.state = body.state;
        else { try { await read(); current = true; } catch (_) {} }
        item.command = null; // An explicit retry must rebase, never replay the conflicted operation.
        item.status = current ? 'conflict' : 'unconfirmed';
      } else if (response.ok && body.schema_version === 3 && body.purpose === item.purpose && ['saved','unchanged'].includes(body.status) && validState(body.state)) {
        item.state = body.state; item.status = body.status; item.dirty = false; item.command = null;
      } else if ([401,403].includes(response.status)) {
        discard(text.account_unknown);
      } else { item.status = 'unconfirmed'; item.problem = ({419:'expired',422:'invalid',429:'rate_limited'})[response.status] || null; }
    } catch (_) { item.status = 'unconfirmed'; }
    finally { item.busy = false; render(item); }
  }
  return {async complete(viewer, creationConfirmed = false) {
    if (complete) return;
    complete = true;
    form.querySelector('button[type=submit]').hidden = true;
    const email = form.querySelector('[name="email"]').value.trim().toLowerCase();
    expectedViewer = viewer && typeof viewer.id === 'string' && viewer.email === email ? {id:viewer.id,email:viewer.email} : null;
    proceed.hidden = false;
    form.querySelectorAll('input:not([data-registration-purpose])').forEach(input => { input.readOnly = true; if (input.type === 'checkbox') input.disabled = true; });
    form.querySelector('[data-result]').textContent = creationConfirmed ? text.created : text.current_account;
    await ready;
    if (!expectedViewer) { discard(text.account_unknown); return; }
    if (!await sameAccount()) {
      if (!stopped) for (const item of items.values()) { if (item.dirty) item.status = 'unconfirmed'; render(item); }
      return;
    }
    // A successful necessary account is never rolled back by either independent purpose command.
    for (const item of items.values()) { if (item.dirty) await submit(item); else render(item); }
  }};
}
