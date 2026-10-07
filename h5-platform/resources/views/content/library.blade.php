<x-filament-panels::page>
<link rel="stylesheet" href="/content/admin.css">
<div id="content-app" data-owner="{{ $isOwner ? '1' : '0' }}" wire:ignore>
<p class="notice">本地内容工作台。文件上传和版权字段登记不代表已公开或已核实真实版权；付费播放仍依赖账务模块。时间统一为北京时间（UTC+8）。</p>
<p id="content-message" role="status" aria-live="polite">正在读取内容…</p>
<div class="toolbar"><button type="button" id="reload">刷新状态</button><button type="button" id="new-drama">新增剧目</button></div>
<section><h2>片库</h2><div id="drama-list" class="list"></div></section>
<section><h2 id="drama-heading">新剧草稿</h2>
<form id="drama-form" class="grid">
<label>英文剧名<input name="title_en" maxlength="200"></label><label>中文剧名<input name="title_zh" maxlength="200"></label>
<label>英文简介<textarea name="synopsis_en" maxlength="10000"></textarea></label><label>中文简介<textarea name="synopsis_zh" maxlength="10000"></textarea></label>
<label>已确认总集数<input name="total_episodes" type="number" min="1" max="10000" placeholder="尚未确认可留空"></label><label>竖版封面<select name="cover_asset_id" id="cover-assets"><option value="">先上传封面</option></select></label>
<label>授权地区（两位代码，逗号分隔）<input name="regions" placeholder="例如 US"></label><label>授权登记说明<textarea name="rights_note" placeholder="登记来源、使用范围和人工核对进展；保存不会自动标为版权已验证"></textarea></label>
<label>授权开始（北京时间）<input name="license_from" type="datetime-local" step="60"></label><label>授权结束（北京时间，不含该时刻）<input name="license_until" type="datetime-local" step="60"></label>
<label>分类<select name="category_ids" id="drama-categories" multiple></select></label><label>目录排序（小的在前）<input name="sort_order" type="number" min="0" value="0"></label>
<label class="check"><input name="recommended" type="checkbox"> 首页推荐（发布后生效）</label><button type="submit">保存剧目草稿 / 修改</button>
</form></section>
<div id="selected-tools" hidden>
<section><h2>上传和分集</h2><p>支持 MP4（H.264/AAC，最大 2 GiB）、PNG/JPEG（20 MiB）、UTF-8 SRT（2 MiB）。文件只存私有目录。视频和字幕可选择多个连续数字文件名；按数字排序依次上传。处理完成后再预览、发布。</p>
<form id="upload-form" class="grid"><label>素材用途<select name="kind"><option value="video">视频</option><option value="subtitle">字幕</option><option value="cover">竖版封面</option><option value="banner">横版 Banner</option></select></label><label>文件<input name="files" type="file" multiple required></label><label>字幕实际语言<select name="language"><option value="en">英文</option><option value="zh_CN">简体中文</option></select></label><label class="check"><input name="attach" type="checkbox" checked> 按数字文件名登记分集（视频 / 字幕）</label><button type="submit">上传并保存私有素材</button><progress id="upload-progress" max="100" value="0"></progress></form>
<details><summary>手动登记 / 编辑一集</summary><form id="episode-form" class="grid"><label>分集序号<input name="number" type="number" min="1" required></label><label>英文标题<input name="title_en"></label><label>中文标题<input name="title_zh"></label><label>视频素材<select name="video_asset_id" id="video-assets"></select></label><label>字幕素材<select name="subtitle_asset_id" id="subtitle-assets"></select></label><label>字幕语言<select name="subtitle_language"><option value="en">英文</option><option value="zh_CN">简体中文</option></select></label><button type="submit">保存此集</button></form></details>
<div id="episode-list" class="list"></div></section>
<section><h2>单剧免费集数</h2><p id="free-status"></p><form id="free-form" class="inline"><label>前 N 集免费<input name="free_count" type="number" min="0" max="10000" required></label><button type="submit">保存为单剧覆盖</button></form><p>手动保存相同值也算覆盖。仅调整 N 不打断已开始当集；新开、刷新、切集重新判断。</p></section>
<section><h2>发布计划</h2><p id="plan-status"></p><form id="plan-form" class="grid"><label>模式<select name="mode"><option value="completed">已完结：上线时一次发布准备齐的全集</option><option value="updating">更新中：按周期分批</option></select></label><label>从下一未发布集开始<input name="start_number" type="number" min="1" required></label><label>频率（仅更新中生效）<select name="interval_days"><option value="1">每天</option><option value="2">每两天</option><option value="7">每周</option></select></label><label>每次集数（仅更新中生效）<select name="batch_size">@foreach([1,2,5,10,20] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach</select></label><label>上线时间（北京时间，保存后固定）<input name="launch_at" type="datetime-local" step="60" required></label><button type="submit">保存计划（需要重新预览启用）</button></form>
<div class="toolbar"><button type="button" id="preview-plan">预览计划并显示首批</button><button type="button" id="activate-plan" disabled>确认启用此计划</button><button type="button" id="pause-plan">暂停更新</button></div><pre id="plan-preview"></pre>
<form id="resume-form" class="inline"><label>确认下一次北京时间<input name="next_at" type="datetime-local" step="60" required></label><button type="submit">恢复更新</button></form><p>暂停保留已公开内容，恢复不累计补发。漏排期的服务器故障规则 D03 尚待总控确认，系统不会擅自补发。</p>
</section>
<section><h2>手动发布 / 下架</h2><form id="manual-form" class="inline"><label>连续发布至第几集<input name="through" type="number" min="1" required></label><button type="submit">预览本次范围</button><button type="button" id="manual-confirm" disabled>确认立即发布</button></form><pre id="manual-preview"></pre>
<div class="toolbar"><button type="button" id="withdraw" class="danger">整剧下架并停用旧计划</button></div>
<form id="relist-form" class="inline"><label>重新上架时的计划<select name="choice"><option value="reset">重设：恢复已公开内容，旧排期停用</option><option value="restore">恢复：确认下一次新时间</option></select></label><label>恢复计划的下一次北京时间<input name="next_at" type="datetime-local" step="60"></label><button type="submit">确认重新上架</button></form>
<details><summary>最近发布记录</summary><pre id="publication-history"></pre></details></section>
</div>
<section><h2>素材处理状态</h2><p>待处理任务由本工作区定时进程执行。失败素材不会发布；修改源文件后重新上传，临时处理故障可重试。</p><div id="asset-list" class="list"></div></section>
<section><h2>分类</h2><div id="category-list" class="list"></div><form id="category-form" class="grid"><label>英文名<input name="name_en"></label><label>中文名<input name="name_zh"></label><label>排序<input name="sort_order" type="number" min="0" value="0"></label><label class="check"><input name="active" type="checkbox" checked>启用</label><button type="submit">保存分类</button><button type="button" id="new-category">清空并新增</button></form></section>
<section><h2>站内 Banner</h2><p>目标只支持本站剧目。草稿、下架或授权到期的目标自动从首页隐藏。封面用于竖卡，Banner 使用横图。</p><div id="banner-list" class="list"></div><form id="banner-form" class="grid"><label>英文文案<input name="title_en"></label><label>中文文案<input name="title_zh"></label><label>本站目标剧目<select name="drama_id" id="banner-dramas" required></select></label><label>横版图片<select name="image_asset_id" id="banner-assets" required></select></label><label>排序<input name="sort_order" type="number" min="0" value="0"></label><label class="check"><input name="active" type="checkbox" checked>启用</label><button type="submit">保存 Banner</button><button type="button" id="new-banner">清空并新增</button></form></section>
@if($isOwner)
<section><h2>负责人：全局默认免费集数</h2><form id="default-form" class="inline"><label>新默认 N<input name="free_count" type="number" min="0" required></label><label>影响范围<select name="scope"><option value="new_only">仅之后新建的剧</option><option value="sync">同步全部未手动覆盖的剧</option></select></label><button type="submit">查看影响清单</button><button type="button" id="default-confirm" disabled>确认提交已展示范围</button></form><pre id="default-preview"></pre></section>
@endif
</div>
<script src="/content/admin.js" defer></script>
</x-filament-panels::page>
