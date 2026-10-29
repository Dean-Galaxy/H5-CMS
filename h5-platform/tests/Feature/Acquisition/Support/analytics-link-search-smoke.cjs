const fs = require('node:fs'), crypto = require('node:crypto'), assert = require('node:assert/strict');
const { chromium } = require(process.env.H5_PLAYWRIGHT_MODULE || 'playwright');
const base = 'http://127.0.0.1:8205';
const fixture = JSON.parse(fs.readFileSync('.runtime/analytics-link-search-fixture.json'));
const account = JSON.parse(fs.readFileSync('.runtime/acquisition-preview-account.json'));
const output = '.runtime/analytics-link-search-browser'; fs.mkdirSync(output, {recursive:true,mode:0o700});
function totp(secret) {
 const chars='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits='';
 for(const c of secret.replace(/=+$/,'')) bits+=chars.indexOf(c).toString(2).padStart(5,'0');
 const key=Buffer.from(bits.match(/.{8}/g).map(x=>parseInt(x,2))),counter=Buffer.alloc(8);
 counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));
 const digest=crypto.createHmac('sha1',key).update(counter).digest();
 return String((digest.readUInt32BE(digest.at(-1)&15)&0x7fffffff)%1000000).padStart(6,'0');
}
(async()=>{
 const browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
 const checks=[], external=[], errors=[];
 try {
  const context=await browser.newContext({viewport:{width:1440,height:1040}});
  await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==base){external.push(new URL(route.request().url()).origin);return route.abort();}return route.continue();});
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/admin/login');const form=page.locator('form#form');
  await form.locator('input[type=email]').fill(account.email);await form.locator('input[type=password]').fill(account.password);await form.locator('button[type=submit]').click();
  const mfa=page.locator('form#multiFactorChallengeForm');await mfa.waitFor({state:'visible'});await mfa.locator('input[autocomplete="one-time-code"]').fill(totp(account.totp_secret));await mfa.locator('button[type=submit]').click();await page.waitForURL(u=>u.pathname.startsWith('/admin')&&!u.pathname.startsWith('/admin/login'));
  await page.goto(base+'/admin/acquisition-desk');await page.locator('#acquisition-workspace').waitFor();
  assert.equal(await page.locator('[data-editor-action=selectLink][data-editor-id="'+fixture.target_id+'"]').count(),0);checks.push('old synthetic link is beyond the main first fifty');
  await page.goto(base+'/admin/acquisition-desk?tab=analytics');const search=page.locator('[data-analytics-link-search]');await search.waitFor();
  const selected=page.locator('select[wire\\:model="analyticsFilters.link_id"]');
  const action=async button=>{const response=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname.endsWith('/update'));await button.click();assert.equal((await response).status(),200);};
  const query=async text=>{await search.locator('input[type=search]').fill(text);await action(search.getByRole('button',{name:'搜索链接',exact:true}));};
  await query('05搜索验收');await page.getByRole('button',{name:'下一页链接选项',exact:true}).waitFor();
  assert.equal(await selected.locator('option[value="'+fixture.target_id+'"]').count(),0);
  await action(page.getByRole('button',{name:'下一页链接选项',exact:true}));await selected.locator('option[value="'+fixture.target_id+'"]').waitFor({state:'attached'});checks.push('independent pagination reaches the old link');
  await query('ZZZ旧广告');await selected.locator('option[value="'+fixture.target_id+'"]').waitFor({state:'attached'});assert.equal(await selected.locator('option').count(),2);checks.push('name search finds the old link across all authorized assets');
  await selected.selectOption(fixture.target_id);await action(page.getByRole('button',{name:'查询分析',exact:true}));assert.equal(await selected.inputValue(),fixture.target_id);checks.push('old link can be selected and its report queried');
  await query('Campaign 000');assert.equal(await selected.inputValue(),fixture.target_id);assert.equal(await selected.locator('option[value="'+fixture.target_id+'"]').count(),1);checks.push('selected authorized link survives a different search');
  await page.screenshot({path:output+'/desktop.png',fullPage:true});
  for (const size of [{width:390,height:844},{width:375,height:500}]) {
   await page.setViewportSize(size);await page.waitForTimeout(200);await search.scrollIntoViewIfNeeded();
   await query('ZZZ旧广告');assert.equal(await selected.inputValue(),fixture.target_id);
   await search.scrollIntoViewIfNeeded();await page.screenshot({path:output+'/search-'+size.width+'x'+size.height+'.png'});
   const bounds=await page.evaluate(()=>({width:document.documentElement.scrollWidth,viewport:innerWidth}));assert.ok(bounds.width<=bounds.viewport+1);
   await action(page.getByRole('button',{name:'查询分析',exact:true}));assert.equal(await selected.inputValue(),fixture.target_id);
   await page.screenshot({path:output+'/query-'+size.width+'x'+size.height+'.png'});
   checks.push(size.width+'x'+size.height+' search and report actions remain operable without document overflow');
  }
  assert.deepEqual(external,[]);assert.deepEqual(errors,[]);checks.push('zero external requests and JavaScript errors');
  const result={checks,passed:checks.length,external_requests:external.length,javascript_errors:errors.length,synthetic_links:fixture.count,viewports:['1440x1040','390x844','375x500']};fs.writeFileSync(output+'/result.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result));
 } finally {await browser.close();}
})().catch(error=>{console.error(error.stack);process.exit(1);});
