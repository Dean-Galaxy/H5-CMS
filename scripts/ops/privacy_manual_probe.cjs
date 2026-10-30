// Actual AN-M01 loopback Chrome/HTTP probe. Separate browser contexts; no registration or settlement.
const fs=require('node:fs'),cp=require('node:child_process'),path=require('node:path');
const assert=require('node:assert/strict'),crypto=require('node:crypto');
const ROOT=path.resolve(__dirname,'../..');
const guard=require(ROOT+'/h5-platform/tests/Browser/analytics-journey.cjs');
const candidate=cp.execFileSync('git',['rev-parse','HEAD'],{cwd:ROOT,encoding:'utf8'}).trim();
const workspace=guard.privateJson(ROOT+'/.runtime/workspace.json');
const base=guard.workspace(ROOT,workspace,candidate);
const output=ROOT+'/.runtime/06/privacy-manual';
const record=guard.privateJson(output+'/process.json');
assert.equal(record.root,ROOT);assert.equal(record.candidate,candidate);
assert.equal(record.command.at(-1),ROOT+'/h5-platform/tests/Browser/Support/privacy-only-router.php');
assert.equal(cp.execFileSync('ps',['-p',String(record.pid),'-o','command='],{encoding:'utf8'}).trim(),record.command.join(' '));
assert.equal(cp.execFileSync('ps',['-p',String(record.pid),'-o','lstart='],{encoding:'utf8'}).trim(),record.started_at);
const cases=guard.privateJson(ROOT+'/.runtime/06/analytics-browser/privacy-cases.json').groups.automatic;
function state(){return JSON.parse(cp.execFileSync('/usr/bin/python3',[ROOT+'/scripts/ops/privacy_manual_window.py','--candidate',candidate,'state'],
    {cwd:ROOT,encoding:'utf8',env:{PATH:process.env.PATH||'/opt/homebrew/bin:/usr/bin:/bin',LANG:'C'}}));}
const initial=state();
const counts=s=>Object.fromEntries(['acquisition_subjects','acquisition_sources','acquisition_behaviors'].map(k=>[k,s.counts[k]]));
const result={candidate,application_base:record.application_base,real_device:false,official_payment:false,external_attempts:0,
    separate_contexts:5,checks:[],passed:false,probe_sha256:crypto.createHash('sha256').update(fs.readFileSync(__filename)).digest('hex')};
