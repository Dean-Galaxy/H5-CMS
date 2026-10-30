/* Independent synthetic loopback HTTP + Playwright request API only. Never launches a browser or H5. */
'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),http=require('node:http');
const {request}=require('/Users/hejunxiang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js');
const {backendFetchOptions,createBackend429Guard}=require('./r1_faults_v2.cjs');
async function serve(handler){const server=http.createServer(handler);await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const port=server.address().port;assert(![8412,5912].includes(port));return {server,url:'http://127.0.0.1:'+port};}
async function close(server){await new Promise(resolve=>server.close(resolve));}
async function body(request){let value='';for await(const chunk of request)value+=chunk;return value;}
test('synthetic cross-origin 302 baseline follows, forced bridge preserves first hop without downstream request',async()=>{
 let downstream=0;const b=await serve((req,res)=>{downstream++;res.writeHead(200,{'Content-Type':'text/plain'});res.end('synthetic destination');});
 const a=await serve((req,res)=>{res.writeHead(302,{Location:b.url+'/destination'});res.end();});let ctx;
 try{ctx=await request.newContext({timeout:5000});const old=await ctx.get(a.url+'/redirect',{maxRetries:0});assert.equal(old.status(),200);assert.equal(downstream,1);downstream=0;
 const fixed=await ctx.fetch(a.url+'/redirect',backendFetchOptions({method:'GET',maxRedirects:20,maxRetries:8}));assert.equal(fixed.status(),302);assert.equal(fixed.headers().location,b.url+'/destination');assert.equal(downstream,0);
 }finally{if(ctx)await ctx.dispose();await Promise.all([close(a.server),close(b.server)]);}
});
test('synthetic normal POST keeps body/header/cookie and API response Set-Cookie; browser fulfillment remains untested',async()=>{
 const a=await serve(async(req,res)=>{const value=await body(req);res.writeHead(200,{'Content-Type':'application/json','Set-Cookie':'synthetic_rotated=opaque-new; Path=/; HttpOnly; SameSite=Lax'});res.end(JSON.stringify({method:req.method,body:value,header:req.headers['x-synthetic-case'],cookie:req.headers.cookie??''}));});let ctx;
 try{ctx=await request.newContext({timeout:5000,storageState:{cookies:[{name:'synthetic_session',value:'opaque-old',domain:'127.0.0.1',path:'/',expires:-1,httpOnly:true,secure:false,sameSite:'Lax'}],origins:[]}});
 const text='{"marker":"only-synthetic"}',response=await ctx.fetch(a.url+'/echo',backendFetchOptions({method:'POST',data:text,headers:{'Content-Type':'application/json','X-Synthetic-Case':'guard-probe'}})),data=await response.json();assert.equal(response.status(),200);assert.equal(data.method,'POST');assert.equal(data.body,text);assert.equal(data.header,'guard-probe');assert.match(data.cookie,/synthetic_session=opaque-old/);assert.match(response.headers()['set-cookie'],/synthetic_rotated=opaque-new/);
 const next=await ctx.fetch(a.url+'/echo',backendFetchOptions({method:'GET'}));assert.match((await next.json()).cookie,/synthetic_rotated=opaque-new/);
 }finally{if(ctx)await ctx.dispose();await close(a.server);}
});
test('synthetic real HTTP 429 preserves raw header and stops next purpose and duplicate registration at server counts',async()=>{
 let registrations=0,after=0;const a=await serve((req,res)=>{if(req.url==='/register'){registrations++;res.writeHead(201);res.end('{}');}else if(req.url==='/limit'){res.writeHead(429,{'Retry-After':'0027'});res.end('{}');}else{after++;res.writeHead(200);res.end('{}');}});let ctx;
 try{ctx=await request.newContext({timeout:5000});const saved=[],guard=createBackend429Guard(r=>saved.push(r)),meta=(method,path)=>({method,path,forward_source:'synthetic_APIRequestContext'});
 await guard.transfer(meta('POST','/register'),()=>ctx.fetch(a.url+'/register',backendFetchOptions({method:'POST',data:'synthetic'})));
 const limited=guard.transfer(meta('PATCH','/limit'),()=>ctx.fetch(a.url+'/limit',backendFetchOptions({method:'PATCH'}))),queued=guard.transfer(meta('PATCH','/after'),()=>ctx.fetch(a.url+'/after',backendFetchOptions({method:'PATCH'})));assert((await Promise.allSettled([limited,queued])).every(r=>r.status==='rejected'));
 await assert.rejects(guard.transfer(meta('POST','/register'),()=>ctx.fetch(a.url+'/register',backendFetchOptions({method:'POST'}))));assert.equal(registrations,1);assert.equal(after,0);assert.equal(saved[0].retry_after_raw,'0027');assert.equal(saved[0].path,'/limit');
 }finally{if(ctx)await ctx.dispose();await close(a.server);}
});
