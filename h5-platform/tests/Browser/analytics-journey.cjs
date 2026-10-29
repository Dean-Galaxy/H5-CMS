// 06 browser observation harness. No provider SDK, billing fixture, credentials or tunnel launcher.
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process');
const assert = require('node:assert/strict'), crypto = require('node:crypto');
const ROOT = path.resolve(__dirname, '../../..');
const protectedPorts = new Set([8377, 5877, 8406, 5906, 8407, 8408, 8409, 8303]);
function workspace(root, ws, candidate) {
  assert.match(candidate, /^[a-f0-9]{40}$/);
  assert.equal(fs.realpathSync(root), fs.realpathSync(ws.root));
  assert(!root.includes('paypal-sandbox') && root !== '/Volumes/KESU/H5');
  assert(Number.isInteger(ws.port) && ws.port > 1024 && ws.port < 65536 && !protectedPorts.has(ws.port));
  assert(Number.isInteger(ws.db_port) && ws.db_port > 1024 && ws.db_port < 65536 && !protectedPorts.has(ws.db_port));
  const suffix = crypto.createHash('sha256').update(root).digest('hex').slice(0, 10);
  assert.equal(ws.workspace, 'h5_' + suffix); assert.equal(ws.db, ws.workspace);
  assert.notEqual(ws.port, ws.db_port);
  return 'http://127.0.0.1:' + ws.port;
}
function ownWebCommand(record, command, root, port) {
  const router = root + '/h5-platform/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
  assert(Number.isInteger(record.pid) && record.pid > 1 && record.marker === router);
  assert(command.includes(' -S 127.0.0.1:' + port + ' ') && command.includes(root + '/h5-platform/public'));
  assert(command.trim().endsWith(router));
}
function privateJson(file) {
  const s = fs.lstatSync(file);
  assert(s.isFile() && !s.isSymbolicLink() && s.nlink === 1 && s.uid === process.getuid() && (s.mode & 0o077) === 0);
  assert(s.size <= 65536);
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}
function localPath(value) {
  assert(typeof value === 'string' && /^\/[A-Za-z0-9/_-]*$/.test(value) && !value.startsWith('//'));
  return value;
}
function summarizeBatch(raw) {
  // Do not serialize request properties, identity, names, payment/media URLs, page paths or errors.
  try {
    const data = JSON.parse(raw);
    if (!Array.isArray(data.events) || data.events.length > 20) return { malformed: true };
    return { events: data.events.map(e => ({
      event_name: typeof e.event_name === 'string' && /^[a-z_]{1,40}$/.test(e.event_name) ? e.event_name : 'invalid',
      event_id_sha256: typeof e.event_id === 'string' ? crypto.createHash('sha256').update(e.event_id).digest('hex') : null,
    })) };
  } catch { return { malformed: true }; }
}
async function run(candidate, fixtureFile) {
  assert.equal(cp.execFileSync('git', ['rev-parse', 'HEAD'], {cwd: ROOT, encoding: 'utf8'}).trim(), candidate);
  assert.equal(cp.execFileSync('git', ['status', '--porcelain', '--', 'h5-platform', 'scripts/ops/analytics_acceptance.py', 'scripts/ops/analytics_preflight.php'], {cwd: ROOT, encoding: 'utf8'}), '');
  const ws = privateJson(ROOT + '/.runtime/workspace.json'), base = workspace(ROOT, ws, candidate);
  assert(typeof fixtureFile === 'string');
  const fixturePath = fs.realpathSync(fixtureFile);
  assert(fixturePath.startsWith(fs.realpathSync(ROOT + '/.runtime/06') + path.sep));
  const fixture = privateJson(fixturePath);
  assert.equal(fixture.candidate, candidate);
  assert.equal(fixture.synthetic_only, true);
  assert(Array.isArray(fixture.steps) && fixture.steps.length <= 100);
  // This must be generated for THIS candidate by 06 preparation, not copied from a previous run.
  const readiness = privateJson(path.join(ROOT, '.runtime/06/analytics-browser-readiness.json'));
  assert.equal(readiness.candidate, candidate);
  if (!readiness.combined_ready) { console.log(JSON.stringify({candidate, result: 'blocked_missing_consumers', browser_executed: false})); return 77; }
  const web = privateJson(ROOT + '/.runtime/pids/web.json');
  ownWebCommand(web, cp.execFileSync('ps', ['-p', String(web.pid), '-o', 'command='], {encoding: 'utf8'}), ROOT, ws.port);
  const flags = JSON.parse(cp.execFileSync(process.env.H5_PHP_BIN || '/opt/homebrew/opt/php@8.4/bin/php', [ROOT + '/scripts/ops/analytics_browser_runtime.php'], {cwd: ROOT, encoding: 'utf8', stdio: ['ignore','pipe','pipe'], env: {PATH: process.env.PATH || '/opt/homebrew/bin:/usr/bin:/bin', LANG: 'C'}}));
  assert.equal(flags.runtime_safe, true); assert.equal(flags.external_enabled, false); assert.equal(flags.purchase_enabled, false);
  const { chromium } = require(process.env.H5_PLAYWRIGHT_MODULE || 'playwright');
  const browser = await chromium.launch({headless: true, executablePath: process.env.H5_CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
  const context = await browser.newContext({viewport: {width: 375, height: 812}, serviceWorkers: 'block'});
  const result = {candidate, contract: 'analytics-v1', scope: 'browser observations only; DB/funnel/money/authority acceptance is separate',
    browser: browser.version(), external_attempts: 0, tracking: [], playback: [], steps: [], media_requests: 0,
    official_platform_verified: false, real_device: false, completed: false};
  const page = await context.newPage();
  const pending = new Set(); let finished = false;
  await context.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.protocol === 'blob:' || url.protocol === 'data:') return route.continue();
    if (url.origin !== base) { result.external_attempts++; return route.abort('blockedbyclient'); }
    return route.continue();
  });
  page.on('request', req => {
    if (finished) return;
    const url = new URL(req.url());
    if (url.origin !== base) return;
    if (url.pathname === '/api/v1/tracking/events' && result.tracking.length < 200) {
      result.tracking.push(summarizeBatch(req.postData() || ''));
    }
    if (url.pathname.startsWith('/api/v1/content/playback/')) result.media_requests++;
  });
  page.on('response', response => {
    if (finished) return;
    const url = new URL(response.url());
    if (url.origin === base && url.pathname.endsWith('/playback') && result.playback.length < 100) {
      const work = response.json().then(data => result.playback.push({status: response.status(), allowed: data.allowed === true,
        reason: typeof data.reason === 'string' && /^[A-Z_a-z]{1,40}$/.test(data.reason) ? data.reason : 'unknown'})).catch(() => result.playback.push({status: response.status(), invalid_json: true}));
      pending.add(work); work.finally(() => pending.delete(work));
    }
  });
  try {
    for (const [index, step] of fixture.steps.entries()) {
      switch (step.kind) {
        case 'goto': await page.goto(base + localPath(step.path)); break;
        case 'click': assert(typeof step.selector === 'string'); await page.locator(step.selector).click(); break;
        case 'fill': assert(typeof step.selector === 'string' && typeof step.value === 'string'); await page.locator(step.selector).fill(step.value); break;
        case 'wait_url': await page.waitForURL(base + localPath(step.path)); break;
        case 'tracking_offline': await page.route('**/api/v1/tracking/events', r => r.abort('internetdisconnected')); break;
        case 'tracking_online': await page.unroute('**/api/v1/tracking/events'); break;
        case 'reload': await page.reload(); break;
        case 'wait_event': {
          assert.match(step.event_name, /^[a-z_]{1,40}$/); assert(Number.isInteger(step.minimum) && step.minimum >= 1 && step.minimum <= 100);
          await page.waitForFunction(() => document.visibilityState === 'visible');
          const end = Date.now() + 15000;
          while (result.tracking.flatMap(x => x.events || []).filter(x => x.event_name === step.event_name).length < step.minimum && Date.now() < end) await page.waitForTimeout(100);
          assert(result.tracking.flatMap(x => x.events || []).filter(x => x.event_name === step.event_name).length >= step.minimum);
          break;
        }
        case 'play': {
          await page.locator(step.selector || '[data-play]').click();
          // Require actual decoded frames AND wall-time progression, not a button/playing stub.
          await page.waitForFunction(() => {const v=document.querySelector('video'); return v && !v.paused && v.currentTime > .1 && (v.getVideoPlaybackQuality?.().totalVideoFrames || 0) > 0;});
          const start = await page.locator('video').evaluate(v => ({time: v.currentTime, frames: v.getVideoPlaybackQuality().totalVideoFrames}));
          await page.waitForTimeout(1200);
          const end = await page.locator('video').evaluate(v => ({time: v.currentTime, frames: v.getVideoPlaybackQuality().totalVideoFrames, error: v.error?.code || null}));
          assert(end.time > start.time + .5 && end.frames > start.frames && end.error === null);
          result.steps.push({index, kind: 'decoded_playback', advanced_seconds: end.time - start.time, additional_frames: end.frames - start.frames});
          break;
        }
        default: throw Error('UNKNOWN_FIXTURE_ACTION');
      }
      result.steps.push({index, kind: step.kind, completed: true});
    }
    await Promise.allSettled([...pending]);
    assert.equal(result.external_attempts, 0, 'External HTTP requests attempted while external delivery is off');
    result.completed = true;
    return 0;
  } finally {
    finished = true;
    await context.close(); await browser.close();
    const out = ROOT + '/.runtime/06/analytics-browser-' + Date.now() + '.json';
    fs.writeFileSync(out, JSON.stringify(result, null, 2) + '\n', {mode: 0o600, flag: 'wx'});
    console.log(JSON.stringify({candidate, browser_observation_completed: result.completed, external_attempts: result.external_attempts, official_platform_verified: false}));
  }
}
module.exports = {workspace, privateJson, localPath, summarizeBatch, ownWebCommand};
if (require.main === module) {
  const [candidate, fixture] = process.argv.slice(2);
  run(candidate, fixture).then(code => {process.exitCode = code;}).catch(() => {console.error('06 browser refused/failed; inspect own private evidence. No raw inputs printed.');process.exitCode = 1;});
}
