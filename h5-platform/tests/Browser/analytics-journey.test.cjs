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

test('preview verification only accepts actual same-origin signed identity links',()=>{
 const base='http://127.0.0.1:8726',p='/api/v1/auth/email/verify/'+ 'a'.repeat(8)+'-'+ 'b'.repeat(4)+'-'+ 'c'.repeat(4)+'-'+ 'd'.repeat(4)+'-'+ 'e'.repeat(12)+'/'+'f'.repeat(40);
 assert(m.verificationLink(base+p+'?expires=123&nonce=synthetic&signature=synthetic',base));
 for(const url of ['https://foreign.invalid'+p+'?expires=1&nonce=x&signature=y',base+p+'?expires=1',base+'/identity/account?expires=1&nonce=x&signature=y'])assert(!m.verificationLink(url,base));
});
test('preview matching reads only new private mail for a synthetic recipient',()=>{
 const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),root=fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(),'06-mail-fixture-')));
 try {
  const d=path.join(root,'.runtime/mail');fs.mkdirSync(d,{recursive:true,mode:0o700});
  const eml=path.join(d,'synthetic.eml'),html=path.join(d,'synthetic.html');
  fs.writeFileSync(eml,'To: Synthetic <sample06@example.test>\r\nSubject: Verify\r\n\r\nlocal only',{mode:0o600});fs.writeFileSync(html,'<a>local</a>',{mode:0o600});
  assert.equal(m.previewFiles(root,'sample06@example.test',Date.now()-1000).length,1);
  assert.equal(m.previewFiles(root,'other06@example.test',Date.now()-1000).length,0);
  assert.equal(m.previewFiles(root,'sample06@example.test',Date.now()+1000).length,0);
  assert.throws(()=>m.previewFiles(root,'real@external.invalid',0));
  fs.chmodSync(eml,0o644);assert.throws(()=>m.previewFiles(root,'sample06@example.test',0));
 } finally {fs.rmSync(root,{recursive:true,force:true});}
});