function check(name,value){assert(value,name);result.checks.push({name,passed:true});}
let phase='start';
(async()=>{
    const {chromium}=require(process.env.H5_PLAYWRIGHT_MODULE||'playwright');
    const browser=await chromium.launch({headless:true,args:['--no-proxy-server'],executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
    fs.mkdirSync(output+'/screenshots',{mode:0o700,recursive:true});
    try {
        check('runtime keeps purchase and provider API off',initial.new_purchase_provider==='unconfigured'&&initial.paypal_api_enabled===false&&initial.payment_fixture_installed===false);
        check('external advertising and debug off',initial.advertising_external_enabled===false&&initial.advertising_transport==='disabled'&&initial.debug===false);
        for(const key of ['unknown','analytics','advertising','both','denied']){
            phase=key;
            const before=state();let eventCalls=0;
            const context=await browser.newContext({viewport:{width:390,height:844},serviceWorkers:'block',
                userAgent:'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'});
            await context.route('**/*',route=>{
                const url=new URL(route.request().url());
                if(['blob:','data:'].includes(url.protocol)||url.origin===base)return route.continue();
                result.external_attempts++;return route.abort();
            });
            await context.addInitScript(()=>{
                const original=HTMLMediaElement.prototype.canPlayType;
                HTMLMediaElement.prototype.canPlayType=function(type){return /mpegurl/i.test(type)?'':original.call(this,type);};
            });
            const page=await context.newPage();
            page.on('request',request=>{if(new URL(request.url()).pathname==='/api/v1/tracking/events')eventCalls++;});
            try {
                assert(cases[key].url.startsWith(base+'/r/'));
                const response=await page.goto(cases[key].url);assert.equal(response.status(),200);
                await page.locator('[data-privacy-dialog]').waitFor({state:'visible'});
                await page.waitForTimeout(350);
                check(key+' starts with both choices unchecked',!await page.locator('[name=analytics]').isChecked()&&!await page.locator('[name=advertising]').isChecked());
                if(key==='unknown'){
                    await page.waitForTimeout(400);
                    check('unknown creates no analysis or source',JSON.stringify(counts(state()))===JSON.stringify(counts(before))&&eventCalls===0);
                    await page.screenshot({path:output+'/screenshots/unknown.png'});
                    continue;
                }
                const analytics=key==='analytics'||key==='both',advertising=key==='advertising'||key==='both';
                if(analytics)await page.locator('[name=analytics]').check();
                if(advertising)await page.locator('[name=advertising]').check();
                const consent=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/v1/tracking/consent'&&r.status()===200);
                const landing=advertising?page.waitForResponse(r=>new URL(r.url()).pathname==='/api/v1/tracking/landing'&&r.status()===200):null;
                await page.locator(key==='denied'?'[data-privacy-reject]':'[data-privacy-form] button[type=submit]').click();
                const saved=await(await consent).json();
                check(key+' saves the two purposes independently',saved.consent.analytics===(analytics?'granted':'denied')&&saved.consent.advertising===(advertising?'granted':'denied'));
                if(landing)assert.equal((await(await landing).json()).confirmed,true);
                await page.locator('[data-privacy-dialog]').waitFor({state:'hidden'});
                await page.waitForTimeout(900);
                const after=state();
                if(key==='advertising')check('advertising-only uses landing without analytics',after.counts.acquisition_sources===before.counts.acquisition_sources+1&&after.counts.acquisition_subjects===before.counts.acquisition_subjects&&after.counts.acquisition_behaviors===before.counts.acquisition_behaviors&&eventCalls===0);
                if(key==='analytics')check('analytics-only has observations without ad source',after.counts.acquisition_sources===before.counts.acquisition_sources&&after.counts.acquisition_subjects===before.counts.acquisition_subjects+1&&after.counts.acquisition_behaviors>before.counts.acquisition_behaviors);
                if(key==='denied')check('rejecting creates no subject source or behavior',JSON.stringify(counts(after))===JSON.stringify(counts(before))&&eventCalls===0);
                await page.reload();await page.locator('[data-privacy-open]').click();
                await page.locator('[data-privacy-dialog]').waitFor({state:'visible'});
                check(key+' survives refresh',await page.locator('[name=analytics]').isChecked()===analytics&&await page.locator('[name=advertising]').isChecked()===advertising);
                await page.screenshot({path:output+'/screenshots/'+key+'-after-refresh.png'});
                await page.locator('[data-privacy-close]').click();
                if(key==='denied'){
                    await page.goto(base+initial.free_episode_path);await page.locator('[data-play]').click();
                    await page.waitForFunction(()=>{const v=document.querySelector('video');return v&&!v.paused&&v.currentTime>.1&&v.getVideoPlaybackQuality().totalVideoFrames>0;});
                    const start=await page.locator('video').evaluate(v=>({time:v.currentTime,frames:v.getVideoPlaybackQuality().totalVideoFrames}));
                    await page.waitForTimeout(1200);
                    const end=await page.locator('video').evaluate(v=>({time:v.currentTime,frames:v.getVideoPlaybackQuality().totalVideoFrames,mse:v.currentSrc.startsWith('blob:'),error:v.error?.code||null}));
                    check('anonymous reject still decodes real MSE free episode',end.time>start.time+.5&&end.frames>start.frames&&end.mse&&end.error===null);
                    result.decoded_playback={advanced_seconds:end.time-start.time,additional_frames:end.frames-start.frames,mse:end.mse};
                    await page.screenshot({path:output+'/screenshots/denied-free-mse.png',fullPage:true});
                    for(const url of ['/api/v1/auth/register','/api/v1/billing/checkout','/api/v1/billing/subscription/cancel',
                        '/api/v1/billing/subscription/resume','/membership/checkout','/membership/cancel','/membership/resume','/billing/return/recheck','/webhooks/paypal','/webhooks/stripe']){
                        const blocked=await context.request.post(base+url,{data:{}});
                        check('privacy window blocks '+url,blocked.status()===403&&(await blocked.json()).error==='AN_M01_PRIVACY_WINDOW_ONLY');
                    }
                }
            } finally {await context.close();}
        }
        phase='final';const final=state();
        check('no viewer or financial fact created', ['identity_viewers','billing_orders','billing_payments','billing_entitlements','billing_subscriptions','billing_refunds'].every(k=>final.counts[k]===0));
        check('human manual links remain unused',Object.values(final.case_counts.manual).every(c=>c.sources===0&&c.behaviors===0));
        check('zero external browser requests',result.external_attempts===0);
        result.passed=true;
        fs.writeFileSync(output+'/automatic-baseline.json',JSON.stringify(final,null,2),{mode:0o600});
    } catch(error){result.failed_phase=phase;result.failure_type=error.name;process.exitCode=1;}
    finally {
        await browser.close();fs.writeFileSync(output+'/probe-safe.json',JSON.stringify(result,null,2),{mode:0o600});
        console.log(JSON.stringify({candidate,passed:result.passed,checks:result.checks.length,failed_phase:result.failed_phase,external_attempts:result.external_attempts}));
    }
})().catch(()=>{console.error('AN-M01 probe failed; inspect own private evidence.');process.exitCode=1;});
