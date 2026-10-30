document.querySelectorAll('[data-identity-form]').forEach(form => {
  const r1 = form.hasAttribute('data-registration-r1');
  const result = form.querySelector('[data-result]');
  const button = form.querySelector('button[type="submit"],button:not([type])');
  const check = form.querySelector('[data-registration-check]');
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
        window.location.assign('/identity/account');
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
        result.textContent = [error.message, ...Object.values(error.fields || {}).flat(), error.request_id ? 'Request ID: '+error.request_id : ''].filter(Boolean).join('\n');
        const retry = Number(response.headers.get('Retry-After'));
        if (response.status === 429 && retry) cooldown(button, retry); else button.disabled = false;
        return;
      }
      if (body.redirect_to) { window.location.assign(body.redirect_to); return; }
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
