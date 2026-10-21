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
    const prototypeContext = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'zh-CN', colorScheme: 'light', deviceScaleFactor: 1 });
    const prototypeExternal = new Set();
    await prototypeContext.route('**/*', route => { prototypeExternal.add(new URL(route.request().url()).hostname); return route.abort(); });
    const prototype = await prototypeContext.newPage();
    const pairs = [];
    await page.emulateMedia({ colorScheme: 'light' });
    const openReference = async name => {
        // This shell changes no approved fragment bytes, styles, state, or business data.
        await prototype.setContent('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>html,body{margin:0;color-scheme:light}</style>' + fragment);
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
        pairs.push({ name, state, ...dims, theme: 'light', fragment_wrapper: 'No export padding; immutable fragment at full viewport width', crop: !!crop });
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
    fs.writeFileSync(path.join(out, 'pairs.json'), JSON.stringify({ reference_commit: referenceCommit, reference_fragment_sha256: crypto.createHash('sha256').update(fragment).digest('hex'), browser: browser.version(), operating_system: os.platform() + ' ' + os.release(), source_files: Object.fromEntries(['h5-platform/resources/views/billing/desk.blade.php', 'h5-platform/public/admin-ui/admin.css', 'h5-platform/public/admin-ui/admin.js', 'h5-platform/resources/views/components/admin/workspace.blade.php'].map(file => [file, crypto.createHash('sha256').update(fs.readFileSync(path.join(root, file))).digest('hex')])), worktree_status: cp.execFileSync('git', ['status', '--porcelain'], { cwd: root, encoding: 'utf8' }).trim(), application_head: cp.execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim(), pairs, prototype_external_blocked: [...prototypeExternal], unmatched: ['invalid-price state: real validation differs intentionally from prototype sample', 'selected-order/payment/entitlement and support-action details: original did not fully draw these real extensions', 'dark mode, browser zoom changes, empty database and unconfigured-price screenshot not paired in this run'], scope: 'Price fields/preview paired with equal data. Orders/support common entry hierarchy paired; actual recent-order/case tables have no prototype equivalent and are not a same-data visual pass.', successful_price_writes: 0, official_payment_verified: false }, null, 2));
    await prototypeContext.close();
};
