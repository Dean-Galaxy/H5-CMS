// Real Laravel/Livewire HTTP round trips, no staff or business data.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.H5_PLAYWRIGHT_MODULE || 'playwright');
const url = process.argv[2] || 'http://127.0.0.1:8199/admin-ui-livewire';
assert(['127.0.0.1', 'localhost'].includes(new URL(url).hostname));
(async () => {
    const browser = await chromium.launch({ executablePath: process.env.H5_CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
    const page = await browser.newPage();
    const passed = [], errors = [], external = [];
    let responses = 0;
    await page.route('**/*', route => {
        if (new URL(route.request().url()).origin !== new URL(url).origin) { external.push(route.request().url()); return route.abort(); }
        return route.continue();
    });
    page.on('pageerror', e => errors.push(e.message));
    page.on('response', r => { if (r.request().method() === 'POST' && r.url().includes('livewire')) responses++; });
    const tab = id => page.locator('#livewire-tabs-tab-' + id);
    const selected = id => page.waitForFunction(id => document.getElementById('livewire-tabs-tab-' + id)?.getAttribute('aria-selected') === 'true', id);
    const settled = async () => { await page.waitForFunction(() => [...document.querySelectorAll('[data-admin-tab]')].every(el => !el.disabled && !el.hasAttribute('aria-disabled'))); };
    const focused = id => page.evaluate(id => document.activeElement?.id === id, id);
    const check = (name, ok) => { assert(ok, name); passed.push(name); };
    try {
        await page.goto(url); await page.waitForFunction(() => window.Livewire && window.H5Admin);
        await tab('orders').focus();
        for (const [key, id] of [['ArrowRight', 'prices'], ['End', 'history'], ['Home', 'orders'], ['ArrowLeft', 'history']]) {
            await page.keyboard.press(key); await selected(id); await settled();
            check(key + ' retains focus after real server update', await focused('livewire-tabs-tab-' + id));
        }
        check('requests used the real Livewire endpoint', responses >= 4);

        // Loading must suppress repeated actions without disabling the focused DOM element.
        const before = Number(await page.locator('#server-requests').textContent());
        await page.keyboard.press('Home');
        await page.waitForFunction(() => document.getElementById('livewire-tabs-tab-orders').getAttribute('aria-disabled') === 'true');
        await tab('prices').evaluate(el => el.click());
        await selected('orders'); await settled();
        check('loading blocks an extra tab request', Number(await page.locator('#server-requests').textContent()) === before + 1);

        await tab('prices').click(); await page.locator('#outside-input').focus();
        await selected('prices'); await settled();
        check('mouse response does not steal input focus', await focused('outside-input'));

        await tab('prices').focus(); await page.keyboard.press('Home');
        await page.locator('#open-dialog').click(); await page.locator('#dialog-input').focus();
        await selected('orders'); await settled();
        check('server response does not steal modal focus', await focused('dialog-input'));
        await page.keyboard.press('Escape');

        await page.getByLabel('拒绝操作记录').check();
        const rejectedBefore = Number(await page.locator('#server-requests').textContent());
        await tab('orders').focus(); await page.keyboard.press('End');
        await page.getByRole('alert').waitFor(); await settled();
        check('server rejection leaves selection unchanged', await tab('orders').getAttribute('aria-selected') === 'true' && Number(await page.locator('#server-requests').textContent()) === rejectedBefore + 1);
        check('rejection does not force focus elsewhere', await focused('livewire-tabs-tab-history'));
        check('no external request or script error', external.length === 0 && errors.length === 0);
        const result = { passed: passed.length, cases: passed, responses, external, errors, boundary: 'Real Livewire fixture; no staff auth or business acceptance' };
        fs.mkdirSync('.runtime/admin-ui-livewire-review', { recursive: true });
        fs.writeFileSync('.runtime/admin-ui-livewire-review/browser.json', JSON.stringify(result, null, 2));
        console.log(JSON.stringify(result));
    } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
