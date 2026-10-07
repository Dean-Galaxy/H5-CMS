(() => {
  const root = document.getElementById('content-app');
  if (!root || root.dataset.initialized) return;
  root.dataset.initialized = '1';
  const $ = id => document.getElementById(id);
  const state = {data:null, drama:null, category:null, banner:null, planPreview:null, manualPreview:null, defaultPreview:null, csrf:null};
  const base = '/api/v1/admin/content';
  const statuses = {draft:'草稿', published:'已公开', withdrawn:'已下架', uploading:'上传中', pending:'待处理', processing:'处理中', ready:'就绪', failed:'失败', canceled:'已取消', active:'执行中', paused:'已暂停', disabled:'已停用', finished:'计划结束', missed_pending_policy:'漏排期，待处理'};
  function message(text, error=false) { $('content-message').textContent=text; $('content-message').style.background=error?'#fee2e2':'#f0fdf4'; $('content-message').style.color=error?'#991b1b':'#166534'; }
  async function api(path, method='GET', body=null, extra={}) {
    if (!state.csrf) {
      const r=await fetch('/api/v1/admin/csrf',{credentials:'same-origin',headers:{Accept:'application/json'}});
      if (!r.ok) throw new Error('后台会话不可用，请重新登录。');
      state.csrf=(await r.json()).csrf_token;
    }
    const headers={Accept:'application/json','X-CSRF-TOKEN':state.csrf,...extra};
    if (body!==null && !(body instanceof Blob)) headers['Content-Type']='application/json';
    const r=await fetch(base+path,{method,headers,body:body===null?null:body instanceof Blob?body:JSON.stringify(body),credentials:'same-origin'});
    const json=await r.json().catch(()=>({}));
    if (!r.ok) throw new Error((json.error?.message||'操作失败')+(json.error?.fields?'：'+JSON.stringify(json.error.fields):'')+' ['+(json.error?.code||r.status)+']');
    return json;
  }
  function run(fn) { return async e => { e?.preventDefault(); try { message('正在处理，请稍候…'); await fn(e); if($('content-message').textContent==='正在处理，请稍候…')message('操作完成。'); } catch(err) { message(err.message,true); } }; }
  function form(id) { return $(id); }
  function values(id) { const f=form(id), obj=Object.fromEntries(new FormData(f)); for(const el of f.elements) if(el.name && el.type==='checkbox') obj[el.name]=el.checked; return obj; }
  function fill(id, data) { const f=form(id); f.reset(); for(const el of f.elements) { if(!el.name || !(el.name in data)) continue; if(el.type==='checkbox') el.checked=Boolean(data[el.name]); else if(el.multiple) for(const o of el.options) o.selected=data[el.name].includes(o.value); else el.value=data[el.name]??''; } }
  const integer=v=>v===''||v===null?null:Number(v);
  const utc=value=>value?value+':00+08:00':null;
  const local=value=>value?new Date(new Date(value).getTime()+8*3600000).toISOString().slice(0,16):'';
  const beijing=value=>new Date(new Date(value).getTime()+8*3600000).toISOString().slice(0,19).replace('T',' ')+' 北京时间';
  const title=d=>d.title_zh||d.title_en||'未命名草稿';
  function optionList(id, items, label, allowBlank=true) { const s=$(id),old=s.value; s.replaceChildren(); if(allowBlank)s.add(new Option('未选择','')); for(const item of items)s.add(new Option(label(item),item.id)); if([...s.options].some(o=>o.value===old))s.value=old; }
  function row(container,text,actions=[]) { const div=document.createElement('div'); div.className='row'; const label=document.createElement('span');label.textContent=text;div.append(label); const controls=document.createElement('div');controls.className='toolbar';for(const [caption,fn] of actions){const b=document.createElement('button');b.type='button';b.textContent=caption;b.addEventListener('click',run(fn));controls.append(b);}div.append(controls);container.append(div); }
  async function reload(selected=state.drama?.id) {
    const d=state.data=await api('');
    $('drama-list').replaceChildren();
    d.dramas.forEach(x=>row($('drama-list'),`${title(x)} · ${statuses[x.state]} · 已公开 ${x.published_count}/${x.total_episodes??'总数未定'} · N=${x.free_count}`,[['编辑',()=>selectDrama(x.id)]]));
    if(!d.dramas.length)row($('drama-list'),'还没有剧目，先保存一部草稿。');
    renderAssetOptions(d);
    optionList('drama-categories',d.categories,x=>x.name_zh||x.name_en,false);
    optionList('banner-dramas',d.dramas,title);
    renderAssets(d);
    $('category-list').replaceChildren();d.categories.forEach(c=>row($('category-list'),`${c.name_zh||c.name_en} · 排序 ${c.sort_order} · ${c.active?'启用':'停用'}`,[['编辑',()=>{state.category=c;fill('category-form',c);}]]));
    $('banner-list').replaceChildren();d.banners.forEach(b=>row($('banner-list'),`${b.title_zh||b.title_en} · 排序 ${b.sort_order} · ${b.active?'启用':'停用'}`,[['编辑',()=>{state.banner=b;fill('banner-form',b);}]]));
    if($('default-form')&&!state.defaultPreview)form('default-form').elements.free_count.value=d.default_free_count;
    if(selected)await selectDrama(selected); else newDrama();
  }
  function renderAssetOptions(d) {
    const assetLabel=x=>`${x.metadata?.original_filename||x.id.slice(-8)} · ${statuses[x.state]}${x.metadata?.width?' · '+x.metadata.width+'×'+x.metadata.height:''}`;
    for(const [el,kind] of [['cover-assets','cover'],['banner-assets','banner'],['video-assets','video'],['subtitle-assets','subtitle']])optionList(el,d.assets.filter(a=>a.kind===kind),assetLabel);
  }
  function renderAssets(d) {
    $('asset-list').replaceChildren();
    d.assets.forEach(a=>row($('asset-list'),`${a.metadata?.original_filename||a.id} · ${a.kind} · ${statuses[a.state]} · ${a.received_bytes}/${a.expected_bytes}${a.error_code?' · '+a.error_code:''}`,a.state==='uploading'?[['取消上传',async()=>{await api('/uploads/'+a.id+'/cancel','POST',{});await reload();}]]:a.state==='failed'?[['重试处理',async()=>{await api('/uploads/'+a.id+'/retry','POST',{});await reload();}]]:[]));
    $('more-assets').disabled=!d.asset_cursor;
  }
  function newDrama() {state.drama=null;$('selected-tools').hidden=true;fill('drama-form',{sort_order:0});$('drama-heading').textContent='新剧草稿';}
  async function selectDrama(id) {
    const result=await api('/dramas/'+id),d=state.drama=result.drama;
    const merged=new Map(state.data.assets.map(a=>[a.id,a]));for(const a of result.assets)merged.set(a.id,a);state.data.assets=[...merged.values()];renderAssetOptions(state.data);renderAssets(state.data);
    fill('drama-form',{...d,regions:d.regions.join(','),license_from:local(d.license_from),license_until:local(d.license_until),category_ids:d.categories.map(c=>c.id)});
    $('drama-heading').textContent=title(d)+' · '+statuses[d.state];$('selected-tools').hidden=false;
    fill('free-form',{free_count:d.free_count});$('free-status').textContent=d.free_overridden?'已单独设置，全局同步保留此值。':'沿用创建时默认值，可参加未覆盖剧同步。';
    fill('plan-form',{...d.plan,launch_at:local(d.plan.launch_at)});$('plan-status').textContent=`${statuses[d.plan.state]} · 版本 ${d.plan.version} · 下次 ${d.plan.next_at?local(d.plan.next_at).replace('T',' '):'未安排'} 北京时间${d.plan.last_error?' · '+d.plan.last_error:''}`;
    state.planPreview=null;state.manualPreview=null;$('activate-plan').disabled=true;$('manual-confirm').disabled=true;$('plan-preview').textContent='';$('manual-preview').textContent='';
    $('episode-list').replaceChildren();
    d.episodes.forEach(ep=>row($('episode-list'),`第 ${ep.number} 集 · ${ep.published_at?'已公开':'草稿'} · ${ep.title_zh||ep.title_en||''}`,[['编辑',()=>fill('episode-form',ep)],['草稿预览',()=>{window.open('/admin/content/episodes/'+ep.id+'/preview','_blank','noopener');}],...(!ep.published_at?[['删除草稿分集',async()=>{await api('/dramas/'+d.id+'/episodes/'+ep.id,'DELETE',{version:ep.version});await reload(d.id);}]]:[])]));
    $('publication-history').textContent=result.publications.map(p=>`${beijing(p.executed_at)} | 计划 ${p.scheduled_at?beijing(p.scheduled_at):'手动'} | ${p.result} | 第 ${p.episode_numbers.join(',')} 集 | ${p.error_code||''}`).join('\n')||'暂无发布记录';
  }
  function dramaInput() {const d=values('drama-form');return {...d,total_episodes:integer(d.total_episodes),cover_asset_id:d.cover_asset_id||null,regions:d.regions.split(',').map(x=>x.trim().toUpperCase()).filter(Boolean),license_from:utc(d.license_from),license_until:utc(d.license_until),category_ids:[...form('drama-form').elements.category_ids.selectedOptions].map(x=>x.value),sort_order:Number(d.sort_order),...(state.drama?{version:state.drama.version}:{})};}
  $('more-assets').addEventListener('click',run(async()=>{if(!state.data.asset_cursor)return;const page=await api('/assets?before='+encodeURIComponent(state.data.asset_cursor));const merged=new Map(state.data.assets.map(a=>[a.id,a]));for(const a of page.assets)merged.set(a.id,a);state.data.assets=[...merged.values()];state.data.asset_cursor=page.next_cursor;renderAssetOptions(state.data);renderAssets(state.data);message('已读取更早的素材。');}));
  $('reload').addEventListener('click',run(async()=>{await reload();message('已刷新处理、公开和计划状态。');}));$('new-drama').addEventListener('click',newDrama);
  form('drama-form').addEventListener('submit',run(async()=>{const d=await api('/dramas'+(state.drama?'/'+state.drama.id:''),state.drama?'PATCH':'POST',dramaInput());await reload(d.id);message('剧目已保存。保存和上传不会自动公开。');}));
  form('episode-form').addEventListener('submit',run(async()=>{const d=values('episode-form');d.number=Number(d.number);d.video_asset_id||=null;d.subtitle_asset_id||=null;if(!d.subtitle_asset_id)d.subtitle_language=null;const current=state.drama.episodes.find(x=>x.number===d.number);if(current)d.version=current.version;await api('/dramas/'+state.drama.id+'/episodes'+(current?'/'+current.id:''),current?'PATCH':'POST',d);await reload();message('分集已保存。');}));
  form('free-form').addEventListener('submit',run(async()=>{await api('/dramas/'+state.drama.id+'/free','PATCH',{version:state.drama.version,free_count:Number(values('free-form').free_count)});await reload();message('已保存单剧覆盖，含手动同值。');}));
  form('plan-form').addEventListener('submit',run(async()=>{const d=values('plan-form');await api('/dramas/'+state.drama.id+'/plan','PUT',{...d,version:state.drama.plan.version,start_number:Number(d.start_number),interval_days:Number(d.interval_days),batch_size:Number(d.batch_size),launch_at:utc(d.launch_at)});await reload();message('计划已保存并停用旧执行版本，请预览后确认启用。');}));
  function previewText(d) {return `本次：第 ${d.from}—${d.through} 集（${d.count} 集）\n模式：${d.mode==='completed'?'已完结，一次发布全集':'更新中'}\n执行时间：${d.manual?'确认后立即发布':local(d.launch_at).replace('T',' ')+' 北京时间'}\n总集数：${d.total_episodes??'尚未确认'}\n${d.blocked_reason?'当前阻断原因：'+d.blocked_reason:'当前检查通过；执行时仍重新检查素材和授权。'}`;}
  $('preview-plan').addEventListener('click',run(async()=>{state.planPreview=await api('/dramas/'+state.drama.id+'/publication-preview','POST',{});$('plan-preview').textContent=previewText(state.planPreview);$('activate-plan').disabled=false;}));
  $('activate-plan').addEventListener('click',run(async()=>{await api('/dramas/'+state.drama.id+'/activate','POST',{version:state.planPreview.plan_version,confirmation:state.planPreview.confirmation});await reload();message('计划已启用，届时由服务器检查并执行。');}));
  $('pause-plan').addEventListener('click',run(async()=>{await api('/dramas/'+state.drama.id+'/pause','POST',{version:state.drama.plan.version});await reload();message('后续更新已暂停；已公开内容保留。');}));
  form('resume-form').addEventListener('submit',run(async()=>{await api('/dramas/'+state.drama.id+'/resume','POST',{version:state.drama.plan.version,next_at:utc(values('resume-form').next_at)});await reload();message('已按确认的新时间恢复，不累计补发。');}));
  form('manual-form').addEventListener('submit',run(async()=>{const through=Number(values('manual-form').through);state.manualPreview=await api('/dramas/'+state.drama.id+'/publication-preview','POST',{through});state.manualPreview.requestThrough=through;$('manual-preview').textContent=previewText(state.manualPreview);$('manual-confirm').disabled=Boolean(state.manualPreview.blocked_reason);}));
  $('manual-confirm').addEventListener('click',run(async()=>{const p=state.manualPreview;await api('/dramas/'+state.drama.id+'/publish','POST',{version:p.drama_version,through:p.requestThrough,confirmation:p.confirmation});await reload();message('本次连续分集已实际公开。');}));
  $('withdraw').addEventListener('click',run(async()=>{if(!window.confirm('确认整剧下架，并停用旧发布计划？'))return;await api('/dramas/'+state.drama.id+'/withdraw','POST',{version:state.drama.version});await reload();message('已下架；旧计划和旧播放会话不能重新放行。');}));
  form('relist-form').addEventListener('submit',run(async()=>{const d=values('relist-form');await api('/dramas/'+state.drama.id+'/relist','POST',{version:state.drama.version,choice:d.choice,next_at:utc(d.next_at)});await reload();message('已按明确选择重新上架。请核对计划状态。');}));
  for(const kind of ['category','banner']) {
    $('new-'+kind).addEventListener('click',()=>{state[kind]=null;form(kind+'-form').reset();});
    form(kind+'-form').addEventListener('submit',run(async()=>{const d=values(kind+'-form');d.sort_order=Number(d.sort_order);const current=state[kind];if(current)d.version=current.version;await api('/'+(kind==='category'?'categories':'banners')+(current?'/'+current.id:''),current?'PATCH':'POST',d);state[kind]=null;form(kind+'-form').reset();await reload();message('已保存，公开首页将按当前状态和授权过滤。');}));
  }
  if($('default-form')) {
    form('default-form').addEventListener('submit',run(async()=>{const d=values('default-form');state.defaultPreview=await api('/default-preview','POST',{...d,free_count:Number(d.free_count)});const p=state.defaultPreview;$('default-preview').textContent=`新默认 N=${p.free_count} · ${p.scope==='sync'?'同步未覆盖剧':'仅新剧'}\n影响已有剧 ${p.count} 部\n`+p.dramas.map(d=>`${title(d)}：${d.free_count} → ${p.free_count}`).join('\n');$('default-confirm').disabled=false;}));
    $('default-confirm').addEventListener('click',run(async()=>{const p=state.defaultPreview;await api('/default','PATCH',{version:p.version,free_count:p.free_count,scope:p.scope,confirmation:p.confirmation});state.defaultPreview=null;$('default-confirm').disabled=true;await reload();message('全局默认已更新；手动覆盖剧保留。');}));
  }
  form('upload-form').addEventListener('submit',run(async()=>{
    const f=form('upload-form'),kind=f.elements.kind.value,language=f.elements.language.value,attach=f.elements.attach.checked,dramaId=state.drama.id;
    const files=[...f.elements.files.files].sort((a,b)=>a.name.localeCompare(b.name,undefined,{numeric:true}));
    if(attach&&['video','subtitle'].includes(kind)&&files.some(x=>!/^\d+\.(mp4|srt)$/i.test(x.name)))throw new Error('自动登记要求数字文件名，例如 1.mp4 / 1.srt。也可取消自动登记后手动绑定。');
    const button=f.querySelector('button');button.disabled=true;
    try {for(let i=0;i<files.length;i++) {
      const file=files[i],asset=await api('/uploads','POST',{kind,filename:file.name,bytes:file.size});
      for(let pos=0;pos<file.size;pos+=1048576){await api('/uploads/'+asset.id+'/chunks','POST',file.slice(pos,pos+1048576),{'Content-Type':'application/octet-stream','Upload-Offset':String(pos)});$('upload-progress').value=100*(i+Math.min(pos+1048576,file.size)/file.size)/files.length;message(`正在上传 ${i+1}/${files.length}：${file.name}`);}
      await api('/uploads/'+asset.id+'/finish','POST',{});
      if(attach&&['video','subtitle'].includes(kind)) {
        const d=(await api('/dramas/'+dramaId)).drama,num=Number(file.name.split('.')[0]),ep=d.episodes.find(x=>x.number===num);
        const payload={number:num,title_en:ep?.title_en||'',title_zh:ep?.title_zh||'',video_asset_id:ep?.video_asset_id||null,subtitle_asset_id:ep?.subtitle_asset_id||null,subtitle_language:ep?.subtitle_language||null,...(ep?{version:ep.version}:{})};
        if(kind==='video')payload.video_asset_id=asset.id;else {payload.subtitle_asset_id=asset.id;payload.subtitle_language=language;}
        await api('/dramas/'+dramaId+'/episodes'+(ep?'/'+ep.id:''),ep?'PATCH':'POST',payload);
      }
    }await reload(dramaId);message(`已完成 ${files.length} 个文件上传；等待媒体处理，未自动发布。`);}finally{button.disabled=false;}
  }));
  run(async()=>{await reload(null);message('已读取真实内容。先保存草稿，再上传素材。');})();
})();
