(() => {
    'use strict';
    // All deadlines are absolute provider timestamps. Refresh never extends a payment window.
    document.querySelectorAll('[data-billing-deadline]').forEach(panel => {
        const deadline = Date.parse(panel.dataset.billingDeadline);
        const tick = () => {
            const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            const countdown = panel.querySelector('[data-countdown]');
            if (countdown && Number.isFinite(deadline)) {
                countdown.textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
            }
            if (!Number.isFinite(deadline) || seconds <= 0) {
                panel.querySelector('[data-deadline-status]').textContent = panel.dataset.expiredMessage;
                panel.querySelectorAll('[data-deadline-action]').forEach(action => {
                    if (action.tagName === 'A') {
                        action.removeAttribute('href');
                        action.setAttribute('aria-disabled', 'true');
                    } else action.disabled = true;
                });
                return false;
            }
            return true;
        };
        if (tick()) {
            const timer = setInterval(() => { if (!tick()) clearInterval(timer); }, 1000);
        }
    });
    document.querySelectorAll('[data-billing-form]').forEach(form => {
        form.addEventListener('submit', event => {
            if (form.dataset.submitting) { event.preventDefault(); return; }
            if (!form.checkValidity()) return;
            form.dataset.submitting = 'true';
            form.querySelectorAll('button[type=submit]').forEach(button => button.disabled = true);
        });
    });
})();
