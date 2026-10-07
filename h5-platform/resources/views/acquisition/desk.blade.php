<x-filament-panels::page>
    <style>
        .acq-card{border:1px solid #cbd5e1;border-radius:12px;padding:20px;margin-bottom:18px;background:#fff;color:#172033}.acq-card h2{font-size:19px;font-weight:650;margin-bottom:12px}.acq-card p{margin-bottom:10px;line-height:1.6}.acq-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}.acq-grid label{display:flex;flex-direction:column;gap:6px;font-size:14px}.acq-grid input,.acq-grid select,.acq-url{border:1px solid #94a3b8;border-radius:6px;padding:8px;background:white;color:#172033;max-width:100%}.acq-card button{padding:8px 12px;border:1px solid #64748b;border-radius:6px;margin:8px 6px 0 0}.acq-card button[type=submit]{background:#164e63;color:#fff}.acq-note{color:#475569;font-size:14px}.acq-warning{background:#fffbeb;border-color:#f59e0b}.acq-table{overflow:auto}.acq-table table{width:100%;font-size:14px;text-align:left}.acq-table td,.acq-table th{padding:10px;border-bottom:1px solid #e2e8f0;vertical-align:top}.acq-url{width:100%;min-width:230px;font-size:12px}.acq-badge{display:inline-block;padding:2px 7px;border-radius:4px;background:#e2e8f0;font-size:12px}
    </style>
    <section class="acq-card acq-warning">
        <h2>本站投流工作台 · 本地开发</h2>
        <p>Meta / TikTok 均待账号持有人联调。外部发送：<strong>{{ config('acquisition.transport') === 'disabled' ? '关闭' : config('acquisition.transport') }}</strong>。本地模拟、接口接受与平台确认分别记录。</p>
        <p class="acq-note">D05 来源窗口、旧链接与落地隐私规则待总控接收。当前链接入口明确显示未就绪，不会假造来源或影响订阅。</p>
    </section>
    <section class="acq-card">
        <h2>{{ $pixelId ? '编辑像素' : '登记已有像素' }}</h2>
        <form wire:submit="savePixel">
            <div class="acq-grid">
                <label>名称<input wire:model="pixel.name" maxlength="100" required></label>
                <label>平台<select wire:model="pixel.platform"><option value="meta">Meta</option><option value="tiktok">TikTok</option></select></label>
                <label>平台像素编号<input wire:model="pixel.external_id" maxlength="64" required></label>
                <label>资产用途<select wire:model="pixel.environment"><option value="test">专用测试资产</option><option value="production">正式资产（默认不发送）</option></select></label>
                <label>启用状态<select wire:model="pixel.active"><option value="1">启用</option><option value="0">停用</option></select></label>
            </div>
            <p class="acq-note">仅登记平台已有资产；不会在平台创建像素。没有任意脚本或令牌输入框。版本 {{ $pixel['version'] }}。</p>
            @foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
            <button type="submit">保存像素</button><button type="button" wire:click="newPixel">登记另一个</button>
        </form>
        <div class="acq-table"><table><thead><tr><th>像素资产</th><th>状态</th><th>验证与操作</th></tr></thead><tbody>
            @forelse($pixels as $asset)
                <tr wire:key="pixel-{{ $asset['id'] }}"><td><strong>{{ $asset['name'] }}</strong><br>{{ ucfirst($asset['platform']) }} · {{ $asset['external_id'] }}<br><span class="acq-badge">{{ $asset['environment'] === 'test' ? '测试' : '正式' }}</span> · 版本 {{ $asset['version'] }}</td>
                    <td>{{ $asset['active'] ? '启用' : '停用' }}<br>可见关联链接 {{ $asset['affected_links'] }} 个<br>共享人员 {{ count($asset['assigned_staff_ids']) }} 名</td>
                    <td>本地：{{ $asset['local_status'] === 'simulated' ? '模拟通过' : '未测试' }}<br>平台：待联调<br><button wire:click="selectPixel('{{ $asset['id'] }}')">编辑 / 启停</button>@if($asset['environment'] === 'test')<button wire:click="simulatePixel('{{ $asset['id'] }}')">本地模拟校验</button>@endif @if($owner)<button wire:click="selectAssignment('pixel', '{{ $asset['id'] }}')">分配像素</button>@endif</td></tr>
            @empty<tr><td colspan="3">尚无可见像素，请登记你拥有或获授权的测试资产。</td></tr>@endforelse
        </tbody></table></div>
        <p class="acq-note">共 {{ $pixelPagination['total'] }} 项 · 第 {{ $pixelPagination['page'] }} / {{ $pixelPagination['last_page'] }} 页</p>
        @if($pixelPagination['page'] > 1)<button wire:click="$set('pixelPage', {{ $pixelPagination['page'] - 1 }})">上一页像素</button>@endif
        @if($pixelPagination['page'] < $pixelPagination['last_page'])<button wire:click="$set('pixelPage', {{ $pixelPagination['page'] + 1 }})">下一页像素</button>@endif
    </section>
    <section class="acq-card">
        <h2>{{ $linkId ? '编辑推广链接' : '生成推广链接' }}</h2>
        <p>{{ $catalogMessage }}</p><button wire:click="loadCatalog">{{ $catalogCursor ? '读取下一页剧目' : '读取可投放剧目' }}</button>
        <form wire:submit="saveLink">
            <div class="acq-grid">
                <label>推广名称<input wire:model="link.name" maxlength="100" required></label>
                <label>平台<select wire:model="link.platform"><option value="meta">Meta</option><option value="tiktok">TikTok</option></select></label>
                <label>已发布剧目<select wire:model="link.drama_id" required><option value="">请选择</option>@foreach($catalog as $drama)<option value="{{ $drama['id'] }}">{{ $drama['title'] }}</option>@endforeach</select></label>
                <label>授权像素<select wire:model="link.pixel_id" required><option value="">请选择</option>@foreach($pixelOptions as $asset)<option value="{{ $asset['id'] }}">{{ $asset['name'] }} · {{ $asset['platform'] }}{{ $asset['active'] ? '' : '（停用）' }}</option>@endforeach</select></label>
                <label>启用状态<select wire:model="link.active"><option value="1">启用</option><option value="0">停用</option></select></label>
            </div>
            <p class="acq-note">版本 {{ $link['version'] }}；当前可改名称与启停。落地目标 / 像素变更和旧链接规则待 D05。找不到像素时可在上方像素列表翻页，已选项会保留。</p>
            <button type="submit">保存并生成链接</button><button type="button" wire:click="newLink">新建另一条</button>
        </form>
        <div class="acq-table"><table><thead><tr><th>推广链接</th><th>复制与状态</th><th>管理</th></tr></thead><tbody>
        @forelse($links as $entry)
            <tr wire:key="link-{{ $entry['id'] }}"><td><strong>{{ $entry['name'] }}</strong><br>{{ $entry['drama_title'] }}<br>{{ ucfirst($entry['platform']) }} · 版本 {{ $entry['version'] }}</td>
                <td><input class="acq-url" readonly value="{{ $entry['url'] }}" aria-label="推广链接" onclick="this.select()"><button type="button" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText(@js($entry['url'])).then(() => copied = true)" x-text="copied ? '已复制' : '复制链接'">复制链接</button><br>{{ $entry['active'] ? '启用配置' : '停用' }} · 落地待 D05</td>
                <td><button wire:click="selectLink('{{ $entry['id'] }}')">编辑 / 停用</button><button wire:click="showSummary('{{ $entry['id'] }}')">本站汇总</button><button wire:click="retry('{{ $entry['id'] }}')">重试待处理发送</button>@if($owner)<button wire:click="selectAssignment('link', '{{ $entry['id'] }}')">分配链接</button>@endif</td></tr>
        @empty<tr><td colspan="3">尚无可见推广链接。</td></tr>@endforelse
        </tbody></table></div>
        <p class="acq-note">共 {{ $linkPagination['total'] }} 项 · 第 {{ $linkPagination['page'] }} / {{ $linkPagination['last_page'] }} 页</p>
        @if($linkPagination['page'] > 1)<button wire:click="$set('linkPage', {{ $linkPagination['page'] - 1 }})">上一页链接</button>@endif
        @if($linkPagination['page'] < $linkPagination['last_page'])<button wire:click="$set('linkPage', {{ $linkPagination['page'] + 1 }})">下一页链接</button>@endif
    </section>
    @if($summary)
        <section class="acq-card"><h2>对应链接的本站汇总</h2><p>{{ $summary['definition'] }}</p><p>落地访问：待 D05 和 04 插槽接入；预加载不计作真实观看。</p>
            <div class="acq-table"><table><thead><tr><th>环境</th><th>付款类型</th><th>笔数</th><th>核实付款总额</th></tr></thead><tbody>
            @forelse($summary['payments'] as $row)<tr><td>{{ $row['is_test'] ? '测试' : '真实' }}</td><td>{{ ['first_subscription'=>'首次订阅','resubscription'=>'再次订阅','renewal'=>'续费'][$row['transaction_kind']] }}</td><td>{{ $row['payments'] }}</td><td>USD {{ number_format($row['amount_minor']/100, 2) }}</td></tr>@empty<tr><td colspan="4">暂无对应付款事实。</td></tr>@endforelse
            </tbody></table></div>
            @foreach($summary['deliveries'] as $row)<p>发送 {{ $row['state'] }}：{{ $row['count'] }} 项 @if($row['reason'])（{{ $row['reason'] }}）@endif</p>@endforeach
        </section>
    @endif
    @if($owner)
        <section class="acq-card"><h2>负责人分配</h2>
            <p>点击资产旁的“分配像素”或“分配链接”。资产创建人保留访问权；勾选多人可共享管理。编辑启用链接还需拥有对应像素的权限，请分别分配。</p>
            <p class="acq-note">投流同事只看自己和被分配资产的本站汇总，不提供用户联系方式或逐人订单。</p>
            @if($shareId)
                <p>正在分配：{{ $shareName }} · {{ $shareType === 'pixel' ? '像素' : '链接' }} · 版本 {{ $shareVersion }}</p>
                <p>{{ $staffMessage }}</p><button wire:click="loadStaff">{{ $staffCursor ? '读取下一页人员' : '读取可分配人员' }}</button>
                <form wire:submit="share">
                    @foreach($staffCandidates as $candidate)
                        <label style="display:block;margin:8px 0"><input type="checkbox" wire:model="staffIds" value="{{ $candidate['id'] }}"> {{ $candidate['name'] }}</label>
                    @endforeach
                    <button type="submit">保存共享分配</button>
                </form>
            @else<p>尚未选择要分配的资产。</p>@endif
        </section>
    @endif
</x-filament-panels::page>
