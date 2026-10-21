// Requires an existing Playwright runtime and Chrome; no package installation.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.H5_PLAYWRIGHT_MODULE || 'playwright');
const url = process.argv[2] || 'http://127.0.0.1:8198';
assert(['127.0.0.1', 'localhost'].includes(new URL(url).hostname), 'Fixture must be loopback-only');

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.H5_CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1100, height: 800 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const passed = [];
    const check = (name, result) => { assert(result, name); passed.push(name); };
    try {
        await page.goto(url);
        const edit = page.getByRole('tab', { name: '编辑内容' });
        const history = page.getByRole('tab', { name: '操作记录' });
        const name = page.getByLabel('名称', { exact: true });
        const prompt = page.locator('dialog.h5-confirm-leave');
        check('one panel initially visible', await page.getByRole('tabpanel').count() === 1);
        await edit.focus(); await page.keyboard.press('ArrowRight');
        check('keyboard selects next tab', await history.getAttribute('aria-selected') === 'true');
        await page.keyboard.press('Home');
        check('Home returns first tab', await edit.getAttribute('aria-selected') === 'true');

        await name.fill('未保存名称'); await history.click();
        await prompt.waitFor({ state: 'visible' });
        check('dirty tab asks before leaving', await edit.getAttribute('aria-selected') === 'true');
        await prompt.getByRole('button', { name: '继续编辑' }).click();
        check('continue keeps input and tab', await name.inputValue() === '未保存名称' && await edit.getAttribute('aria-selected') === 'true');
        await history.click();
        await prompt.getByRole('button', { name: '保存并继续' }).click();
        await prompt.getByRole('alert').waitFor({ state: 'visible' });
        check('failed save keeps dialog and tab', await prompt.isVisible() && await edit.getAttribute('aria-selected') === 'true');
        await prompt.getByRole('button', { name: '继续编辑' }).click();
        await page.getByLabel('保存返回').selectOption('throw');
        await history.click(); await prompt.getByRole('button', { name: '保存并继续' }).click();
        await prompt.getByRole('alert').waitFor({ state: 'visible' });
        check('unknown save outcome stays in editor', await edit.getAttribute('aria-selected') === 'true');
        await prompt.getByRole('button', { name: '继续编辑' }).click();

        await page.getByLabel('保存返回').selectOption('ok');
        await history.click(); await prompt.getByRole('button', { name: '保存并继续' }).click();
        await prompt.waitFor({ state: 'detached' });
        check('confirmed save continues once', await history.getAttribute('aria-selected') === 'true' && (await page.locator('#test-result').textContent()).includes('保存 1 次'));
        await edit.click(); await name.fill('应被放弃'); await history.click();
        await prompt.getByRole('button', { name: '放弃修改' }).click();
        await edit.click();
        check('discard restores last saved value', await name.inputValue() === '未保存名称' && (await page.locator('#test-result').textContent()).includes('放弃 1 次'));

        await page.getByRole('button', { name: '打开编辑弹窗' }).click();
        await page.getByLabel('弹窗备注').fill('尚未保存的备注');
        await page.keyboard.press('Escape');
        await prompt.waitFor({ state: 'visible' });
        await prompt.getByRole('button', { name: '继续编辑' }).click();
        check('Escape respects dirty dialog', await page.locator('#test-dialog').isVisible());
        await page.keyboard.press('Escape');
        await prompt.getByRole('button', { name: '放弃修改' }).click();
        check('confirmed discard closes original dialog', !(await page.locator('#test-dialog').isVisible()));
        check('focus returns to opener', await page.getByRole('button', { name: '打开编辑弹窗' }).evaluate(el => el === document.activeElement));

        await page.setViewportSize({ width: 375, height: 667 });
        await page.getByRole('button', { name: '打开编辑弹窗' }).click();
        const buttonBox = await page.getByRole('button', { name: '保存弹窗' }).boundingBox();
        check('mobile long-dialog actions stay visible', buttonBox && buttonBox.y >= 0 && buttonBox.y + buttonBox.height <= 667);
        check('mobile body has no horizontal overflow', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        await page.screenshot({ path: '.runtime/admin-ui-review/mobile-dialog.png' });
        await page.keyboard.press('Escape');
        await name.fill('并发切换检查');
        await page.evaluate(() => {
            const button = document.getElementById('review-tabs-tab-history');
            button.click(); button.click();
        });
        check('only one simultaneous leave prompt', await prompt.count() === 1);
        await prompt.getByRole('button', { name: '放弃修改' }).click();
        await prompt.waitFor({ state: 'detached' });
        check('approved tab does not recursively prompt', await history.getAttribute('aria-selected') === 'true');
        check('no browser script errors', errors.length === 0);
        fs.writeFileSync('.runtime/admin-ui-review/browser.json', JSON.stringify({ passed: passed.length, cases: passed, errors, boundary: 'Shared UI fixture only; not module or provider acceptance' }, null, 2));
        console.log(JSON.stringify({ passed: passed.length, cases: passed }));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
