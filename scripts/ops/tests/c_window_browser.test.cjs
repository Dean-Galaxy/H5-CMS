'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto'),cp=require('node:child_process');
const driver=require('../c_window_browser.cjs');
function config(root){const suffix=crypto.createHash('sha256').update(root).digest('hex').slice(0,10);return {root,workspace:'h5_'+suffix,db:'h5_'+suffix,user:'h5_app_'+suffix,port:8411,db_port:5911};}
test('inert --plan works with no runtime or Playwright and writes no files',()=>{
 const temp=fs.mkdtempSync(path.join(os.tmpdir(),'c-driver-plan-'));try{
  const result=JSON.parse(cp.execFileSync(process.execPath,[path.resolve(__dirname,'../c_window_browser.cjs'),'--plan'],{cwd:temp,encoding:'utf8',env:{PATH:process.env.PATH,H5_PLAYWRIGHT_MODULE:'/does-not-exist'}}));
  assert.equal(result.browser_executed,false);assert.equal(result.runtime_started,false);assert.equal(result.cases.length,9);assert.deepEqual(fs.readdirSync(temp),[]);
 }finally{fs.rmSync(temp,{recursive:true});}
});
test('browser binding rejects old roots, protected ports, copied database and abbreviated version',()=>{
 const temp=fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(),'c-driver-binding-'))),root=path.join(temp,'h5-c-ui-manual','H5');fs.mkdirSync(root,{recursive:true});
 try{
  const cfg=config(root);assert.equal(driver.binding(root,cfg,'a'.repeat(40)),'http://127.0.0.1:8411');
  for(const port of [8206,8303,5606,8377,5877,8406,5906,8407,8408,8409,8410,5910,true])for(const key of ['port','db_port'])assert.throws(()=>driver.binding(root,{...cfg,[key]:port},'a'.repeat(40)));
  assert.throws(()=>driver.binding(root,{...cfg,db:'old-db'},'a'.repeat(40)));assert.throws(()=>driver.binding(root,cfg,'abcdef1'));
  assert.throws(()=>driver.binding(temp,config(temp),'a'.repeat(40)));
 }finally{fs.rmSync(temp,{recursive:true});}
});
test('off assertion detects any C/v1 subject, event or play increment and watermark drift',()=>{
 const counts=Object.fromEntries(['acquisition_c_subjects','acquisition_c_sessions','acquisition_c_events','acquisition_c_event_keys','acquisition_c_plays','acquisition_subjects','acquisition_sources','acquisition_behaviors','acquisition_event_keys'].map(n=>[n,0]));
 const baseline={counts,c_event_high_watermark:8};driver.noOptionalDelta(baseline,structuredClone(baseline));
 for(const key of Object.keys(counts)){const after=structuredClone(baseline);after.counts[key]++;assert.throws(()=>driver.noOptionalDelta(baseline,after));}
 assert.throws(()=>driver.noOptionalDelta(baseline,{counts,c_event_high_watermark:9}));
});
test('financial evidence cannot omit tables or hide a new payment',()=>{
 const names=['identity_viewers','billing_prices','billing_orders','billing_payments','billing_entitlements','billing_subscriptions','billing_refunds','billing_inbox','billing_outbox','billing_behavior_outbox'];
 const state={counts:Object.fromEntries(names.map(n=>[n,0]))};driver.financialZero(state);
 assert.throws(()=>driver.financialZero({counts:{}}));state.counts.billing_payments=1;assert.throws(()=>driver.financialZero(state));
});
test('payload assertion rejects identity, source and arbitrary URL properties',()=>{
 const payload={events:[{event_id:crypto.randomUUID(),event_name:'page_view',properties:{page_instance_id:crypto.randomUUID(),page_type:'home',locale:'en'}}]};driver.validateBatch(payload);
 for(const key of ['viewer_id','order_id','url','referrer','click_id','utm_source']){const copy=structuredClone(payload);copy.events[0].properties[key]='forbidden';assert.throws(()=>driver.validateBatch(copy));}
 assert.throws(()=>driver.validateBatch({events:[]}));
});
test('unknown scenario cannot silently become passed or reach runtime admission',async()=>{await assert.rejects(driver.run('a'.repeat(40),['not-a-case']));});

test('seek acceptance requires real pre-seek sampling and rejects inflated watch/completion',()=>{
 const observed={before_position:5.25,resumed_position:7.65,duration:8,frames_before:130,frames_after:139};
 const events=[{event_name:'watch_progress',properties:{watched_seconds:5.1}}];
 assert.equal(driver.seekEvidence(events,observed),true);
 assert.equal(driver.seekEvidence([],observed),false);
 assert.equal(driver.seekEvidence([{event_name:'watch_progress',properties:{watched_seconds:8}}],observed),false);
 assert.equal(driver.seekEvidence([...events,{event_name:'episode_completed'}],observed),false);
 assert.equal(driver.seekEvidence(events,{...observed,before_position:.6}),false);
 assert.equal(driver.seekEvidence(events,{...observed,frames_after:130}),false);
});

test('read-only server observation accepts only an actual UUID play reference',()=>{
 const query=driver.sqlForPlay('00000000-0000-4000-8000-000000000006');assert(query.startsWith('SELECT '));assert(!/INSERT|UPDATE|DELETE|DROP/.test(query));
 for(const input of ["';DROP TABLE acquisition_c_plays;--",'',null,'not-a-play'])assert.throws(()=>driver.sqlForPlay(input));
});
test('explicit old target is rejected before runtime/dependency loading',()=>{
 const proc=cp.spawnSync(process.execPath,[path.resolve(__dirname,'../c_window_browser.cjs'),'--run','--candidate','a'.repeat(40)],{encoding:'utf8',env:{PATH:process.env.PATH,H5_C_TARGET_ROOT:'/Volumes/KESU/H5',H5_PLAYWRIGHT_MODULE:'/does-not-exist'}});
 assert.equal(proc.status,1);assert.match(proc.stderr,/admission failed/);
});
