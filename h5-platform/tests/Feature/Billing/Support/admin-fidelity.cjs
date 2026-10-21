// F09 pairs use the immutable approved fragment, with only the export wrapper omitted.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const crypto = require('node:crypto');
const os = require('node:os');
module.exports = async ({ page, browser, root, out, base, credentials, check, mark, selected, tab, snapshot }) => {
    const referenceCommit = 'bc5a24d63b7e23126bad9bb5519ea0e9fa8825cf';
    const fragment = cp.execFileSync('git', ['show', referenceCommit + ':docs/ui/08-admin-v2/approved-fragment.html'], { cwd: root, encoding: 'utf8' });
    const exported = cp.execFileSync('git', ['show', referenceCommit + ':docs/ui/08-admin-v2/index.html'], { cwd: root, encoding: 'utf8' });
    const prototypeContext = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'zh-CN', colorScheme: 'light', deviceScaleFactor: 1 });
    const prototypeExternal = new Set();
    await prototypeContext.route('**/*', route => { prototypeExternal.add(new URL(route.request().url()).hostname); return route.abort(); });
    const prototype = await prototypeContext.newPage();
    // Preserve the approved export's typography resets; omit only its outer iframe padding.
    const referenceDocument = await prototype.evaluate(html => new DOMParser().parseFromString(html, 'text/html').querySelector('#codex-visualization').getAttribute('data-srcdoc'), exported);
    assert(referenceDocument.includes(fragment), 'export contains the exact approved fragment');
    const pairs = [];
    const typography = [];
    const dialogs = [];
    await page.emulateMedia({ colorScheme: 'light' });
    const openReference = async name => {
        await prototype.setContent(referenceDocument);
        await prototype.locator('[data-module="billing"]').click();
        await prototype.getByRole('tab', { name, exact: true }).click();
    };
    const pair = async (name, state, crop) => {
        await snapshot(page, name + '-actual'); await snapshot(prototype, name + '-approved');
        if (crop) {
            await page.locator('[data-billing-pricing]').screenshot({ path: path.join(out, name + '-actual-card.png') });
            await prototype.locator('#h5-pricing').screenshot({ path: path.join(out, name + '-approved-card.png') });
        }
        const dims = await page.evaluate(() => ({ viewport: [innerWidth, innerHeight], dpr: devicePixelRatio, zoom: getComputedStyle(document.documentElement).zoom }));
        pairs.push({ name, state, ...dims, theme: 'light', fragment_wrapper: 'Fixed index inner document, including export typography; outer iframe wrapper omitted for equal canvas viewport', crop: !!crop });
    };
    await page.goto(base + '/admin/billing-desk?tab=prices'); await selected(page, '会员价格');
    const regular = page.locator('#billing-regular-usd'), first = page.locator('#billing-first-week-usd');
    const preview = page.locator('[data-billing-price-preview]');
    check('B01 one visible module title', await page.locator('h1:visible').count() === 1 && (await page.locator('h1:visible').innerText()) === '订单与会员');
    const gap = await page.evaluate(() => {
        const tabs = document.querySelector('#billing-tabs');
        const panel = document.querySelector('.billing-workspace > .h5-tab-panel');
        return panel.getBoundingClientRect().top - tabs.getBoundingClientRect().bottom;
    });
    check('B01 nested tab-to-panel spacing is the approved 22px', Math.abs(gap - 22) < 0.1);
    check('B01 recurring badge and two internal dollar prefixes', await page.getByText('每周自动续费', { exact: true }).isVisible() && await page.locator('.billing-money>span').count() === 2);
    const savedRegular = await regular.inputValue(), savedFirst = await first.inputValue();
    await regular.fill('13.34'); await first.fill('4.21');
    await page.waitForFunction(() => document.querySelector('[data-billing-price-preview]').textContent === '首周 $4.21，之后 $13.34 / 周');
    mark('B01 preview updates without saving or quoting');
    await openReference('会员价格');
    await prototype.locator('[name=price]').fill('13.34'); await prototype.locator('[name=promo]').fill('4.21');
    for (const [name, actualSelector, referenceSelector] of [['h1', '.h5-workspace-title', '#h5-screen h1'], ['h2', '[data-billing-pricing] h2', '#h5-pricing h2'], ['h3', '[data-billing-pricing] h3', '#h5-pricing h3']]) {
        const metrics = el => { const s = getComputedStyle(el); return { size: s.fontSize, line_height: s.lineHeight, family: s.fontFamily, weight: s.fontWeight }; };
        const actual = await page.locator(actualSelector).evaluate(metrics), reference = await prototype.locator(referenceSelector).evaluate(metrics);
        typography.push({ name, actual, reference });
        check('stable public ' + name + ' matches approved size and line height', actual.size === reference.size && Math.abs(parseFloat(actual.line_height) - parseFloat(reference.line_height)) < .05 && actual.family === reference.family);
    }
    await pair('prices-desktop', 'Owner; prices tab; unsaved 13.34 / 4.21 USD', true);
    await regular.fill('12.345');
    await page.waitForFunction(() => document.querySelector('[data-billing-price-preview]').textContent.startsWith('请输入有效'));
    check('B01 excess precision cannot display an effective offer', !(await preview.innerText()).includes('$'));
    await page.getByRole('button', { name: '保存测试价格', exact: true }).click();
    await page.locator('.h5-error').filter({ hasText: '两位小数' }).first().waitFor();
    check('B01 failed save keeps the invalid input', await regular.inputValue() === '12.345');
    await snapshot(page, 'prices-invalid-unpaired');
    await regular.fill('3.00'); await first.fill('3.01');
    await page.waitForFunction(() => document.querySelector('[data-billing-price-preview]').textContent.startsWith('请输入有效'));
    mark('B01 first-week price above regular price has no offer preview');
    await regular.fill('00012.30'); await first.fill('0.01');
    await page.waitForFunction(() => document.querySelector('[data-billing-price-preview]').textContent === '首周 $0.01，之后 $12.30 / 周');
    mark('B01 decimal and leading-zero normalization matches server display');
    await regular.fill('1000000');
    await page.waitForFunction(() => document.querySelector('[data-billing-price-preview]').textContent.startsWith('请输入有效'));
    await regular.fill('');
    await page.waitForFunction(() => document.querySelector('[data-billing-price-preview]').textContent.startsWith('请输入有效'));
    mark('B01 out-of-range and empty inputs cannot masquerade as current prices');
    // Explicit discard clears the server validation error before the paired valid state.
    await tab(page, '订单查询').click();
    const leave = page.locator('dialog.h5-confirm-leave');
    await leave.waitFor();
    await leave.getByRole('button', { name: '保存并继续', exact: true }).click();
    await leave.getByRole('alert').waitFor({ state: 'visible' });
    check('stable dialog failed save retains invalid amount and price tab', await regular.inputValue() === '' && await tab(page, '会员价格').getAttribute('aria-selected') === 'true');
    for (const [name, viewport] of [['unsaved-error-desktop', { width: 1280, height: 900 }], ['unsaved-error-short', { width: 375, height: 320 }]]) {
        await page.setViewportSize(viewport);
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        const layout = await leave.evaluate(el => {
            const rect = selector => { const r = el.querySelector(selector).getBoundingClientRect(); return { top: r.top, bottom: r.bottom, width: r.width, height: r.height }; };
            return { close: rect('.h5-dialog-close'), body: rect('.h5-dialog-body'), actions: rect('.h5-dialog-actions'), dialog_width: el.getBoundingClientRect().width, viewport: [innerWidth, innerHeight] };
        });
        check('stable ' + name + ' keeps text and scrolling below X, actions in viewport', layout.body.top >= layout.close.bottom - .1 && layout.actions.bottom <= viewport.height && layout.close.width === 44);
        dialogs.push({ name, ...layout });
        await snapshot(page, name + '-unpaired');
    }
    await leave.getByRole('button', { name: '关闭弹窗', exact: true }).click();
    await leave.waitFor({ state: 'detached' });
    check('stable X close preserves unsaved input', await regular.inputValue() === '' && await tab(page, '会员价格').getAttribute('aria-selected') === 'true');
    await page.setViewportSize({ width: 1280, height: 900 });
    await tab(page, '订单查询').click(); await leave.waitFor();
    await leave.getByRole('button', { name: '放弃修改', exact: true }).click();
    await leave.waitFor({ state: 'detached' }); await selected(page, '订单查询');
    await tab(page, '会员价格').click(); await selected(page, '会员价格');
    // Re-enter the paired state. No successful price write in this mode.
    await regular.fill('13.34'); await first.fill('4.21');
    await page.setViewportSize({ width: 375, height: 812 }); await prototype.setViewportSize({ width: 375, height: 812 });
    await page.locator('.fi-sidebar-close-overlay').waitFor({ state: 'hidden' });
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    const r = await regular.boundingBox(), f = await first.boundingBox();
    check('B01 narrow price fields stack without horizontal page overflow', f.y > r.y + r.height && await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await pair('prices-narrow', 'Owner; prices tab; unsaved 13.34 / 4.21 USD', true);
    await regular.fill(savedRegular); await first.fill(savedFirst);
    // Refresh clears the validation message and proves failed save did not change the current version/price.
    await page.reload(); await selected(page, '会员价格');
    check('B01 invalid preview/save did not change stored dollars', await regular.inputValue() === savedRegular && await first.inputValue() === savedFirst);
    await page.setViewportSize({ width: 1280, height: 900 }); await prototype.setViewportSize({ width: 1280, height: 900 });
    await tab(page, '订单查询').click(); await selected(page, '订单查询'); await openReference('订单查询');
    await pair('orders-desktop', 'Owner; orders tab; no selected order (actual recent-order table is a real extension)', false);
    await tab(page, '客服记录').click(); await selected(page, '客服记录'); await openReference('客服记录');
    await pair('support-desktop', 'Owner; support tab; no selected order (actual case table is a real extension)', false);
    await page.setViewportSize({ width: 375, height: 812 }); await prototype.setViewportSize({ width: 375, height: 812 });
    await pair('support-narrow', 'Owner; support tab; no selected order (real extension table can scroll)', false);
    await tab(page, '订单查询').click(); await selected(page, '订单查询'); await openReference('订单查询');
    await pair('orders-narrow', 'Owner; orders tab; no selected order', false);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.locator(`[data-billing-order="${credentials.samples.active.order_id}"]`).click();
    await page.getByText('当次约定金额', { exact: true }).waitFor();
    await snapshot(page, 'order-selected-extension');
    check('B02 selected order keeps agreed and paid amounts distinct', await page.getByText('当次约定金额', { exact: true }).count() === 1 && await page.getByText('$3.00 USD', { exact: true }).count() > 0);
    await page.getByRole('button', { name: '进入此订单的客服处理', exact: true }).click(); await selected(page, '客服记录');
    await snapshot(page, 'support-selected-extension');
    check('B03 support actions stay inside selected order and require verification', await page.getByRole('button', { name: '代用户停止未来续费', exact: true }).isDisabled());
    fs.writeFileSync(path.join(out, 'pairs.json'), JSON.stringify({ reference_commit: referenceCommit, reference_index_sha256: crypto.createHash('sha256').update(exported).digest('hex'), reference_document_sha256: crypto.createHash('sha256').update(referenceDocument).digest('hex'), reference_fragment_sha256: crypto.createHash('sha256').update(fragment).digest('hex'), browser: browser.version(), operating_system: os.platform() + ' ' + os.release(), source_files: Object.fromEntries(['h5-platform/resources/views/billing/desk.blade.php', 'h5-platform/public/admin-ui/admin.css', 'h5-platform/public/admin-ui/admin.js', 'h5-platform/resources/views/components/admin/workspace.blade.php'].map(file => [file, crypto.createHash('sha256').update(fs.readFileSync(path.join(root, file))).digest('hex')])), worktree_status: cp.execFileSync('git', ['status', '--porcelain'], { cwd: root, encoding: 'utf8' }).trim(), application_head: cp.execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim(), pairs, typography, dialogs, prototype_external_blocked: [...prototypeExternal], unmatched: ['invalid-price state: real validation differs intentionally from prototype sample', 'selected-order/payment/entitlement and support-action details: original did not fully draw these real extensions', 'Billing unsaved/error dialog is a real protection extension not drawn for the prototype prices tab; actual desktop/short-screen screenshots retained', 'dark mode, browser zoom changes, empty database and unconfigured-price screenshot not paired in this run'], scope: 'Price fields/preview paired with equal data. Orders/support common entry hierarchy paired; actual recent-order/case tables have no prototype equivalent and are not a same-data visual pass.', successful_price_writes: 0, official_payment_verified: false }, null, 2));
    await prototypeContext.close();
};
