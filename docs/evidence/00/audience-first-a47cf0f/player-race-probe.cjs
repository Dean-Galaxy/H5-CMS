const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('h5-platform/public/audience/player.js', 'utf8');
async function scenario(event) {
  const callbacks = new Map(), windowCallbacks = new Map();
  const button = {hidden:false, disabled:false, textContent:'Play', addEventListener:(n,f)=>callbacks.set(n,f)};
  const status = {textContent:''}; const member = {};
  let resolveGrant, assignedSources = [], scheduled = [], playCalls = 0;
  const video = {currentTime:0, duration:10, paused:true, currentSrc:'', textTracks:[],
    canPlayType:()=> 'probably', querySelectorAll:()=>[], addEventListener:()=>{}, removeEventListener:()=>{},
    removeAttribute:()=>{}, load:()=>{}, pause:()=>{video.paused=true;},
    play:()=>{playCalls++; return Promise.resolve();}};
  Object.defineProperty(video,'src',{set:value=>{assignedSources.push(value);video.currentSrc=value;}});
  const root={dataset:{duration:'10',episode:'fixture-episode',viewer:''},
    querySelector:s=>s==='video'?video:s==='[data-play]'?button:s==='[data-player-status]'?status:member,
    querySelectorAll:()=>[]};
  const context={document:{querySelector:s=>s==='[data-player]'?root:{content:'testing-csrf'},
    getElementById:()=>({textContent:JSON.stringify({play:'Play',retry:'Retry',loading:'Loading',network:'Network',media_error:'Media'})}),
    querySelectorAll:()=>[]},window:{addEventListener:(n,f)=>windowCallbacks.set(n,f)},
    location:{href:'http://127.0.0.1/fixture',pathname:'/fixture'}, navigator:{onLine:true},
    URL,AbortSignal, Date, setTimeout:f=>{scheduled.push(f);return scheduled.length;},clearTimeout:()=>{},
    fetch:(url)=>url.startsWith('/api/')?new Promise(resolve=>{resolveGrant=resolve;}):Promise.resolve({ok:true,text:async()=> '#EXTM3U\n'})};
  vm.runInNewContext(source,context);
  callbacks.get('click')();
  if(event==='offline')context.navigator.onLine=false;
  windowCallbacks.get(event)();
  const afterEvent={buttonDisabled:button.disabled,status:status.textContent};
  resolveGrant({ok:true,json:async()=>({allowed:true,short_lived_url:'http://127.0.0.1/signed-fixture/master.m3u8',play_session_id:'fixture-session',expires_at:new Date(Date.now()+120000).toISOString()})});
  await new Promise(resolve=>setImmediate(resolve));
  return {event,afterEvent,afterLateGrant:{assignedSources:assignedSources.length,playCalls,renewalTimers:scheduled.length,buttonHidden:button.hidden},
    violation:assignedSources.length>0||scheduled.length>0};
}
(async()=>{const results=await Promise.all(['offline','pagehide'].map(scenario));
  console.log(JSON.stringify({candidate:'a47cf0faee13ecf795eebfadc6b04b48a95af17c',type:'Node isolated lifecycle probe; mocked DOM/fetch, no network or browser playback',results},null,2));
  if(!results.every(r=>r.violation))process.exit(2);
})();
