@php($labels = \App\Modules\Acquisition\Analytics\Labels::class)
<div class="h5-card">
    <h3>站内分析</h3>
    <p class="h5-note">仅已同意的观测样本；未知来源/设备单列，不能反推全部用户或平台归因。日期按UTC。投流员工仅查询自己或获分配的链接。</p>
    <form wire:submit="searchAnalyticsLinks" class="h5-form-grid" data-analytics-link-search>
        <label class="h5-field">搜索推广链接名称<input type="search" class="h5-input" wire:model="analyticsLinkSearch" maxlength="100" placeholder="搜索全部有权链接" aria-describedby="analytics-link-search-help"></label>
        <div><button type="submit" class="h5-button" wire:loading.attr="disabled" wire:target="searchAnalyticsLinks">搜索链接</button></div>
    </form>
    @error('analyticsLinkSearch')<p class="h5-error" role="alert">{{ $message }}</p>@enderror
    <p class="h5-note" id="analytics-link-search-help">按名称搜索全部自己或获分配的链接，不受推广链接列表页影响。每页最多50条；仍有权的已选链接会保留。当前搜索：{{ $analyticsLinkQuery === '' ? '全部可见链接' : $analyticsLinkQuery }} · 共{{ $analyticsLinks['total'] }}条 · 第{{ $analyticsLinks['page'] }}/{{ $analyticsLinks['last_page'] }}页</p>
    <div class="h5-toolbar" data-analytics-link-pagination>
        @if($analyticsLinks['page'] > 1)<button type="button" class="h5-button" wire:click="paginateAnalyticsLinks({{ $analyticsLinks['page'] - 1 }})">上一页链接选项</button>@endif
        @if($analyticsLinks['page'] < $analyticsLinks['last_page'])<button type="button" class="h5-button" wire:click="paginateAnalyticsLinks({{ $analyticsLinks['page'] + 1 }})">下一页链接选项</button>@endif
    </div>
    <form wire:submit="refreshAnalytics" class="h5-form-grid">
        <label class="h5-field">入口/付款开始日期<input type="date" class="h5-input" wire:model="analyticsFilters.from"></label>
        <label class="h5-field">结束日期<input type="date" class="h5-input" wire:model="analyticsFilters.until"></label>
        <label class="h5-field">渠道<select class="h5-input" wire:model="analyticsFilters.channel"><option value="">全部</option><option value="meta">Meta</option><option value="tiktok">TikTok</option><option value="unknown">未知/无归属</option></select></label>
        <label class="h5-field">设备<select class="h5-input" wire:model="analyticsFilters.device"><option value="">全部</option>@foreach(['desktop'=>'桌面','mobile'=>'手机','tablet'=>'平板','unknown'=>'未知'] as $v=>$label)<option value="{{ $v }}">{{ $label }}</option>@endforeach</select></label>
        <label class="h5-field">链接<select class="h5-input" wire:model="analyticsFilters.link_id"><option value="">当前授权范围全部</option>@foreach($analyticsLinks['items'] as $item)<option value="{{ $item['id'] }}" @disabled($item['unavailable'])>{{ $item['name'] }}</option>@endforeach</select></label>
        <label class="h5-field">剧目<select class="h5-input" wire:model="analyticsFilters.drama_id"><option value="">全部剧目</option>@foreach($analytics['dimensions']['dramas'] ?? [] as $id=>$title)<option value="{{ $id }}">{{ $title }}</option>@endforeach</select></label>
        <label class="h5-field">分集<select class="h5-input" wire:model="analyticsFilters.episode_id"><option value="">全部分集</option>@foreach($analytics['dimensions']['episodes'] ?? [] as $id=>$title)<option value="{{ $id }}">{{ $title }}</option>@endforeach</select></label>
        <label class="h5-field">环境<select class="h5-input" wire:model="analyticsFilters.test"><option value="all">测试/真实分别核对</option><option value="test">仅测试</option><option value="real">仅真实</option></select></label>
        <div><button type="submit" class="h5-button h5-primary">查询分析</button></div>
    </form>
    @if($analyticsError)<p class="h5-error" role="alert">{{ $analyticsError }}</p>@endif
    @if($analytics)
        <p class="h5-note">{{ $analytics['definition'] }} {{ $analytics['from'] }} — {{ $analytics['until'] }}</p>
        @if($analytics['limited'])<p class="h5-alert">样本超过单次10000条上限，结果不完整，转化率不计算；请缩小日期或筛选。</p>@endif
        <p>访问人数 {{ $analytics['visitors'] ?? '—' }} · 会话 {{ $analytics['sessions'] ?? '—' }} · 有效观看累计 {{ $analytics['watched_seconds'] ?? '—' }} 秒 · 仍在7天观察窗 {{ $analytics['observing_people'] }} 人</p>
        <h3>各环节独立计数</h3>
        <div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>事件</th><th>次数</th><th>去重人数</th></tr></thead><tbody>@forelse($analytics['counts'] ?? [] as $event=>$row)<tr><td>{{ $labels::event($event) }}</td><td>{{ $row['events'] }}</td><td>{{ $row['people'] }}</td></tr>@empty<tr><td colspan="3">暂无已同意样本或采集未启用；不代表全站没有行为。</td></tr>@endforelse</tbody></table></div>
        @foreach(['funnel'=>'主路径顺序漏斗（入口→播放→建单→非续费付款→付款后播放）','registration_funnel'=>'新注册完整路径（其他合法购买不要求经过所有步骤）'] as $key=>$title)
        <h3>{{ $title }}</h3><div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>步骤</th><th>同入口队列人数</th><th>相对上步</th></tr></thead><tbody>@foreach($analytics['segments'] as $environment=>$segment) @foreach($segment[$key] as $row)<tr><td>{{ $environment === 'test' ? '测试' : '真实' }} · {{ $labels::event($row['step']) }}</td><td>{{ $row['people'] }}</td><td>{{ $row['rate'] === null ? '—' : $row['rate'].'%' }}</td></tr>@endforeach @endforeach</tbody></table></div>
        @endforeach
        <h3>按剧集观看卡点</h3>
        <p class="h5-note">播放/完播人数：所选日期开始且同播放实例7天内结束的队列；观看秒、失败、首帧和卡顿：所选日期实际记录。分母0显示—；不能仅凭掉落判断剧情原因。</p>
        <div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>剧目 / 分集</th><th>环境</th><th>播放人数</th><th>完成人数 / 完播率</th><th>累计有效秒</th><th>播放失败</th><th>首帧次数 / 平均毫秒</th><th>卡顿次数 / 累计毫秒</th></tr></thead><tbody>
        @forelse($analytics['episodes'] as $row)<tr><td>{{ $row['drama_title'] }}<br>{{ $row['episode_number'] === null ? '未知集号' : '第'.$row['episode_number'].'集' }} · {{ $row['episode_title'] }}</td><td>{{ $row['is_test'] ? '测试' : '真实' }}</td><td>{{ $row['play_people'] }}</td><td>{{ $row['complete_people'] }} / {{ $row['completion_rate'] === null ? '—' : $row['completion_rate'].'%' }}</td><td>{{ $row['watched_seconds'] }}</td><td>{{ $row['play_errors'] }}</td><td>{{ $row['startup_samples'] }} / {{ $row['startup_avg_ms'] ?? '—' }}</td><td>{{ $row['stall_count'] }} / {{ $row['stall_ms'] }}</td></tr>@empty<tr><td colspan="8">暂无获授权、已同意的分集播放样本。</td></tr>@endforelse
        </tbody></table></div>
        <h3>已核实财务（USD）</h3>
        <p>状态：{{ $labels::state($analytics['finance']['status']) }} <span class="h5-note">所选期间付款减这些付款当前累计确认退款；不是退款期现金流或ROAS。</span> · 毛收 {{ $analytics['finance']['gross_minor'] === null ? '—' : \App\Support\Admin\UsdAmount::format($analytics['finance']['gross_minor']) }} · 已确认累计退款 {{ $analytics['finance']['refund_minor'] === null ? '—' : \App\Support\Admin\UsdAmount::format($analytics['finance']['refund_minor']) }} · 确认净收 {{ $analytics['finance']['net_minor'] === null ? '—' : \App\Support\Admin\UsdAmount::format($analytics['finance']['net_minor']) }}</p>
        @foreach($analytics['finance']['kinds'] as $kind=>$value)<p>{{ $labels::event($kind) }}：{{ $value['payments'] }}笔 / {{ \App\Support\Admin\UsdAmount::format($value['gross_minor']) }}</p>@endforeach
        <p class="h5-note">“未来续费已停止”含已确认的停止结果（含退款/到期/平台处理），不代表用户主动取消率。</p><h3>错误与付款待核状态</h3>@forelse($analytics['errors'] as $name=>$count)<p>{{ $labels::error($name) }}：{{ $count }}</p>@empty<p class="h5-muted">当前授权样本无已记录错误；不等于全站无故障。</p>@endforelse
        <h3>采集与回传诊断</h3><p class="h5-note">诊断按当前授权、链接及环境累计，不按入口日期或设备重算。</p><p>采集：{{ $analytics['diagnostics']['enabled'] ? '已启用' : '未启用' }} · 最近接收：{{ $analytics['diagnostics']['last_received_at'] ?? '未知' }} · 外发：{{ $analytics['diagnostics']['transport'] === 'disabled' ? '外发关闭' : $labels::state($analytics['diagnostics']['transport']) }}</p>
        @if($analytics['diagnostics']['consent_skipped'] !== null)<p class="h5-note">正常未采集请求：{{ $analytics['diagnostics']['consent_skipped'] }}（未同意，仅运行诊断，不计访问）</p>@endif
        @foreach($analytics['diagnostics']['capture_failures'] as $row)<p>{{ $labels::error($row->reason) }}：{{ $row->count }} / {{ $row->last_at }}</p>@endforeach
        @foreach($analytics['diagnostics']['source_status'] as $row)<p>{{ $row->is_test ? '测试' : '真实' }} · {{ $labels::state($row->source_status) }}：{{ $row->count }}</p>@endforeach
        @foreach($analytics['diagnostics']['deliveries'] as $row)<p>{{ $labels::state($row->environment) }} · {{ $labels::state($row->state) }} · {{ $labels::error($row->last_error) }}：{{ $row->count }}</p>@endforeach
        <p class="h5-note">{{ $analytics['diagnostics']['platform_confirmation'] }}</p>
    @endif
</div>
