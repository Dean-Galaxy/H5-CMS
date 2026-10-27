// Real Chrome, generated local content only. No PayPal credentials or external requests.
const {chromium}=await import(process.env.H5_PLAYWRIGHT_MODULE || 'playwright');
import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
const root=process.cwd();
const cfg=JSON.parse(fs.readFileSync('.runtime/workspace.json'));
if(cfg.root!==root)throw new Error('Workspace mismatch');
const fixture=JSON.parse(fs.readFileSync('.runtime/06/preview.json'));
const account=JSON.parse(fs.readFileSync('.runtime/http-smoke-account.json'));
const base=`http://127.0.0.1:${cfg.port}`;
const out='.runtime/06/paypal-browser';fs.mkdirSync(out,{recursive:true,mode:0o700});
const browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
const context=await browser.newContext({viewport:{width:1100,height:850}});
const checks={};const errors=[];const media=[];let firstMedia=null;
await context.route('**/*',route=>{
 const url=new URL(route.request().url());
 if(url.origin!==base){errors.push('external_request_blocked');return route.abort();}
 return route.continue();
});
// Force only the non-native HLS branch; actual signed TS bytes must still decode via hls.js/MSE.
await context.addInitScript(()=>{
 const original=HTMLMediaElement.prototype.canPlayType;
 HTMLMediaElement.prototype.canPlayType=function(type){return /mpegurl/i.test(type)?'':original.call(this,type);};
});
const page=await context.newPage();
page.on('pageerror',()=>errors.push('page_error'));
page.on('response',r=>{const u=new URL(r.url());if(u.pathname.startsWith('/api/v1/content/playback/')){
 if(!firstMedia)firstMedia=u;
 media.push({type:u.pathname.endsWith('.ts')?'segment':u.pathname.endsWith('.m3u8')?'playlist':'other',status:r.status()});
}});
try{
 let response=await page.goto(base+'/');checks.home=response.status()===200;
 await page.screenshot({path:out+'/home.png',fullPage:true});
 response=await page.goto(base+`/dramas/${fixture.drama_id}/episodes/${fixture.episodes[0]}`);
 checks.watch=response.status()===200;
 await page.locator('video').evaluate(v=>{v.muted=true;});
 await page.locator('[data-play]').click();
 await page.waitForFunction(()=>{const v=document.querySelector('video');return v&&v.readyState>=3&&v.currentTime>1&&v.getVideoPlaybackQuality().totalVideoFrames>0;},null,{timeout:35000});
 const playback=await page.locator('video').evaluate(v=>({readyState:v.readyState,time:v.currentTime,decodedFrames:v.getVideoPlaybackQuality().totalVideoFrames,error:v.error?.code??null,mse:v.currentSrc.startsWith('blob:')}));
 checks.actual_mse_decode=playback.mse&&playback.decodedFrames>0&&playback.error===null;
 checks.signed_playlist_and_segments=media.some(x=>x.type==='playlist'&&x.status===200)&&media.some(x=>x.type==='segment'&&x.status===200);
 await page.screenshot({path:out+'/free-playback.png',fullPage:true});
 const unsigned=await context.request.get(firstMedia.origin+firstMedia.pathname);checks.unsigned_media_denied=unsigned.status()===403;
 await page.goto(base+`/dramas/${fixture.drama_id}/episodes/${fixture.episodes[5]}`);
 const mediaBefore=media.length;
 const [grantResponse]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/playback')&&r.request().method()==='POST'),page.locator('[data-play]').click()]);
 const grant=await grantResponse.json();
 checks.member_episode_denied=grant.allowed===false&&grant.reason==='MEMBERSHIP_REQUIRED';
 await page.waitForFunction(()=>document.querySelector('[data-play]')?.disabled===false,null,{timeout:5000});
 checks.denied_has_no_media_requests=media.length===mediaBefore;
 await page.screenshot({path:out+'/membership-gate.png',fullPage:true});
 await page.goto(base+'/membership?purchase=1');
 const text=await page.textContent('body');checks.test_prices_shown=text.includes('USD 3.00')&&text.includes('USD 12.00');
 await page.screenshot({path:out+'/test-offer.png',fullPage:true});
 const csrf=await (await context.request.get(base+'/api/v1/auth/csrf')).json();
 const login=await context.request.post(base+'/api/v1/auth/login',{data:{...account,return_to:'/membership'},headers:{'X-CSRF-TOKEN':csrf.csrf_token,'Accept':'application/json'}});
 checks.synthetic_viewer_login=login.status()===200;
 await page.goto(base+'/membership?purchase=1');
 await page.locator('input[name=confirmed]').check();
 const [checkout]=await Promise.all([page.waitForResponse(r=>r.url()===base+'/membership/checkout'&&r.request().method()==='POST'),page.locator('form[action="/membership/checkout"] button').click()]);
 checks.purchase_not_falsely_successful=checkout.status()===503;
 checks.no_external_or_script_errors=errors.length===0;
 const report={application_candidate:'a82b831ba5d03064d7d1564a93a5b201298571ce',checkout_sha:execFileSync('git',['rev-parse','HEAD'],{encoding:'utf8'}).trim(),browser:browser.version(),checks,playback,media_requests:media,local_region:'explicit synthetic US',mail:'preview only',non_native_branch:'forced via canPlayType; decoded actual generated media',phone_tested:false,paypal_contacted:false};
 fs.writeFileSync(out+'/result.json',JSON.stringify(report,null,2)+'\n');
 console.log(JSON.stringify({checks,playback}));
 if(Object.values(checks).some(x=>x!==true))process.exitCode=1;
}finally{await browser.close();}
