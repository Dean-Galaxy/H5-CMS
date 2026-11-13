// Deterministic DOM/HTTP doubles for sensitive input lifetime. Not a browser or real network test.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const blade = fs.readFileSync('h5-platform/resources/views/acquisition/desk.blade.php', 'utf8');
const code = '(() => {' + blade.split('<script>')[1].split('(() => {')[1].split('})();')[0] + '})();';
const node = () => ({value: '', checked: false, disabled: false, hidden: false, textContent: '', handlers: {}, addEventListener(n, f) { this.handlers[n] = f; }});
const token = node(), testCode = node(), reuse = node(), result = node(), label = node(), testField = node(), submit = node();
const form = node(), dialog = node(), root = node(); root.dataset = {};
const fields = [token, testCode, reuse, submit];
dialog.querySelector = s => ({'form': form, '[data-credential-token]': token, '[data-credential-test-code]': testCode,
  '[data-credential-reuse]': reuse, '[data-credential-result]': result, '[data-credential-target]': label, '[data-credential-test-field]': testField})[s];
dialog.querySelectorAll = () => fields;
root.querySelector = () => dialog;
let calls = [], resolve, rejection = false, destination = null;
const document = {getElementById: () => root, querySelector: () => ({content: 'FAKE_CSRF'})};
const window = node();
vm.runInNewContext(code, {document, window, H5Admin: {openDialog() {}}, location: {assign(url) {destination = url;}}, AbortController,
  setTimeout, clearTimeout, fetch(url, options) { calls.push({url, options}); return new Promise((yes, no) => { resolve = data => rejection ? no(new Error('FAKE')) : yes(data); }); }});
const open = () => root.handlers.click({target: {closest: () => ({dataset: {pixelId: 'FAKE_UUID', pixelVersion: '2', pixelEnvironment: 'test'}})}});
const fill = () => {token.value = 'FAKE_TOKEN'; testCode.value = 'FAKE_TEST_CODE';};
const submitEvent = {preventDefault() {}};
(async () => {
  open(); fill(); dialog.handlers.close(); assert.equal(token.value, ''); assert.equal(testCode.value, ''); assert.equal(calls.length, 0);
  open(); fill(); const failed = form.handlers.submit(submitEvent);
  assert.equal(token.value, ''); assert.equal(testCode.value, ''); assert.equal(fields.every(f => f.disabled), true);
  await form.handlers.submit(submitEvent); assert.equal(calls.length, 1); // busy never resubmits
  const body = JSON.parse(calls[0].options.body); assert.equal(body.token, 'FAKE_TOKEN'); assert.equal(body.test_event_code, 'FAKE_TEST_CODE');
  assert.equal(calls[0].options.cache, 'no-store'); assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'FAKE_CSRF');
  let prevented = false; dialog.handlers['h5-admin:dialog-close-request']({preventDefault() {prevented = true;}}); assert.equal(prevented, true);
  resolve({ok: false, json: async () => ({code: 'CREDENTIAL_VERSION_CONFLICT'})}); await failed;
  assert.match(result.textContent, /版本已变化/); assert.equal(token.value, ''); assert.equal(calls.length, 1);
  open(); fill(); rejection = true; const unknown = form.handlers.submit(submitEvent); resolve(); await unknown;
  assert.match(result.textContent, /结果未知/); assert.equal(token.value, ''); assert.equal(calls.length, 2);
  open(); fill(); rejection = false; const saved = form.handlers.submit(submitEvent);
  resolve({ok: true, json: async () => ({code: 'CREDENTIAL_SAVED'})}); await saved;
  assert.equal(destination, '/admin/acquisition-desk?tab=pixels'); assert.equal(token.value, ''); assert.equal(testCode.value, '');
  assert.equal(calls.length, 3); console.log('PASS: cancel, busy single submit, pre-send clear, conflict, unknown no retry, success clear, fixed endpoint and CSRF (DOM/HTTP doubles)');
})().catch(e => {console.error(e); process.exitCode = 1;});
