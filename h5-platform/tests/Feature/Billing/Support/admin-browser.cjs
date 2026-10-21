// Real employee password + MFA and browser interactions on the guarded disposable test server.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const { chromium } = require(process.env.H5_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../../../../..');
const runtime = path.join(root, '.runtime');
const credentials = JSON.parse(fs.readFileSync(path.join(runtime, '03-admin-browser-credentials.json'), 'utf8'));
const server = JSON.parse(fs.readFileSync(path.join(runtime, '03-admin-browser-server.json'), 'utf8'));
assert(credentials.root === root && credentials.is_test === true && server.root === root);
assert(credentials.database === server.database && credentials.database.endsWith('_test'));
const base = `http://127.0.0.1:${server.port}`;
const sharedSmoke = process.argv.includes('--shared-smoke');
const out = path.join(runtime, sharedSmoke ? '03-admin-shared-browser' : '03-admin-ui-browser');
fs.mkdirSync(out, { recursive: true, mode: 0o700 });
const checks = [];
const errors = [];
const unexpected = [];
const external = new Set();
const externalFinished = new Set();
let step = 'start';
const mark = name => { checks.push(name); console.log(JSON.stringify({ passed: checks.length, check: name })); };
const check = (name, condition) => { assert(condition, name); mark(name); };
const snapshot = async (page, name) => { await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: path.join(out, name + '.png'), fullPage: true }); };
const selected = async (page, name) => page.waitForFunction(label => [...document.querySelectorAll('[role=tab]')].some(el => el.textContent.trim() === label && el.getAttribute('aria-selected') === 'true'), name);
const tab = (page, name) => page.getByRole('tab', { name, exact: true });
const prompt = page => page.locator('dialog.h5-confirm-leave');
const closePrompt = async (page, name) => { await prompt(page).getByRole('button', { name, exact: true }).click(); await prompt(page).waitFor({ state: 'detached' }); };
let browser;
let lastLoginStarted = 0;
async function context() {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'zh-CN' });
    await ctx.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.origin === base || ['data:', 'blob:'].includes(url.protocol)) return route.continue();
        external.add(url.hostname); return route.abort();
    });
    ctx.on('page', page => {
        page.on('pageerror', error => errors.push(error.message));
        page.on('requestfinished', request => { const url = new URL(request.url()); if (url.origin !== base && ['http:', 'https:'].includes(url.protocol)) externalFinished.add(url.hostname); });
        page.on('response', response => { if (response.status() >= 500) unexpected.push({ path: new URL(response.url()).pathname, status: response.status() }); });
    });
    return ctx;
}
async function login(ctx, role) {
    // Each real login uses two authenticate calls; respect the existing 5/minute IP limit.
    const pause = Math.max(0, lastLoginStarted + 31000 - Date.now());
    if (pause) { console.log(JSON.stringify({ waiting_for_login_rate_limit_ms: pause })); await new Promise(resolve => setTimeout(resolve, pause)); }
    lastLoginStarted = Date.now();
    step = `login-${role}`;
    const page = await ctx.newPage();
    const entry = credentials.staff[role];
    await page.goto(base + '/admin/login');
    await page.locator('[id="form.email"]').fill(entry.email);
    await page.locator('[id="form.password"]').fill(entry.password);
    await page.getByRole('button', { name: '登录', exact: true }).click();
    await page.locator('#multiFactorChallengeForm').waitFor();
    cp.execFileSync('python3', [path.join(__dirname, 'admin-browser.py'), 'code', role, '--port', String(server.port)], { cwd: root, stdio: 'pipe' });
    const { code } = JSON.parse(fs.readFileSync(entry.totp_file, 'utf8'));
    const codeInputs = page.locator('#multiFactorChallengeForm input:not([type=hidden]):visible');
    assert([1, 6].includes(await codeInputs.count()), 'supported MFA input layout');
    await codeInputs.first().fill(code); // The native component supports pasting all six digits.
    await page.waitForFunction(expected => [...document.querySelectorAll('#multiFactorChallengeForm input:not([type=hidden])')].filter(e => e.offsetParent !== null).map(e => e.value).join('') === expected, code);
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await page.locator('#multiFactorChallengeForm button[type=submit]').click();
    await page.waitForURL(url => url.pathname !== '/admin/login');
    check(`${role}: real password and MFA login`, !page.url().includes('/admin/login'));
    return page;
}
(async () => {
    browser = await chromium.launch({ executablePath: process.env.H5_CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
    try {
        const ownerContext = await context();
        const page = await login(ownerContext, 'owner');
        if (sharedSmoke) {
            step = 'shared-1.0.5';
            await page.goto(base + '/admin/billing-desk'); await selected(page, '订单查询');
            const avatar = page.locator('img[src$="/admin-ui/staff-avatar.svg"]').first();
            await avatar.waitFor();
            await page.waitForFunction(() => [...document.images].some(img => img.src.endsWith('/admin-ui/staff-avatar.svg') && img.complete && img.naturalWidth > 0));
            mark('same-origin staff avatar loads successfully');
            check('owner still sees three authorized tabs', await page.getByRole('tab').count() === 3);
            await tab(page, '订单查询').focus(); await page.keyboard.press('ArrowRight'); await selected(page, '会员价格');
            await page.keyboard.press('End'); await selected(page, '客服记录');
            await page.keyboard.press('Home'); await selected(page, '订单查询');
            mark('shared patch preserves continuous real Livewire keyboard navigation');
            await tab(page, '会员价格').click(); await selected(page, '会员价格');
            const amount = page.locator('#billing-regular-usd');
            check('previously saved dollar price remains exact', await amount.inputValue() === '12.34');
            await amount.fill('12.345'); await tab(page, '订单查询').click(); await prompt(page).waitFor();
            await prompt(page).getByRole('button', { name: '保存并继续' }).click();
            await prompt(page).getByRole('alert').waitFor({ state: 'visible' });
            check('shared patch preserves failed-save input and active tab', await amount.inputValue() === '12.345' && await tab(page, '会员价格').getAttribute('aria-selected') === 'true');
            await closePrompt(page, '继续编辑');
            await tab(page, '订单查询').click(); await closePrompt(page, '放弃修改'); await selected(page, '订单查询');
            await tab(page, '会员价格').click(); await selected(page, '会员价格');
            check('shared patch discard restores original price without new version', await amount.inputValue() === '12.34');
            await snapshot(page, 'shared-owner-prices');
            await page.setViewportSize({ width: 375, height: 812 });
            check('shared patch mobile has no document horizontal overflow', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            await snapshot(page, 'shared-mobile-prices');
            check('shared patch has no external avatar or other external requests', external.size === 0 && externalFinished.size === 0);
            check('shared patch has no page errors or HTTP 5xx', errors.length === 0 && unexpected.length === 0);
            step = 'complete';
            return;
        }
        step = 'owner-orders';
        await page.goto(base + '/admin/billing-desk');
        await selected(page, '订单查询');
        check('owner three tabs and one visible panel', await page.getByRole('tab').count() === 3 && await page.getByRole('tabpanel').count() === 1);
        await page.locator(`[data-billing-order="${credentials.samples.active.order_id}"]`).click();
        await page.getByRole('button', { name: '进入此订单的客服处理' }).waitFor();
        const orderText = await page.locator('[data-billing-workspace]').innerText();
        check('paid order, original renewal and zero refund show exact USD', orderText.includes('$3.00 USD') && orderText.includes('$12.00 USD') && orderText.includes('$0.00 USD'));
        check('unconfigured official boundary visible', orderText.includes('Stripe 未配置；官方测试未验证'));
        await snapshot(page, 'owner-orders');
        await page.locator(`[data-billing-order="${credentials.samples.partial_refund.order_id}"]`).click();
        await page.getByText('$1.25 USD', { exact: true }).waitFor();
        mark('partial refund shows $1.25 USD');
        await snapshot(page, 'owner-partial-refund');

        step = 'prices-and-dirty';
        await tab(page, '会员价格').click(); await selected(page, '会员价格');
        const regular = page.locator('#billing-regular-usd');
        const first = page.locator('#billing-first-week-usd');
        check('stored cents refill as dollar strings', await regular.inputValue() === '12.00' && await first.inputValue() === '3.00');
        await regular.fill('12.345');
        await tab(page, '订单查询').click(); await prompt(page).waitFor();
        await closePrompt(page, '继续编辑');
        check('continue editing retains amount and tab', await regular.inputValue() === '12.345' && await tab(page, '会员价格').getAttribute('aria-selected') === 'true');
        await tab(page, '订单查询').click();
        await prompt(page).getByRole('button', { name: '保存并继续' }).click();
        await prompt(page).getByRole('alert').waitFor({ state: 'visible' });
        check('failed precision validation cannot navigate or lose input', await regular.inputValue() === '12.345' && await tab(page, '会员价格').getAttribute('aria-selected') === 'true');
        await snapshot(page, 'price-validation-keeps-input');
        await closePrompt(page, '继续编辑');
        await tab(page, '订单查询').click(); await closePrompt(page, '放弃修改'); await selected(page, '订单查询');
        await tab(page, '会员价格').click(); await selected(page, '会员价格');
        check('discard reloads confirmed price', await regular.inputValue() === '12.00');
        await regular.fill('12.34'); await first.fill('3.21');
        await tab(page, '订单查询').click(); await closePrompt(page, '保存并继续'); await selected(page, '订单查询');
        await tab(page, '会员价格').click(); await selected(page, '会员价格');
        await page.reload(); await selected(page, '会员价格');
        check('successful save and refresh preserve precise dollars and tab', await regular.inputValue() === '12.34' && await first.inputValue() === '3.21' && new URL(page.url()).searchParams.get('tab') === 'prices');
        await snapshot(page, 'owner-prices');

        step = 'refresh-protection';
        await regular.fill('18.00');
        let beforeUnload = false;
        page.once('dialog', async dialog => { beforeUnload = dialog.type() === 'beforeunload'; await dialog.dismiss(); });
        await page.reload({ timeout: 2000 }).catch(error => { if (!/ERR_ABORTED|Timeout/.test(error.message)) throw error; });
        check('native refresh cancel keeps unsaved price', beforeUnload && await regular.inputValue() === '18.00');
        await tab(page, '订单查询').click(); await closePrompt(page, '放弃修改'); await selected(page, '订单查询');

        step = 'owner-mobile-keyboard';
        await tab(page, '订单查询').focus(); await page.keyboard.press('ArrowRight'); await selected(page, '会员价格');
        await page.keyboard.press('End'); await selected(page, '客服记录');
        await page.keyboard.press('Home'); await selected(page, '订单查询');
        mark('keyboard arrows Home End select real server tabs');
        await page.setViewportSize({ width: 375, height: 812 });
        await tab(page, '会员价格').click(); await selected(page, '会员价格');
        check('mobile has no document horizontal overflow', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        const inputBox = await regular.boundingBox();
        check('mobile amount input remains usable', inputBox && inputBox.width > 160 && inputBox.x >= 0 && inputBox.x + inputBox.width <= 375);
        await snapshot(page, 'owner-mobile-prices');
        await page.emulateMedia({ colorScheme: 'dark' });
        await page.evaluate(() => document.documentElement.classList.add('dark'));
        await snapshot(page, 'owner-mobile-dark');
        check('dark mode keeps visible price input', await regular.isVisible());

        step = 'support-scope';
        const supportContext = await context();
        const support = await login(supportContext, 'support');
        await support.goto(base + '/admin/billing-desk?tab=prices'); await selected(support, '订单查询');
        check('support cannot see price tab or recent-order list', await tab(support, '会员价格').count() === 0 && await support.locator('#billing-regular-usd').count() === 0 && !(await support.locator('[data-billing-workspace]').innerText()).includes('最近 50 笔'));
        await tab(support, '客服记录').click(); await selected(support, '客服记录');
        check('support sees only assigned cases', await support.locator(`[data-billing-case="${credentials.samples.cases.support}"]`).count() === 1 && await support.locator(`[data-billing-case="${credentials.samples.cases.supportOther}"]`).count() === 0);
        await support.locator(`[data-billing-case="${credentials.samples.cases.support}"]`).click();
        const note = support.locator('#billing-note'); await note.waitFor();
        check('unverified cancellation is disabled', await support.locator('[data-billing-cancel]').isDisabled());
        await note.fill('03 browser TEST: saved through the real browser form.');
        await support.getByRole('button', { name: '按订单号查询', exact: true }).click();
        await closePrompt(support, '继续编辑');
        check('case-navigation continue keeps note', (await note.inputValue()).includes('real browser form'));
        await support.getByRole('button', { name: '添加备注', exact: true }).click();
        await support.getByText('03 browser TEST: saved through the real browser form.', { exact: true }).waitFor();
        check('real note save clears input', await note.inputValue() === '');
        await support.reload(); await selected(support, '客服记录');
        await support.getByText('03 browser TEST: saved through the real browser form.', { exact: true }).waitFor();
        check('refresh retains case context and persisted note', new URL(support.url()).searchParams.get('case') === credentials.samples.cases.support);
        await snapshot(support, 'support-case');
        await support.goto(base + '/admin/billing-desk?tab=support&case=' + credentials.samples.cases.supportOther);
        await selected(support, '客服记录');
        check('foreign case URL reveals no detail or notes', await support.locator('#billing-note').count() === 0 && !(await support.locator('[data-billing-workspace]').innerText()).includes('supportOther owns'));
        await snapshot(support, 'support-forbidden-case');

        for (const role of ['content', 'acquisition']) {
            step = `${role}-forbidden`;
            const ctx = await context(); const other = await login(ctx, role);
            const response = await other.goto(base + '/admin/billing-desk');
            check(`${role} direct Billing page is forbidden`, response.status() === 403 && await other.locator('[data-billing-workspace]').count() === 0);
            await ctx.close();
        }
        check('no page script errors or server failures', errors.length === 0 && unexpected.length === 0);
        check('no external browser requests completed', externalFinished.size === 0);
        step = 'complete';
    } finally {
        fs.writeFileSync(path.join(out, 'browser.json'), JSON.stringify({ step, passed: checks.length, checks, errors, server_failures: unexpected, blocked_external_hosts: [...external], completed_external_hosts: [...externalFinished], is_test: true, official_payment_verified: false, boundary: 'Real Chrome password+MFA and HTTP UI; synthetic payment facts in this workspace testing database only.' }, null, 2));
        await browser?.close();
    }
})().catch(error => { console.error(JSON.stringify({ failed_step: step, error: error.message })); process.exitCode = 1; });
