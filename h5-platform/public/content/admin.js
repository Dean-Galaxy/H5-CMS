(() => {
  const root = document.getElementById('content-app');
  if (!root || root.dataset.initialized) return;
  root.dataset.initialized = '1';
  const $ = id => document.getElementById(id);
  const state = {data:null, drama:null, episodeEdit:null, selection:0, selectionLoading:false, uploading:false, category:null, banner:null, planPreview:null, manualPreview:null, launchPreview:null, nextBatchPreview:null, defaultPreview:null, csrf:null, view:'dramas', detail:'basics', savedForms:{}};
  const allowedViews=['dramas','releases','categories','banners'],allowedDetails=['basics','episodes','rights','release','history'];
  const initialUrl=new URL(location.href);state.view=allowedViews.includes(initialUrl.searchParams.get('content_view'))?initialUrl.searchParams.get('content_view'):'dramas';state.detail=allowedDetails.includes(initialUrl.searchParams.get('content_detail'))?initialUrl.searchParams.get('content_detail'):'basics';
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
  function run(fn) {
    return async e => {
      const target=e?.currentTarget;e?.preventDefault();
      const otherDrafts=target instanceof HTMLFormElement?dirtyForms().filter(f=>f!==target).map(f=>[f.id,formValues(f)]):[];
      try {
        if(target instanceof HTMLButtonElement&&dirty()&&!target.dataset.confirmFor&&!await guardUnsaved())return;
        if(target?.dataset.confirmFor&&dirtyForms().some(f=>f.id!==target.dataset.confirmFor))throw new Error('请先保存其他表单，再确认此操作。');
        message('正在处理，请稍候…');await fn(e);
        if(target instanceof HTMLFormElement&&target.dataset.previewOnly!=='true')rememberForm(target);
        for(const [id,data] of otherDrafts)fill(id,data);
        if($('content-message').textContent==='正在处理，请稍候…')message('操作完成。');
        root.dispatchEvent(new CustomEvent('content:action-result',{detail:{form:target instanceof HTMLFormElement?target:null,ok:true}}));
      }catch(err){
        for(const [id,data] of otherDrafts)fill(id,data);
        message(err.message,true);root.dispatchEvent(new CustomEvent('content:action-result',{detail:{form:target instanceof HTMLFormElement?target:null,ok:false}}));
      }
    };
  }
  function form(id) { return $(id); }
  const trackedFormIds=['drama-form','episode-form','rights-form','free-form','plan-form','resume-form','manual-form','relist-form','category-form','banner-form','default-form'];
  function formValues(f){const out={};for(const el of f.elements){if(!el.name||el.type==='file')continue;out[el.name]=el.type==='checkbox'?el.checked:el.multiple?[...el.selectedOptions].map(o=>o.value):el.value;}return out;}
  function rememberForm(f){if(f?.id&&trackedFormIds.includes(f.id))state.savedForms[f.id]=formValues(f);}
  function dirtyForms(){return trackedFormIds.map(id=>$(id)).filter(f=>f&&JSON.stringify(formValues(f))!==JSON.stringify(state.savedForms[f.id]??{}));}
  function restoreDirtyForms(){for(const f of dirtyForms())fill(f.id,state.savedForms[f.id]??{});syncPlanFields();state.planPreview=state.manualPreview=state.launchPreview=state.nextBatchPreview=state.defaultPreview=null;for(const id of ['confirm-publication','manual-confirm','next-batch-confirm','default-confirm'])if($(id))$(id).disabled=true;return !dirtyForms().length;}
  async function saveDirtyForm(){
    if(state.uploading||dirtyForms().some(f=>f.dataset.previewOnly==='true'))return false;
    for(const f of [...dirtyForms()]){
      if(!f.reportValidity())return false;
      const saved=await new Promise(resolve=>{
        let done=false;const finish=value=>{if(done)return;done=true;clearTimeout(timer);root.removeEventListener('content:action-result',listener);resolve(value);};
        const listener=e=>{if(e.detail.form===f)finish(e.detail.ok===true);};
        const timer=setTimeout(()=>finish(false),30000);root.addEventListener('content:action-result',listener);f.requestSubmit();
      });
      if(!saved)return false;
    }
    return !dirtyForms().length;
  }
  const dirty=()=>state.uploading||dirtyForms().length>0;
  async function guardUnsaved(){if(!dirty())return true;return window.H5Admin?window.H5Admin.confirmUnsaved({save:saveDirtyForm,discard:async()=>!state.uploading&&restoreDirtyForms()}):false;}
  function values(id) { const f=form(id), obj=Object.fromEntries(new FormData(f)); for(const el of f.elements) if(el.name && el.type==='checkbox') obj[el.name]=el.checked; return obj; }
  function fill(id, data) { const f=form(id); f.reset(); for(const el of f.elements) { if(!el.name || !(el.name in data)) continue; if(el.type==='checkbox') el.checked=Boolean(data[el.name]); else if(el.multiple) for(const o of el.options) o.selected=data[el.name].includes(o.value); else el.value=data[el.name]??''; } }
  const integer=v=>v===''||v===null?null:Number(v);
  const utc=value=>value?value+':00+08:00':null;
  const local=value=>value?new Date(new Date(value).getTime()+8*3600000).toISOString().slice(0,16):'';
  const beijing=value=>new Date(new Date(value).getTime()+8*3600000).toISOString().slice(0,19).replace('T',' ')+' 北京时间';
  const title=d=>d.title_zh||d.title_en||'未命名草稿';
  const nextRange=(out,batch,total)=>out.plan_state==='finished'||!total||out.next_number>total?'无':`第 ${out.next_number}—${Math.min(total,out.next_number+batch-1)} 集`;
  function optionList(id, items, label, allowBlank=true) { const s=$(id),old=s.value; s.replaceChildren(); if(allowBlank)s.add(new Option('未选择','')); for(const item of items)s.add(new Option(label(item),item.id)); if([...s.options].some(o=>o.value===old))s.value=old; }
  function row(container,text,actions=[]) { const div=document.createElement('div'); div.className='row'; const label=document.createElement('span');label.textContent=text;div.append(label); const controls=document.createElement('div');controls.className='toolbar';for(const [caption,fn] of actions){const b=document.createElement('button');b.type='button';b.textContent=caption;b.addEventListener('click',run(fn));controls.append(b);}div.append(controls);container.append(div); }
  function activateSharedTab(groupId,id) {const button=document.querySelector(`#${groupId} [data-admin-tab="${id}"]`);if(button&&button.getAttribute('aria-selected')!=='true')window.H5Admin?.activateTab(button);}
  let restoringUrl=false;
  function writeTabUrl(replace=false){const url=new URL(location.href);url.searchParams.set('content_view',state.view);url.searchParams.set('content_detail',state.detail);if(state.drama)url.searchParams.set('content_drama',state.drama.id);else url.searchParams.delete('content_drama');if(url.href!==location.href)history[replace?'replaceState':'pushState']({content:true},'',url);}
  function setView(view) {state.view=view;activateSharedTab('content-tabs',view);}
  function setDetail(detail) {state.detail=detail;activateSharedTab('content-detail-tabs',detail);if(detail==='history')$('publication-history').closest('details').open=true;}
  function resetEpisodeForm() {
    state.episodeEdit=null;
    const used=new Set(state.drama?.episodes.map(ep=>ep.number)||[]);
    let number=1;while(used.has(number))number++;
    fill('episode-form',{number,title_en:'',title_zh:'',video_asset_id:'',subtitle_asset_id:'',subtitle_language:'en'});
    form('episode-form').elements.number.readOnly=false;form('episode-form').elements.video_asset_id.disabled=false;form('episode-form').elements.subtitle_asset_id.disabled=false;
    $('episode-mode').textContent='新增草稿分集';$('save-episode').textContent='新增分集';
    rememberForm(form('episode-form'));
  }
  function beginSelection() {
    state.selectionLoading=true;state.drama=null;
    resetEpisodeForm();
    return ++state.selection;
  }
  function editEpisode(dramaId, ep) {
    if(state.selectionLoading||state.drama?.id!==dramaId)return;
    state.episodeEdit={id:ep.id,version:ep.version,dramaId,video_asset_id:ep.video_asset_id,subtitle_asset_id:ep.subtitle_asset_id};
    fill('episode-form',ep);form('episode-form').elements.number.readOnly=Boolean(ep.published_at);form('episode-form').elements.video_asset_id.disabled=Boolean(ep.video_asset_id||ep.published_at);form('episode-form').elements.subtitle_asset_id.disabled=Boolean(ep.subtitle_asset_id||ep.published_at);
    $('episode-mode').textContent=`正在编辑第 ${ep.number} 集${ep.published_at?'（已公开，序号不可修改）':'；修改序号仍保存这条原分集'}`;
    $('save-episode').textContent='保存分集修改';$('episode-editor').open=true;
    rememberForm(form('episode-form'));
  }
  async function reload(selected=state.drama?.id) {
    const previousView=state.view,selection=beginSelection(),d=await api('');
    if(selection!==state.selection)return;
    state.data=d;
    $('drama-list').replaceChildren();
    d.dramas.forEach(x=>row($('drama-list'),`${title(x)} · ${statuses[x.state]} · 已公开 ${x.published_count}/${x.total_episodes??'总数未定'} · N=${x.free_count}`,[['管理剧目',()=>selectDrama(x.id,'basics')]]));
    $('release-drama-list').replaceChildren();d.dramas.forEach(x=>row($('release-drama-list'),`${title(x)} · ${statuses[x.state]} · 已公开 ${x.published_count}/${x.total_episodes??'总数未定'} · N=${x.free_count} · ${statuses[x.plan.state]} · 下次 ${x.plan.next_at?beijing(x.plan.next_at):'未安排'}`,[['修改发布安排',()=>selectDrama(x.id,'release')]]));
    if(!d.dramas.length)row($('drama-list'),'还没有剧目，先保存一部草稿。');
    renderAssetOptions(d);
    optionList('drama-categories',d.categories,x=>x.name_zh||x.name_en,false);
    optionList('banner-dramas',d.dramas,title);
    renderAssets(d);
    $('category-list').replaceChildren();d.categories.forEach(c=>row($('category-list'),`${c.name_zh||c.name_en} · 排序 ${c.sort_order} · ${c.active?'启用':'停用'}`,[['编辑',()=>{state.category=c;fill('category-form',c);rememberForm(form('category-form'));}]]));
    $('banner-list').replaceChildren();d.banners.forEach(b=>row($('banner-list'),`${b.title_zh||b.title_en} · 排序 ${b.sort_order} · ${b.active?'启用':'停用'}`,[['编辑',()=>{state.banner=b;fill('banner-form',b);rememberForm(form('banner-form'));}]]));
    if($('default-form')&&!state.defaultPreview)form('default-form').elements.free_count.value=d.default_free_count;
    if($('default-form'))rememberForm(form('default-form'));
    if(selected)await selectDrama(selected,state.detail,false); else newDrama(false);setView(previousView);
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
  function newDrama(navigate=true) {beginSelection();state.selectionLoading=false;fill('drama-form',{sort_order:0});fill('rights-form',{});rememberForm(form('drama-form'));rememberForm(form('rights-form'));$('drama-heading').textContent='新剧草稿';$('drama-summary').textContent='先保存基本资料，再登记分集、授权和发布安排。';lockDetailTabs();if(navigate){setView('dramas');setDetail('basics');}writeTabUrl(true);}
  async function selectDrama(id,targetDetail=state.detail,guard=true) {
    if(guard&&!await guardUnsaved())return;
    const selection=beginSelection(),result=await api('/dramas/'+id);
    if(selection!==state.selection)return;
    const d=state.drama=result.drama;state.selectionLoading=false;lockDetailTabs();$('drama-library').open=false;
    const publishedCount=d.episodes.filter(ep=>ep.published_at).length;
    $('drama-summary').textContent=`${title(d)} · ${statuses[d.state]} · 已公开 ${publishedCount}/${d.total_episodes??'总数未定'} · 前 ${d.free_count} 集免费`;
    setView('dramas');setDetail(allowedDetails.includes(targetDetail)?targetDetail:'basics');writeTabUrl(true);
    const merged=new Map(state.data.assets.map(a=>[a.id,a]));for(const a of result.assets)merged.set(a.id,a);state.data.assets=[...merged.values()];renderAssetOptions(state.data);renderAssets(state.data);
    fill('drama-form',{...d,regions:d.regions.join(','),license_from:local(d.license_from),license_until:local(d.license_until),category_ids:d.categories.map(c=>c.id)});
    rememberForm(form('drama-form'));
    fill('rights-form',{...d,regions:d.regions.join(','),license_from:local(d.license_from),license_until:local(d.license_until)});rememberForm(form('rights-form'));
    $('drama-heading').textContent=title(d)+' · '+statuses[d.state];$('selected-tools').hidden=false;
    fill('free-form',{free_count:d.free_count});$('free-status').textContent=d.free_overridden?'已单独设置，全局同步保留此值。':'沿用创建时默认值，可参加未覆盖剧同步。';
    rememberForm(form('free-form'));
    fill('plan-form',{...d.plan,timing:'scheduled',launch_at:local(d.plan.launch_at),next_at:local(d.plan.next_at)});syncPlanFields();$('plan-status').textContent=`${statuses[d.plan.state]} · 版本 ${d.plan.version} · 下次 ${d.plan.next_at?local(d.plan.next_at).replace('T',' '):'未安排'} 北京时间${d.plan.last_error?' · '+d.plan.last_error:''}`;
    rememberForm(form('plan-form'));
    state.planPreview=null;state.manualPreview=null;state.launchPreview=null;state.nextBatchPreview=null;$('next-batch-confirm').disabled=true;$('next-batch-preview-text').textContent='';$('confirm-publication').disabled=true;$('manual-confirm').disabled=true;$('plan-preview').textContent='';$('manual-preview').textContent='';
    resetEpisodeForm();
    $('episode-list').replaceChildren();
    d.episodes.forEach(ep=>renderEpisode(d,ep));
    const actionNames={'drama.saved':'保存剧目或授权','episode.saved':'保存分集','episode.deleted':'删除草稿分集','episode.media_replaced':'确认替换媒体','free.saved':'修改免费数','plan.saved':'保存计划','plan.activated':'启用计划','plan.paused':'暂停计划','plan.resumed':'恢复计划','plan.launched_now':'立即发布并安排后续','plan.batch_published_early':'提前发布下一批','published':'公开分集','drama.withdrawn':'整剧下架','drama.relisted':'重新上架','upload.canceled':'取消候选'};
    $('operation-history').textContent=(result.operations||[]).map(o=>`${beijing(o.occurred_at)} | ${actionNames[o.action]||o.action} | 版本 ${o.version}`).join('\n')||'暂无升级后的操作记录';
    $('publication-history').textContent=result.publications.map(p=>`${beijing(p.executed_at)} | 计划 ${p.scheduled_at?beijing(p.scheduled_at):'手动'} | ${p.result} | 第 ${p.episode_numbers.join(',')} 集 | ${p.error_code||''}`).join('\n')||'暂无发布记录';
  }
  function assetName(id){const a=state.data.assets.find(a=>a.id===id);return a?`${a.metadata?.original_filename||a.id.slice(-8)} · ${statuses[a.state]}`:'未绑定';}
  function renderEpisode(drama,ep){
    const card=document.createElement('details');card.className='episode-card';
    const heading=document.createElement('summary');heading.textContent=`第 ${ep.number} 集 · ${ep.published_at?'已公开':'草稿'} · ${ep.title_zh||ep.title_en||''}`;card.append(heading);$('episode-list').append(card);
    row(card,'分集操作',[['编辑',()=>editEpisode(drama.id,ep)],['草稿预览',()=>window.open('/admin/content/episodes/'+ep.id+'/preview','_blank','noopener')],...(!ep.published_at?[['删除草稿分集',async()=>{await api(`/dramas/${drama.id}/episodes/${ep.id}`,'DELETE',{version:ep.version});await reload(drama.id);}]]:[])]);
    for(const role of ['video','subtitle']){
      const name=role==='video'?'视频':'字幕',current=ep[role+'_asset_id'];
      row(card,`当前${name}：${assetName(current)}${role==='subtitle'&&ep.subtitle_language?' · '+ep.subtitle_language:''}`,[[`上传${name}候选`,()=>replacementUpload(ep,role)]]);
      const candidates=state.data.assets.filter(a=>a.id!==current&&a.kind===role&&a.metadata?.context_drama_id===drama.id&&a.metadata?.context_episode_id===ep.id&&a.metadata?.context_role==='episode_replacement_'+role);
      for(const candidate of candidates){const actions=[];
        if(candidate.state==='ready')actions.push(['确认替换'+name,async()=>{
          if(!window.confirm(`第 ${ep.number} 集${name}：当前 ${assetName(current)} → ${assetName(candidate.id)}。${role==='subtitle'?'登记语言 '+candidate.metadata.context_language+'，请人工核实实际语言。':'视频替换后旧许可下一次请求将要求重新开始。'}确认切换？`))return;
          await api(`/dramas/${drama.id}/episodes/${ep.id}/media-replacement`,'POST',{drama_version:drama.version,episode_version:ep.version,role,asset_id:candidate.id,subtitle_language:role==='subtitle'?candidate.metadata.context_language:null,confirmation:true});await reload(drama.id);message('素材绑定已确认切换；原文件保留，公开计数与排期不变。');
        }]);
        if(['uploading','pending','processing','failed','ready'].includes(candidate.state))actions.push(['放弃此候选',async()=>{await api('/uploads/'+candidate.id+'/cancel','POST',{});await reload(drama.id);message('已取消候选，当前绑定保持。');}]);
        if(candidate.state==='failed')actions.push(['重试处理',async()=>{await api('/uploads/'+candidate.id+'/retry','POST',{});await reload(drama.id);}]);
        row(card,`候选${name}：${assetName(candidate.id)}${candidate.metadata?.context_language?' · '+candidate.metadata.context_language:''}${candidate.error_code?' · '+candidate.error_code:''}`,actions);
      }
    }
  }
  async function replacementUpload(ep,role) {
    if(!state.drama)return;const dramaId=state.drama.id;const input=document.createElement('input');input.type='file';input.accept=role==='video'?'.mp4':'.srt';input.onchange=async()=>{const file=input.files?.[0];if(!file)return;
      try {
        let language=null;if(role==='subtitle'){language=window.prompt('请选择字幕文件中的实际语言：输入 en 或 zh_CN',ep.subtitle_language||'en');if(language===null)return;if(!['en','zh_CN'].includes(language))throw new Error('字幕语言需为 en 或 zh_CN。');}
        state.uploading=true;const kind=role==='video'?'video':'subtitle',candidate=await api('/uploads','POST',{kind,filename:file.name,bytes:file.size,context_drama_id:dramaId,context_episode_id:ep.id,context_role:'episode_replacement_'+role,...(language?{context_language:language}:{})});
        for(let pos=0;pos<file.size;pos+=1048576)await api('/uploads/'+candidate.id+'/chunks','POST',file.slice(pos,pos+1048576),{'Content-Type':'application/octet-stream','Upload-Offset':String(pos)});
        await api('/uploads/'+candidate.id+'/finish','POST',{});state.uploading=false;await reload(dramaId);message(`候选${role==='video'?'视频':'字幕'}已上传并进入处理。当前分集仍使用原素材；处理就绪后再次选择“确认替换”。`);
      }catch(err){message(err.message,true);}finally{state.uploading=false;}
    };input.click();
  }
  function dramaInput(rights=false){
    const d=values('drama-form'),r=rights?values('rights-form'):{regions:(state.drama?.regions||[]).join(','),rights_note:state.drama?.rights_note||'',license_from:local(state.drama?.license_from),license_until:local(state.drama?.license_until)};
    return {...d,...r,total_episodes:integer(d.total_episodes),cover_asset_id:d.cover_asset_id||null,regions:r.regions.split(',').map(x=>x.trim().toUpperCase()).filter(Boolean),license_from:utc(r.license_from),license_until:utc(r.license_until),category_ids:[...form('drama-form').elements.category_ids.selectedOptions].map(x=>x.value),sort_order:Number(d.sort_order),...(state.drama?{version:state.drama.version}:{})};
  }
  function lockDetailTabs(){for(const b of document.querySelectorAll('#content-detail-tabs [data-admin-tab]'))b.disabled=!state.drama&&b.dataset.adminTab!=='basics';}
  function syncPlanFields(){const f=form('plan-form'),updating=f.elements.mode.value==='updating',now=f.elements.timing.value==='now';f.querySelectorAll('[data-updating]').forEach(e=>e.hidden=!updating);$('launch-at-field').hidden=now;f.elements.launch_at.required=!now;$('next-at-field').hidden=!now||!updating;f.elements.start_number.readOnly=now;f.dataset.previewOnly=now?'true':'false';if(now&&state.drama){const published=new Set(state.drama.episodes.filter(e=>e.published_at).map(e=>e.number));let n=1;while(published.has(n))n++;f.elements.start_number.value=n;}}
  $('more-assets').addEventListener('click',run(async()=>{if(!state.data.asset_cursor)return;const page=await api('/assets?before='+encodeURIComponent(state.data.asset_cursor));const merged=new Map(state.data.assets.map(a=>[a.id,a]));for(const a of page.assets)merged.set(a.id,a);state.data.assets=[...merged.values()];state.data.asset_cursor=page.next_cursor;renderAssetOptions(state.data);renderAssets(state.data);message('已读取更早的素材。');}));
  $('reload').addEventListener('click',run(async()=>{await reload();message('已刷新处理、公开和计划状态。');}));$('new-drama').addEventListener('click',run(async()=>newDrama()));
  form('drama-form').addEventListener('submit',run(async()=>{if(state.selectionLoading)throw new Error('剧目正在读取，请稍后保存。');const d=await api('/dramas'+(state.drama?'/'+state.drama.id:''),state.drama?'PATCH':'POST',dramaInput());await reload(d.id);message('剧目已保存。保存和上传不会自动公开。');}));
  $('new-episode').addEventListener('click',run(async()=>resetEpisodeForm()));
  root.addEventListener('h5-admin:tab-change',event=>{if(event.detail.group==='content-tabs'){state.view=event.detail.id;}else if(event.detail.group==='content-detail-tabs'){state.detail=event.detail.id;if(state.detail==='history')$('publication-history').closest('details').open=true;}if(!restoringUrl)writeTabUrl();});
  window.addEventListener('popstate',async()=>{
    const url=new URL(location.href),view=allowedViews.includes(url.searchParams.get('content_view'))?url.searchParams.get('content_view'):'dramas',detail=allowedDetails.includes(url.searchParams.get('content_detail'))?url.searchParams.get('content_detail'):'basics';
    if(dirty()&&!await guardUnsaved()){writeTabUrl(true);return;}
    restoringUrl=true;
    try{const id=url.searchParams.get('content_drama');if(id&&id!==state.drama?.id)await selectDrama(id,detail,false);setView(view);setDetail(detail);}catch(err){message(err.message,true);}finally{restoringUrl=false;writeTabUrl(true);}
  });
  form('rights-form').addEventListener('submit',run(async()=>{await api('/dramas/'+state.drama.id,'PATCH',dramaInput(true));await reload();message('授权登记已保存，不代表真实版权已经核验。');}));
  form('episode-form').addEventListener('submit',run(async()=>{
    if(state.selectionLoading||!state.drama)throw new Error('请先选择并读取一部剧目。');
    const dramaId=state.drama.id,selection=state.selection,editing=state.episodeEdit,d=values('episode-form');
    if(editing&&editing.dramaId!==dramaId)throw new Error('编辑对象已变化，请重新选择分集。');
    d.number=Number(d.number);d.video_asset_id=d.video_asset_id??editing?.video_asset_id??null;d.subtitle_asset_id=d.subtitle_asset_id??editing?.subtitle_asset_id??null;d.video_asset_id||=null;d.subtitle_asset_id||=null;if(!d.subtitle_asset_id)d.subtitle_language=null;
    if(editing)d.version=editing.version;
    const button=$('save-episode');button.disabled=true;
    try {
      await api('/dramas/'+dramaId+'/episodes'+(editing?'/'+editing.id:''),editing?'PATCH':'POST',d);
      if(selection===state.selection&&state.drama?.id===dramaId)await reload(dramaId);
      message(editing?'原分集的修改已保存。':'新分集已创建。');
    }finally{button.disabled=false;}
  }));
  form('free-form').addEventListener('submit',run(async()=>{await api('/dramas/'+state.drama.id+'/free','PATCH',{version:state.drama.version,free_count:Number(values('free-form').free_count)});await reload();message('已保存单剧覆盖，含手动同值。');}));
  form('plan-form').addEventListener('input',()=>{syncPlanFields();state.planPreview=state.launchPreview=null;$('confirm-publication').disabled=true;});
  form('plan-form').addEventListener('submit',run(async()=>{
    const d=values('plan-form');
    if(d.timing==='now'){
      state.launchPreview=await api('/dramas/'+state.drama.id+'/launch-preview','POST',{mode:d.mode,interval_days:Number(d.interval_days),batch_size:Number(d.batch_size),next_at:utc(d.next_at)});state.planPreview=null;
      const p=state.launchPreview;const next=p.through+1;
      $('plan-preview').textContent=`现在：第 ${p.from}—${p.through} 集（${p.count} 集）\n${p.next_at?`下一批：${next}—${Math.min(state.drama.total_episodes,next+p.batch_size-1)} 集，${beijing(p.next_at)}；之后每 ${p.interval_days} 天，每批 ${p.batch_size} 集。`:'完成后无后续排期。'}\n${p.blocked_reason?'阻断：'+p.blocked_reason:'当前检查通过，确认时再次核验。'}`;
      $('confirm-publication').disabled=Boolean(p.blocked_reason);
    }else{
      await api('/dramas/'+state.drama.id+'/plan','PUT',{...d,version:state.drama.plan.version,start_number:Number(d.start_number),interval_days:Number(d.interval_days),batch_size:Number(d.batch_size),launch_at:utc(d.launch_at)});
      await reload();state.planPreview=await api('/dramas/'+state.drama.id+'/publication-preview','POST',{});$('plan-preview').textContent=previewText(state.planPreview);$('confirm-publication').disabled=Boolean(state.planPreview.blocked_reason);
    }
    message('请核对显示的范围和时间，然后确认发布安排。');
  }));
  function previewText(d){return `本次：第 ${d.from}—${d.through} 集（${d.count} 集）\n${d.mode==='completed'?'全集一次上线':'分批更新'} · ${d.manual?'确认后立即发布':beijing(d.launch_at)}\n${d.mode==='updating'&&!d.manual?`之后每 ${state.drama.plan.interval_days} 天、每批 ${state.drama.plan.batch_size} 集。\n`:''}${d.blocked_reason?'阻断：'+d.blocked_reason:'当前检查通过；执行时再次核验素材和授权。'}`;}
  $('confirm-publication').dataset.confirmFor='plan-form';
  $('confirm-publication').addEventListener('click',run(async()=>{
    if(dirtyForms().some(f=>f.id!=='plan-form'))throw new Error('请先保存其他表单。');
    if(state.launchPreview){const p=state.launchPreview;const out=await api('/dramas/'+state.drama.id+'/launch-now','POST',{...p,next_at:p.next_at?new Date(p.next_at).toISOString().replace('.000Z','+00:00'):null});rememberForm(form('plan-form'));await reload();message(`已公开 ${out.published_count}/${state.drama.total_episodes} 集；下一批 ${nextRange(out,p.batch_size,state.drama.total_episodes)}；时间 ${out.next_at?beijing(out.next_at):'无'}。`);}
    else if(state.planPreview){const p=state.planPreview;await api('/dramas/'+state.drama.id+'/activate','POST',{version:p.plan_version,confirmation:p.confirmation});await reload();message('指定时间安排已启用；尚未到时不会提前公开。');}
    else throw new Error('安排已变化，请重新预览。');
  }));
  $('next-batch-preview').addEventListener('click',run(async()=>{state.nextBatchPreview=await api('/dramas/'+state.drama.id+'/next-batch-preview','POST',{});const p=state.nextBatchPreview;$('next-batch-preview-text').textContent=`提前发布第 ${p.from}—${p.through} 集\n后续下一批保留原确认时间：${beijing(p.next_at)}\n${p.blocked_reason?'阻断：'+p.blocked_reason:'整批已准备好。确认后计划版本会更新，定时执行只处理新的游标。'}`;$('next-batch-confirm').disabled=Boolean(p.blocked_reason);}));
  $('next-batch-confirm').addEventListener('click',run(async()=>{const p=state.nextBatchPreview;if(!p)throw new Error('请先查看提前发布范围。');const out=await api('/dramas/'+state.drama.id+'/publish-next-batch','POST',{plan_version:p.plan_version,confirmation:p.confirmation});const batch=state.drama.plan.batch_size,total=state.drama.total_episodes;await reload();message(`已提前发布第 ${out.published.join(',')} 集，共公开 ${out.published_count} 集；下一批 ${nextRange(out,batch,total)}，时间 ${out.next_at?beijing(out.next_at):'无'}。`);}));
  $('pause-plan').addEventListener('click',run(async()=>{await api('/dramas/'+state.drama.id+'/pause','POST',{version:state.drama.plan.version});await reload();message('后续更新已暂停；已公开内容保留。');}));
  form('resume-form').addEventListener('submit',run(async()=>{await api('/dramas/'+state.drama.id+'/resume','POST',{version:state.drama.plan.version,next_at:utc(values('resume-form').next_at)});await reload();message('已按确认的新时间恢复，不累计补发。');}));
  form('manual-form').addEventListener('submit',run(async()=>{const through=Number(values('manual-form').through);state.manualPreview=await api('/dramas/'+state.drama.id+'/publication-preview','POST',{through});state.manualPreview.requestThrough=through;$('manual-preview').textContent=previewText(state.manualPreview);$('manual-confirm').disabled=Boolean(state.manualPreview.blocked_reason);}));
  $('manual-confirm').addEventListener('click',run(async()=>{const p=state.manualPreview;await api('/dramas/'+state.drama.id+'/publish','POST',{version:p.drama_version,through:p.requestThrough,confirmation:p.confirmation});rememberForm(form('manual-form'));await reload();message('本次连续分集已实际公开。');}));
  $('withdraw').addEventListener('click',run(async()=>{if(!window.confirm('确认整剧下架，并停用旧发布计划？'))return;await api('/dramas/'+state.drama.id+'/withdraw','POST',{version:state.drama.version});await reload();message('已下架；旧计划和旧播放会话不能重新放行。');}));
  form('relist-form').addEventListener('submit',run(async()=>{const d=values('relist-form');await api('/dramas/'+state.drama.id+'/relist','POST',{version:state.drama.version,choice:d.choice,next_at:utc(d.next_at)});await reload();message('已按明确选择重新上架。请核对计划状态。');}));
  for(const kind of ['category','banner']) {
    $('new-'+kind).addEventListener('click',run(async()=>{state[kind]=null;form(kind+'-form').reset();rememberForm(form(kind+'-form'));}));
    form(kind+'-form').addEventListener('submit',run(async()=>{const d=values(kind+'-form');d.sort_order=Number(d.sort_order);const current=state[kind];if(current)d.version=current.version;await api('/'+(kind==='category'?'categories':'banners')+(current?'/'+current.id:''),current?'PATCH':'POST',d);state[kind]=null;form(kind+'-form').reset();await reload();message('已保存，公开首页将按当前状态和授权过滤。');}));
  }
  if($('default-form')) {
    form('default-form').addEventListener('submit',run(async()=>{const d=values('default-form');state.defaultPreview=await api('/default-preview','POST',{...d,free_count:Number(d.free_count)});const p=state.defaultPreview;$('default-preview').textContent=`新默认 N=${p.free_count} · ${p.scope==='sync'?'同步未覆盖剧':'仅新剧'}\n影响已有剧 ${p.count} 部\n`+p.dramas.map(d=>`${title(d)}：${d.free_count} → ${p.free_count}`).join('\n');$('default-confirm').disabled=false;}));
    $('default-confirm').addEventListener('click',run(async()=>{const p=state.defaultPreview;await api('/default','PATCH',{version:p.version,free_count:p.free_count,scope:p.scope,confirmation:p.confirmation});rememberForm(form('default-form'));state.defaultPreview=null;$('default-confirm').disabled=true;await reload();message('全局默认已更新；手动覆盖剧保留。');}));
  }
  for(const [id,button] of [['manual-form','manual-confirm'],['default-form','default-confirm']])if(form(id)){form(id).dataset.previewOnly='true';$(button).dataset.confirmFor=id;form(id).addEventListener('input',()=>{$(button).disabled=true;if(id==='manual-form')state.manualPreview=null;else state.defaultPreview=null;});}
  trackedFormIds.forEach(id=>rememberForm(form(id)));
  window.H5Admin?.protect(root,{isDirty:dirty,save:saveDirtyForm,discard:async()=>!state.uploading&&restoreDirtyForms()});
  form('upload-form').addEventListener('submit',run(async()=>{
    const f=form('upload-form'),kind=f.elements.kind.value,language=f.elements.language.value,attach=f.elements.attach.checked,dramaId=state.drama.id;
    const files=[...f.elements.files.files].sort((a,b)=>a.name.localeCompare(b.name,undefined,{numeric:true}));
    if(attach&&['video','subtitle'].includes(kind)&&files.some(x=>!/^\d+\.(mp4|srt)$/i.test(x.name)))throw new Error('自动登记要求数字文件名，例如 1.mp4 / 1.srt。也可取消自动登记后手动绑定。');
    if(attach&&['video','subtitle'].includes(kind)){const latest=(await api('/dramas/'+dramaId)).drama;for(const file of files){const ep=latest.episodes.find(e=>e.number===Number(file.name.split('.')[0]));if(ep&&(ep[kind+'_asset_id']||ep.published_at))throw new Error(`第 ${ep.number} 集已有素材或已经公开，请在该集上传候选并确认替换。此次批量上传尚未开始。`);}}
    const button=f.querySelector('button');button.disabled=true;
    state.uploading=true;try {for(let i=0;i<files.length;i++) {
      const file=files[i],asset=await api('/uploads','POST',{kind,filename:file.name,bytes:file.size});
      for(let pos=0;pos<file.size;pos+=1048576){await api('/uploads/'+asset.id+'/chunks','POST',file.slice(pos,pos+1048576),{'Content-Type':'application/octet-stream','Upload-Offset':String(pos)});$('upload-progress').value=100*(i+Math.min(pos+1048576,file.size)/file.size)/files.length;message(`正在上传 ${i+1}/${files.length}：${file.name}`);}
      await api('/uploads/'+asset.id+'/finish','POST',{});
      if(attach&&['video','subtitle'].includes(kind)) {
        const d=(await api('/dramas/'+dramaId)).drama,num=Number(file.name.split('.')[0]),ep=d.episodes.find(x=>x.number===num);
        const payload={number:num,title_en:ep?.title_en||'',title_zh:ep?.title_zh||'',video_asset_id:ep?.video_asset_id||null,subtitle_asset_id:ep?.subtitle_asset_id||null,subtitle_language:ep?.subtitle_language||null,...(ep?{version:ep.version}:{})};
        if(kind==='video')payload.video_asset_id=asset.id;else {payload.subtitle_asset_id=asset.id;payload.subtitle_language=language;}
        await api('/dramas/'+dramaId+'/episodes'+(ep?'/'+ep.id:''),ep?'PATCH':'POST',payload);
      }
    }await reload(dramaId);message(`已完成 ${files.length} 个文件上传；等待媒体处理，未自动发布。`);}finally{state.uploading=false;button.disabled=false;}
  }));
  for(const kind of ['cover','banner'])form(kind+'-upload-form').addEventListener('submit',run(async()=>{
    const f=form(kind+'-upload-form'),file=f.elements.file.files[0];if(!file)return;state.uploading=true;const button=f.querySelector('button');button.disabled=true;
    try{const a=await api('/uploads','POST',{kind,filename:file.name,bytes:file.size});for(let pos=0;pos<file.size;pos+=1048576)await api('/uploads/'+a.id+'/chunks','POST',file.slice(pos,pos+1048576),{'Content-Type':'application/octet-stream','Upload-Offset':String(pos)});const saved=await api('/uploads/'+a.id+'/finish','POST',{});state.data.assets.push(saved);renderAssetOptions(state.data);$(kind+'-upload-status').textContent=`${file.name} 已上传，等待处理。刷新状态后，选择就绪候选并保存${kind==='cover'?'基本资料':'Banner'}。`;f.reset();message('上传已记录；当前图片绑定未切换。');}finally{state.uploading=false;button.disabled=false;}
  }));
  restoringUrl=true;setView(state.view);setDetail(state.detail);restoringUrl=false;
  run(async()=>{const wantedView=state.view,wantedDetail=state.detail;await reload(initialUrl.searchParams.get('content_drama'));if(wantedView!=='dramas')setView(wantedView);if(state.drama)setDetail(wantedDetail);writeTabUrl(true);message('已读取真实内容。保存和上传不会自动公开。');})();
})();
