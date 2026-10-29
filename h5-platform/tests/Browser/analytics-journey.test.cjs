const {test}=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const m=require('./analytics-journey.cjs');
test('metadata discards actor/price/token/body and hashes event IDs',()=>{
 const r=m.summarizeBatch(JSON.stringify({events:[{event_id:'SYNTHETIC_ID',event_name:'page_view',properties:{email:'PRIVATE',token:'SECRET',amount_minor:3,viewer_id:'OTHER'}}]}));
 assert.equal(r.events[0].event_name,'page_view');assert.equal(r.events[0].event_id_sha256.length,64);assert(!JSON.stringify(r).includes('PRIVATE'));assert(!JSON.stringify(r).includes('SECRET'));assert(!JSON.stringify(r).includes('SYNTHETIC_ID'));
});
test('arbitrary malformed event names and oversized batches omitted',()=>{
 assert(m.summarizeBatch('not json').malformed);assert(m.summarizeBatch(JSON.stringify({events:Array(21).fill({})})).malformed);
 assert.equal(m.summarizeBatch(JSON.stringify({events:[{event_name:'private@email.invalid'}]})).events[0].event_name,'invalid');
});
test('fixture navigation never allows external or signed query URLs',()=>{
 for(const u of ['https://example.invalid','//evil','/x?token=SECRET','/x#fragment','/../admin','/x\n'])assert.throws(()=>m.localPath(u));
 assert.equal(m.localPath('/identity/register'),'/identity/register');
});
test('browser guard refuses protected ports and foreign workspaces',()=>{
 const fs=require('node:fs'),root=fs.realpathSync(__dirname+'/../../..'),s=crypto.createHash('sha256').update(root).digest('hex').slice(0,10);
 const ws={root,workspace:'h5_'+s,db:'h5_'+s,port:8726,db_port:6126};assert.equal(m.workspace(root,ws,'a'.repeat(40)),'http://127.0.0.1:8726');
 for(const p of [8406,5906,8407,8408,8409,8377,5877,8303])assert.throws(()=>m.workspace(root,{...ws,port:p},'a'.repeat(40)));
 assert.throws(()=>m.workspace(root,{...ws,db:'other'},'a'.repeat(40)));
});

test('browser checks the own live web process binding before connecting',()=>{
 const root='/private/tmp/synthetic-06',router=root+'/h5-platform/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
 const record={pid:12001,marker:router},cmd='php -S 127.0.0.1:8726 -t '+root+'/h5-platform/public '+router;
 m.ownWebCommand(record,cmd,root,8726);
 assert.throws(()=>m.ownWebCommand(record,cmd,root,8406));
 assert.throws(()=>m.ownWebCommand({...record,marker:'/other/router'},cmd,root,8726));
 assert.throws(()=>m.ownWebCommand(record,cmd+' --other',root,8726));
});
