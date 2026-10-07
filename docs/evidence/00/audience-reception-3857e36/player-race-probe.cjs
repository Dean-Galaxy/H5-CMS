// Independent 00 regression probe. Mocked DOM, clock and transport; not browser playback.
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(process.argv[2] || 'h5-platform/public/audience/player.js', 'utf8');
const old = process.argv[3] === 'expect-regression';
const settle = async () => { for (let n = 0; n < 4; n++) await new Promise(resolve => setImmediate(resolve)); };
async function scenario(event) {
  const callbacks = new Map(), windowCallbacks = new Map(), timers = new Map();
  const button = {hidden:false, disabled:false, textContent:'Play', addEventListener:(n,f)=>callbacks.set(n,f)};
  const status = {textContent:''}; const progress = {};
  let resolveGrant, requestSignal, assignedSources = 0, nextTimer = 0, playCalls = 0;
  const video = {currentTime:0, duration:10, paused:true, currentSrc:'', textTracks:[],
    canPlayType:()=> 'probably', querySelectorAll:()=>[], addEventListener:()=>{}, removeEventListener:()=>{},
    removeAttribute:()=>{video.currentSrc='';}, load:()=>{}, pause:()=>{video.paused=true;},
    play:()=>{playCalls++; return Promise.resolve();}};
  Object.defineProperty(video,'src',{set:value=>{assignedSources++;video.currentSrc=value;}});
  const root={dataset:{duration:'10',drama:'fixture-drama',episode:'fixture-episode',viewer:''},
    querySelector:s=>s==='video'?video:s==='[data-play]'?button:s==='[data-player-status]'?status:progress,
    querySelectorAll:()=>[]};
  const context={document:{querySelector:s=>s==='[data-player]'?root:{content:'testing-csrf'},
    getElementById:()=>({textContent:JSON.stringify({play:'Play',retry:'Retry',loading:'Loading',network:'Network',media_error:'Media'})}),
    querySelectorAll:()=>[],addEventListener:()=>{}},window:{addEventListener:(n,f)=>windowCallbacks.set(n,f)},
    location:{href:'http://127.0.0.1/fixture',pathname:'/fixture'}, navigator:{onLine:true},
    localStorage:{getItem:()=>null,setItem:()=>{}},URL,AbortSignal,AbortController,Date,
    setTimeout:(f,delay)=>{const id=++nextTimer;timers.set(id,{f,delay});return id;},clearTimeout:id=>timers.delete(id),
    fetch:(url,options={})=>/playback/.test(url)?new Promise(resolve=>{resolveGrant=resolve;requestSignal=options.signal;}):Promise.resolve({ok:true,text:async()=> '#EXTM3U\n'})};
  vm.runInNewContext(source,context);
  callbacks.get('click')(); await settle();
  if(!resolveGrant)throw new Error('Probe did not reach authorization');
  if(event==='offline')context.navigator.onLine=false;
  windowCallbacks.get(event)(); await settle();
  const afterEvent={buttonDisabled:button.disabled,status:status.textContent,requestAborted:requestSignal?.aborted===true};
  // Deliberately deliver the allowed response even if transport cancellation was requested.
  resolveGrant({ok:true,json:async()=>({allowed:true,short_lived_url:'http://127.0.0.1/signed-fixture/master.m3u8',play_session_id:'fixture-session',expires_at:new Date(Date.now()+120000).toISOString()})});
  await settle();
  const afterLateGrant={assignedSources,playCalls,activeTimers:timers.size,buttonHidden:button.hidden};
  return {event,afterEvent,afterLateGrant,violation:assignedSources>0||playCalls>0||timers.size>0};
}
(async()=>{
  const results=await Promise.all(['offline','pagehide'].map(scenario));
  console.log(JSON.stringify({type:'00 independent isolated lifecycle probe; mocked DOM/fetch; no browser or network',expectation:old?'original defect reproduces':'fixed lifecycle',results},null,2));
  if(!results.every(r=>old?r.violation:!r.violation&&r.afterEvent.requestAborted&&!r.afterEvent.buttonDisabled))process.exit(2);
})();
