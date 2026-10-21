/* Shared UI mechanics, with no business data or automatic success assumptions. */
(() => {
    'use strict';
    if (window.H5Admin) return;
    const acceptedTabs = new WeakSet();
    const protections = new WeakMap();
    let asking = false;
    let promptId = 0;
    const element = value => typeof value === 'string' ? document.getElementById(value) : value;
    const unavailable = button => button.disabled || button.getAttribute('aria-disabled') === 'true';

    function openDialog(value) {
        const dialog = element(value);
        if (!(dialog instanceof HTMLDialogElement)) return false;
        if (!dialog.open) dialog.showModal();
        return true;
    }

    function closeDialog(value) {
        const dialog = element(value);
        if (!(dialog instanceof HTMLDialogElement)) return false;
        if (dialog.open) dialog.close();
        return true;
    }

    function requestClose(dialog) {
        const event = new CustomEvent('h5-admin:dialog-close-request', { bubbles: true, cancelable: true, detail: { dialog } });
        if (dialog.dispatchEvent(event)) closeDialog(dialog);
    }

    function activateTab(button) {
        if (!(button instanceof HTMLButtonElement) || !button.isConnected || unavailable(button)) return;
        acceptedTabs.add(button);
        button.click();
        acceptedTabs.delete(button);
    }

    function selectLocalTab(group, selected) {
        for (const button of group.querySelectorAll('[data-admin-tab]')) {
            const active = button === selected;
            button.setAttribute('aria-selected', String(active));
            button.tabIndex = active ? 0 : -1;
            const panel = document.getElementById(button.getAttribute('aria-controls'));
            if (panel) panel.hidden = !active;
        }
        group.dispatchEvent(new CustomEvent('h5-admin:tab-change', { bubbles: true, detail: { id: selected.dataset.adminTab, group: group.id, button: selected } }));
    }

    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) return;
        const close = event.target.closest('[data-admin-close]');
        if (close) {
            const dialog = close.closest('dialog[data-admin-dialog]');
            if (dialog) { event.preventDefault(); requestClose(dialog); }
            return;
        }
        const button = event.target.closest('button[data-admin-tab]');
        const group = button?.closest('[data-admin-tabs]');
        if (!group) return;
        if (unavailable(button)) {
            event.preventDefault(); event.stopImmediatePropagation(); return;
        }
        if (button.getAttribute('aria-selected') === 'true') {
            event.preventDefault(); event.stopImmediatePropagation(); return;
        }
        if (!acceptedTabs.has(button)) {
            const requested = new CustomEvent('h5-admin:tab-request', {
                bubbles: true, cancelable: true,
                detail: { id: button.dataset.adminTab, group: group.id, button, select: () => activateTab(button) },
            });
            if (!button.dispatchEvent(requested)) {
                event.preventDefault(); event.stopImmediatePropagation(); return;
            }
        }
        if (group.dataset.adminMode === 'local') selectLocalTab(group, button);
        // Livewire tabs deliberately wait for their authorized server method/render.
    }, true);

    document.addEventListener('keydown', event => {
        if (!(event.target instanceof Element)) return;
        const button = event.target.closest('button[data-admin-tab]');
        const group = button?.closest('[data-admin-tabs]');
        if (!group || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        if (unavailable(button)) return;
        const tabs = [...group.querySelectorAll('button[data-admin-tab]')].filter(tab => !unavailable(tab));
        const index = tabs.indexOf(button);
        let next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[next]?.focus();
        tabs[next]?.click();
    });

    document.addEventListener('cancel', event => {
        const dialog = event.target;
        if (dialog instanceof HTMLDialogElement && dialog.hasAttribute('data-admin-dialog')) {
            event.preventDefault(); requestClose(dialog);
        }
    }, true);

    function confirmUnsaved({ save, discard } = {}) {
        // A second pending navigation must not inherit the first one's approval.
        if (asking) return Promise.resolve(false);
        asking = true;
        return new Promise(resolve => {
            const opener = document.activeElement;
            const dialog = document.createElement('dialog');
            const titleId = 'h5-unsaved-' + (++promptId);
            dialog.className = 'h5-admin-dialog h5-confirm-leave';
            dialog.setAttribute('aria-labelledby', titleId);
            // Static text only; form values and server messages are never interpolated.
            dialog.innerHTML = '<div class="h5-dialog-header"><h2>修改尚未保存</h2></div><div class="h5-dialog-body"><p>保存成功后再继续，或放弃本次修改。</p><p class="h5-error" role="alert" hidden></p></div><div class="h5-dialog-actions"><button type="button" class="h5-button" data-choice="stay" autofocus>继续编辑</button><button type="button" class="h5-button h5-danger" data-choice="discard">放弃修改</button><button type="button" class="h5-button h5-primary" data-choice="save">保存并继续</button></div>';
            dialog.querySelector('h2').id = titleId;
            const buttons = [...dialog.querySelectorAll('button')];
            const error = dialog.querySelector('[role=alert]');
            const callbacks = { save, discard };
            for (const button of buttons) {
                if (button.dataset.choice !== 'stay') button.disabled = typeof callbacks[button.dataset.choice] !== 'function';
            }
            let busy = false;
            let settled = false;
            const finish = result => {
                if (settled) return;
                settled = true; asking = false;
                dialog.close(); dialog.remove();
                if (opener instanceof HTMLElement && opener.isConnected) opener.focus();
                resolve(result);
            };
            dialog.addEventListener('cancel', event => { event.preventDefault(); if (!busy) finish(false); });
            dialog.addEventListener('close', () => finish(false));
            dialog.addEventListener('click', async event => {
                const button = event.target.closest('button[data-choice]');
                if (!button || busy || button.disabled) return;
                const choice = button.dataset.choice;
                if (choice === 'stay') { finish(false); return; }
                busy = true; error.hidden = true;
                buttons.forEach(item => { item.disabled = true; });
                dialog.setAttribute('aria-busy', 'true');
                try {
                    if (await callbacks[choice]() === true) { finish(true); return; }
                } catch { /* Keep failed/unknown operations in the editor. */ }
                busy = false;
                dialog.removeAttribute('aria-busy');
                error.textContent = choice === 'save' ? '未确认保存成功，请继续编辑并检查表单或网络。' : '未能还原修改，请继续编辑。';
                error.hidden = false;
                for (const item of buttons) item.disabled = item.dataset.choice !== 'stay' && typeof callbacks[item.dataset.choice] !== 'function';
                dialog.querySelector('[data-choice=stay]').focus();
            });
            document.body.append(dialog);
            dialog.showModal();
        });
    }

    /** Modules own dirty state and real save/revert; this helper owns leave UI only. */
    function protect(root, { isDirty, save, discard }) {
        root = element(root);
        if (!(root instanceof Element) || typeof isDirty !== 'function') throw new TypeError('protect requires a workspace element and isDirty callback');
        protections.get(root)?.();
        const dirty = () => root.isConnected && isDirty() === true;
        const check = () => confirmUnsaved({ save, discard });
        const tab = event => {
            if (!dirty() || event.defaultPrevented) return;
            event.preventDefault();
            check().then(allowed => { if (allowed) event.detail.select(); });
        };
        const close = event => {
            if (!dirty() || event.defaultPrevented) return;
            event.preventDefault();
            check().then(allowed => { if (allowed) closeDialog(event.detail.dialog); });
        };
        const navigation = event => {
            if (!dirty() || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !(event.target instanceof Element)) return;
            const link = event.target.closest('a[href]');
            if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
            const url = new URL(link.href, location.href);
            if (!['http:', 'https:'].includes(url.protocol) || (url.origin === location.origin && url.pathname === location.pathname && url.search === location.search)) return;
            event.preventDefault(); event.stopImmediatePropagation();
            check().then(allowed => { if (allowed) location.assign(url.href); });
        };
        const unload = event => { if (dirty()) { event.preventDefault(); event.returnValue = ''; } };
        root.addEventListener('h5-admin:tab-request', tab);
        root.addEventListener('h5-admin:dialog-close-request', close);
        document.addEventListener('click', navigation, true);
        window.addEventListener('beforeunload', unload);
        const dispose = () => {
            root.removeEventListener('h5-admin:tab-request', tab);
            root.removeEventListener('h5-admin:dialog-close-request', close);
            document.removeEventListener('click', navigation, true);
            window.removeEventListener('beforeunload', unload);
            protections.delete(root);
        };
        protections.set(root, dispose);
        return dispose;
    }

    window.H5Admin = Object.freeze({ version: '1', openDialog, closeDialog, activateTab, confirmUnsaved, protect });
    document.dispatchEvent(new CustomEvent('h5-admin:ready'));
})();
