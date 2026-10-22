// Real loopback workspace only. Credentials, browser state, fixtures and screenshots stay outside Git.
// Run from repository root with H5_PLAYWRIGHT_MODULE and H5_CONTENT_BROWSER_ACCOUNT set.
const {chromium}=require(process.env.H5_PLAYWRIGHT_MODULE||'playwright');
const fs=require('node:fs'),crypto=require('node:crypto'),assert=require('node:assert/strict'),cp=require('node:child_process');
const root=process.cwd(),runtime=root+'/.runtime/content',workspace=JSON.parse(fs.readFileSync(root+'/.runtime/workspace.json','utf8')),url=process.env.H5_CONTENT_BROWSER_URL||('http://127.0.0.1:'+workspace.port);
assert.equal(fs.realpathSync(workspace.root),fs.realpathSync(root));assert.equal(Number(new URL(url).port),workspace.port,'Browser must target this workspace port');
assert(['localhost','127.0.0.1'].includes(new URL(url).hostname));
const credentials=JSON.parse(fs.readFileSync(process.env.H5_CONTENT_BROWSER_ACCOUNT||runtime+'/preview-account.json','utf8'));
const out=runtime+'/release-list';fs.mkdirSync(out,{recursive:true});
function totp(secret){const alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';let bits='';for(const c of secret)bits+=alphabet.indexOf(c).toString(2).padStart(5,'0');const key=Buffer.from((bits.match(/.{8}/g)||[]).map(x=>parseInt(x,2))),t=Buffer.alloc(8);t.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const mac=crypto.createHmac('sha1',key).update(t).digest();return ((mac.readUInt32BE(mac[19]&15)&0x7fffffff)%1000000).toString().padStart(6,'0');}

const passed=[],errors=[],requests=[];function check(name,value){assert(value,name);passed.push(name);console.log('PASS',name);}
(async()=>{const browser=await chromium.launch({headless:true,executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'}),page=await browser.newPage({viewport:{width:1100,height:1000}});page.on('pageerror',e=>errors.push(e.message));
const read=()=>page.evaluate(()=>fetch('/api/v1/admin/content',{headers:{Accept:'application/json'}}).then(r=>{if(!r.ok)throw Error('read failed');return r.json()}));
const snapshot=d=>JSON.stringify({dramas:d.dramas,default_free_count:d.default_free_count,categories:d.categories,banners:d.banners});
const open=async()=>{await page.goto(url+'/admin/content-library?content_view=releases');await page.waitForFunction(()=>document.getElementById('content-message')?.textContent.includes('已读取真实内容'));};
const capture=async name=>{await page.setViewportSize({width:1100,height:1000});await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:out+'/'+name+'-desktop.png'});await page.setViewportSize({width:375,height:812});await page.locator('#content-view-releases .h5-table-wrap').first().evaluate(el=>{el.scrollLeft=el.querySelector('th:nth-child(3)').offsetLeft;});await page.screenshot({path:out+'/'+name+'-mobile-range.png'});await page.setViewportSize({width:1100,height:1000});};
try{
 await page.goto(url+'/admin/login');await page.locator('input[type=email]').fill(credentials.email);await page.locator('input[type=password]').fill(credentials.password);await page.locator('button[type=submit]').click();await page.locator('input[id="multiFactorChallengeForm.app.code"]').waitFor();await page.locator('input[id="multiFactorChallengeForm.app.code"]').pressSequentially(totp(credentials.totp_secret));await page.locator('button[type=submit]').click();await page.waitForURL('**/admin',{timeout:5000}).catch(async()=>{await new Promise(r=>setTimeout(r,31000-Date.now()%30000));await page.locator('input[id="multiFactorChallengeForm.app.code"]').fill(totp(credentials.totp_secret));await page.locator('button[type=submit]').click();await page.waitForURL('**/admin');});

 page.on('request',r=>{if(new URL(r.url()).pathname.startsWith('/api/v1/admin/content'))requests.push({method:r.method(),path:new URL(r.url()).pathname});});
 const before=await read();await open();const actualRows=await page.locator('#content-view-releases tbody tr').evaluateAll(rows=>rows.map(tr=>[...tr.cells].map(c=>c.textContent.trim())));
 check('all real ranges are ascending',actualRows.every(r=>{const m=r[2].match(/第 (\d+)–(\d+) 集/);return !m||Number(m[1])<=Number(m[2])}));
 check('real five-episode withdrawn completion has no inverted range',actualRows.some(r=>r[2]==='无后续分集 · 已停用')&&before.dramas.some(d=>d.total_episodes===5&&d.published_count===5&&d.plan.start_number===6));
 check('real thirty-five-episode withdrawn completion has no inverted range',before.dramas.some(d=>d.total_episodes===35&&d.published_count===35&&d.plan.start_number===36)&&actualRows.some(r=>r[2]==='无后续分集 · 已停用'));
 check('real partially published disabled plan shows stopped',actualRows.some(r=>r[2]==='已停用'));check('real unknown total stays unknown',actualRows.some(r=>r[2]==='总集数未确认'));check('real normal pending range remains visible',actualRows.some(r=>/第 \d+–\d+ 集/.test(r[2])));await capture('actual');
 // Browser response fixtures only, so finished/active/edge states require no business writes.
 const cases=[
  {name:'已完结且全部公开',total:35,published:35,state:'published',planState:'finished',start:36,size:10,expected:'无后续分集'},
  {name:'下架且全部公开',total:5,published:5,state:'withdrawn',planState:'disabled',start:6,size:10,expected:'无后续分集 · 已停用'},
  {name:'下架仍有未公开',total:35,published:10,state:'withdrawn',planState:'disabled',start:11,size:10,expected:'已停用'},
  {name:'正常下一批',total:35,published:10,state:'published',planState:'active',start:11,size:10,expected:'第 11–20 集'},
  {name:'最后不足一批',total:35,published:30,state:'published',planState:'active',start:31,size:10,expected:'第 31–35 集'},
  {name:'总集数未知',total:null,published:0,state:'draft',planState:'draft',start:1,size:10,expected:'总集数未确认'},
  {name:'游标越界但有未公开',total:5,published:3,state:'published',planState:'active',start:6,size:10,expected:'后续范围待核对'},
  {name:'已结束但公开数不齐',total:5,published:3,state:'published',planState:'finished',start:6,size:10,expected:'计划已结束，请核对未公开分集'}
 ];
 const fixture={...before,dramas:cases.map((c,i)=>({...before.dramas[0],id:'display-fixture-'+i,title_zh:c.name,title_en:'',total_episodes:c.total,published_count:c.published,state:c.state,plan:{...before.dramas[0].plan,state:c.planState,mode:'updating',start_number:c.start,batch_size:c.size,next_at:null}}))};
 const route=/\/api\/v1\/admin\/content$/;await page.route(route,r=>r.fulfill({status:200,contentType:'application/json',body:JSON.stringify(fixture)}));await open();
 for(let i=0;i<cases.length;i++)check(cases[i].name,await page.locator('#content-view-releases tbody tr').nth(i).locator('td').nth(2).textContent()===cases[i].expected);
 await capture('response-fixture');await page.unroute(route);const after=await read();check('real content payload is identical before and after',snapshot(before)===snapshot(after));check('no content mutation requests',requests.every(r=>r.method==='GET'));check('no browser script errors',errors.length===0);
 fs.writeFileSync(out+'/browser.json',JSON.stringify({passed:passed.length,cases:passed,errors,requests,realRows:actualRows,fixtureCases:cases,beforeSha256:crypto.createHash('sha256').update(snapshot(before)).digest('hex'),afterSha256:crypto.createHash('sha256').update(snapshot(after)).digest('hex'),boundary:'Actual screenshots are the unchanged real API list. response-fixture screenshots are explicitly intercepted browser-only rows, never database writes. Mobile table is horizontally scrolled to the next-batch column.',browser:await browser.version()},null,2));
}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
