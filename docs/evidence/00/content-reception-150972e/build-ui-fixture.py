from pathlib import Path
import re, json, subprocess
root=Path.cwd()
out=root/'.runtime/receipt-ui/recheck-150972e'
out.mkdir(parents=True,exist_ok=True)
view=subprocess.check_output(['git','show','150972e:h5-platform/resources/views/content/library.blade.php'], text=True)
view=view.replace('<x-filament-panels::page>','').replace('</x-filament-panels::page>','').replace('data-owner="{{ $isOwner ? \'1\' : \'0\' }}"','data-owner="1"')
view=re.sub(r'@foreach\(\[1,2,5,10,20\] as \$size\).*?@endforeach',''.join(f'<option value="{n}">{n}</option>' for n in [1,2,5,10,20]),view)
view=view.replace('@if($isOwner)','').replace('@endif','').replace('/content/admin.js','/recheck-150972e/admin.js').replace('/content/admin.css','/recheck-150972e/admin.css')
mock='''
const oldAssets = [{id:'cover-old',kind:'cover',state:'ready',metadata:{}},{id:'video-one',kind:'video',state:'ready',metadata:{}},{id:'video-two',kind:'video',state:'ready',metadata:{}}];
const episodes=[{id:'episode-one',number:1,version:1,title_en:'Episode one',title_zh:'',video_asset_id:'video-one',subtitle_asset_id:null,subtitle_language:null,published_at:null},{id:'episode-two',number:2,version:1,title_en:'Episode two',title_zh:'',video_asset_id:'video-two',subtitle_asset_id:null,subtitle_language:null,published_at:null}];
const drama={id:'drama-one',title_en:'Isolated review fixture',title_zh:'',synopsis_en:'',synopsis_zh:'',version:1,state:'draft',sort_order:0,published_count:0,total_episodes:62,free_count:5,free_overridden:false,cover_asset_id:'cover-old',regions:['US'],license_from:'2026-10-06T00:00:00Z',license_until:'2027-10-06T00:00:00Z',rights_note:'Synthetic review only',categories:[],episodes,plan:{version:1,mode:'updating',state:'draft',start_number:1,batch_size:10,interval_days:1,launch_at:'2026-10-08T00:00:00Z',next_at:null}};
const other={...structuredClone(drama),id:'drama-other',title_en:'Second isolated drama',episodes:[]};
window.receiptRequests=[];
const scenario=new URLSearchParams(location.search).get('case')||'episode';
window.fetch=async(url,options={})=>{
 let result={};const body=options.body?JSON.parse(options.body):null;
 if(url.endsWith('/csrf'))result={csrf_token:'isolated-no-network'};
 else if(options.method==='PATCH') {
  window.receiptRequests.push({method:options.method,url,body});
  document.getElementById('receipt-results').textContent=JSON.stringify(window.receiptRequests,null,2);
  if(url.includes('/episodes/') && body.number===2 && url.endsWith('/episode-one')) return {ok:false,status:422,json:async()=>({error:{code:'VALIDATION_FAILED',message:'重复集号，未修改任何记录'}})};
  if(url.endsWith('/drama-one')) Object.assign(drama,body); else {const ep=episodes.find(e=>url.endsWith('/'+e.id));if(ep)Object.assign(ep,body);}
  result={id:'drama-one'};
 } else if(url==='/api/v1/admin/content') result={dramas:[drama,other],assets:scenario==='assets'?[...Array.from({length:200},(_,i)=>({id:'new-'+i,kind:'video',state:'ready',metadata:{}})),oldAssets[0]]:oldAssets,categories:[],banners:[],default_free_count:5};
 else if(url==='/api/v1/admin/content/dramas/drama-one')result={drama,assets:oldAssets,publications:[]};
 else if(url==='/api/v1/admin/content/dramas/drama-other')result={drama:other,assets:oldAssets,publications:[]};
 else if(options.method==='POST'&&url.endsWith('/episodes')){window.receiptRequests.push({method:options.method,url,body});document.getElementById('receipt-results').textContent=JSON.stringify(window.receiptRequests,null,2);result={};}
 else throw new Error('Unexpected fixture request: '+url);
 return {ok:true,json:async()=>JSON.parse(JSON.stringify(result))};
};
'''
(out/'index.html').write_text('<!doctype html><meta charset="utf-8"><title>总控隔离复现，不连接真实数据</title><h1>总控隔离复现数据</h1><p>虚构数据，只检查候选页面构造的请求；真实数据库另跑回归。</p><pre id="receipt-results">尚未提交</pre><script>'+mock+'</script>'+view)
(out/'admin.js').write_bytes(subprocess.check_output(['git','show','150972e:h5-platform/public/content/admin.js']))
(out/'admin.css').write_bytes(subprocess.check_output(['git','show','150972e:h5-platform/public/content/admin.css']))
print('Created isolated UI fixture using exact candidate admin.js and view controls; fetch is synthetic, not a production runtime.')
