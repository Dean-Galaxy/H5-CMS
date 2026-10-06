document.querySelectorAll('[data-identity-form]').forEach(form => form.addEventListener('submit', async event => {
  event.preventDefault(); const result = form.querySelector('[data-result]'); const button = form.querySelector('button'); button.disabled = true; result.textContent = '';
  try { const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {'Accept': 'application/json'}, credentials: 'same-origin'}); const body = await response.json();
    if (!response.ok) { const error = body.error || {}; result.textContent = [error.message, ...Object.values(error.fields || {}).flat(), error.request_id ? 'Request ID: '+error.request_id : ''].filter(Boolean).join('\n');
      const retry = Number(response.headers.get('Retry-After')); if (response.status === 429 && retry) cooldown(button, retry); else button.disabled = false; return; }
    if (body.redirect_to) { window.location.assign(body.redirect_to); return; } result.textContent = body.message || 'OK'; if (button.dataset.cooldown) cooldown(button, Number(button.dataset.cooldown)); else button.disabled = false;
  } catch (_) { result.textContent = document.documentElement.lang.startsWith('zh') ? '请求未完成，请稍后重试。' : 'The request could not be completed. Please retry.'; button.disabled = false; }
}));
function cooldown(button, seconds) { const title = button.textContent; let left = Math.ceil(seconds); button.disabled = true; button.textContent = title + ' ('+left+'s)'; const timer = setInterval(() => { left--; if (left <= 0) {clearInterval(timer); button.disabled=false;button.textContent=title;} else button.textContent=title+' ('+left+'s)'; },1000); }
