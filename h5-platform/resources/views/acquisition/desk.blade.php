<x-filament-panels::page>
    <x-admin.workspace title="本站投流工作台" description="管理 Meta / TikTok 推广链接、像素、本站付款汇总和发送状态。外部平台联调仍待账号持有人。">
        <section class="h5-alert">
            <strong>外部发送：{{ config('acquisition.transport') === 'disabled' ? '关闭' : config('acquisition.transport') }}</strong>
            <p>D05 来源窗口、旧链接与落地隐私规则待总控接收；当前落地和访问统计显示未就绪，无来源不会阻塞订阅。本地模拟、接口接受与平台确认分别记录。</p>
        </section>

        <x-admin.tabs id="acquisition-tabs" :items="$tabs" :active="$tab" method="selectTab" label="投流管理功能分栏" />

        <x-admin.panel group="acquisition-tabs" name="links" :active="$tab">
            <section class="h5-card">
                <h2>{{ $linkId ? '编辑推广链接' : '生成推广链接' }}</h2>
                <p>{{ $catalogMessage }}</p><button class="h5-button" wire:click="loadCatalog">{{ $catalogCursor ? '读取下一页剧目' : '读取可投放剧目' }}</button>
                <form data-editor="link" data-save="saveLink">
                    <div class="h5-form-grid">
                        <label class="h5-field">推广名称<input class="h5-input" wire:model="link.name" maxlength="100" required></label>
                        <label class="h5-field">平台<select class="h5-input" wire:model="link.platform"><option value="meta">Meta</option><option value="tiktok">TikTok</option></select></label>
                        <label class="h5-field">已发布剧目<select class="h5-input" wire:model="link.drama_id" required><option value="">请选择</option>@foreach($catalog as $drama)<option value="{{ $drama['id'] }}">{{ $drama['title'] }}</option>@endforeach</select></label>
                        <label class="h5-field">授权像素<select class="h5-input" wire:model="link.pixel_id" required><option value="">请选择</option>@foreach($pixelOptions as $asset)<option value="{{ $asset['id'] }}">{{ $asset['name'] }} · {{ $asset['platform'] }}{{ $asset['active'] ? '' : '（停用）' }}</option>@endforeach</select></label>
                        <label class="h5-field">启用状态<select class="h5-input" wire:model="link.active"><option value="1">启用</option><option value="0">停用</option></select></label>
                    </div>
                    <p class="h5-muted">{{ $catalogMessage }}。版本 {{ $link['version'] }}；落地目标、来源和像素变更规则待 D05。已选像素无权使用时仅可停用已有链接。</p>
                    @foreach($errors->all() as $error)<p class="h5-error" role="alert">{{ $error }}</p>@endforeach
                    <button class="h5-button h5-primary" type="submit">保存并生成链接</button><button class="h5-button" type="button" data-editor-action="newLink">新建另一条</button>
                </form>
            </section>
            <section class="h5-card"><h2>推广链接</h2>
                <div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>链接</th><th>复制与状态</th><th>管理</th></tr></thead><tbody>
                @forelse($links as $entry)
                    <tr wire:key="link-{{ $entry['id'] }}"><td><strong>{{ $entry['name'] }}</strong><br>{{ $entry['drama_title'] }}<br>{{ ucfirst($entry['platform']) }} · 版本 {{ $entry['version'] }}</td>
                        <td><input class="h5-input" readonly value="{{ $entry['url'] }}" aria-label="推广链接" onclick="this.select()"><button type="button" class="h5-button" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText(@js($entry['url'])).then(() => copied = true)" x-text="copied ? '已复制' : '复制链接'">复制链接</button><br>{{ $entry['active'] ? '启用配置' : '停用' }} · 落地待 D05</td>
                        <td><button class="h5-button" data-editor-action="selectLink" data-editor-id="{{ $entry['id'] }}">编辑 / 停用</button><button class="h5-button" data-editor-action="showSummary" data-editor-id="{{ $entry['id'] }}">查看本站汇总</button><button class="h5-button" wire:click="retry('{{ $entry['id'] }}')">重试待处理发送</button>@if($owner)<button class="h5-button" data-editor-action="selectAssignment" data-editor-type="link" data-editor-id="{{ $entry['id'] }}">分配链接</button>@endif</td></tr>
                @empty<tr><td colspan="3">尚无可见推广链接。</td></tr>@endforelse
                </tbody></table></div>
                <p class="h5-muted">共 {{ $linkPagination['total'] }} 项 · 第 {{ $linkPagination['page'] }} / {{ $linkPagination['last_page'] }} 页</p>
                @if($linkPagination['page'] > 1)<button class="h5-button" wire:click="$set('linkPage', {{ $linkPagination['page'] - 1 }})">上一页链接</button>@endif
                @if($linkPagination['page'] < $linkPagination['last_page'])<button class="h5-button" wire:click="$set('linkPage', {{ $linkPagination['page'] + 1 }})">下一页链接</button>@endif
            </section>
        </x-admin.panel>

        <x-admin.panel group="acquisition-tabs" name="pixels" :active="$tab">
            <section class="h5-card"><h2>{{ $pixelId ? '编辑像素' : '登记已有像素' }}</h2>
                <form data-editor="pixel" data-save="savePixel"><div class="h5-form-grid">
                    <label class="h5-field">名称<input class="h5-input" wire:model="pixel.name" maxlength="100" required></label>
                    <label class="h5-field">平台<select class="h5-input" wire:model="pixel.platform"><option value="meta">Meta</option><option value="tiktok">TikTok</option></select></label>
                    <label class="h5-field">平台像素编号<input class="h5-input" wire:model="pixel.external_id" maxlength="64" required></label>
                    <label class="h5-field">资产用途<select class="h5-input" wire:model="pixel.environment"><option value="test">专用测试资产</option><option value="production">正式资产（默认不发送）</option></select></label>
                    <label class="h5-field">启用状态<select class="h5-input" wire:model="pixel.active"><option value="1">启用</option><option value="0">停用</option></select></label>
                </div><p class="h5-muted">只登记平台已有资产；不会创建平台像素。不接受任意脚本或令牌。版本 {{ $pixel['version'] }}。</p>
                @foreach($errors->all() as $error)<p class="h5-error" role="alert">{{ $error }}</p>@endforeach
                <button class="h5-button h5-primary" type="submit">保存像素</button><button class="h5-button" type="button" data-editor-action="newPixel">登记另一个</button></form>
            </section>
            <section class="h5-card"><h2>像素资产</h2><div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>资产</th><th>状态</th><th>验证与操作</th></tr></thead><tbody>
            @forelse($pixels as $asset)<tr wire:key="pixel-{{ $asset['id'] }}"><td><strong>{{ $asset['name'] }}</strong><br>{{ ucfirst($asset['platform']) }} · {{ $asset['external_id'] }}<br><span class="h5-badge">{{ $asset['environment'] === 'test' ? '测试' : '正式' }}</span> · 版本 {{ $asset['version'] }}</td>
                <td>{{ $asset['active'] ? '启用' : '停用' }}<br>可见关联链接 {{ $asset['affected_links'] }} 个<br>共享人员 {{ count($asset['assigned_staff_ids']) }} 名</td>
                <td>本站模拟：{{ $asset['local_status'] === 'simulated' ? '通过' : '未测试' }}<br>平台：待联调<br><button class="h5-button" data-editor-action="selectPixel" data-editor-id="{{ $asset['id'] }}">编辑 / 启停</button>@if($asset['environment'] === 'test')<button class="h5-button" wire:click="simulatePixel('{{ $asset['id'] }}')">本地模拟校验</button>@endif @if($owner)<button class="h5-button" data-editor-action="selectAssignment" data-editor-type="pixel" data-editor-id="{{ $asset['id'] }}">分配像素</button>@endif</td></tr>
            @empty<tr><td colspan="3">尚无可见像素，请登记你拥有或获授权的测试资产。</td></tr>@endforelse
            </tbody></table></div><p class="h5-muted">共 {{ $pixelPagination['total'] }} 项 · 第 {{ $pixelPagination['page'] }} / {{ $pixelPagination['last_page'] }} 页</p>
            @if($pixelPagination['page'] > 1)<button class="h5-button" wire:click="$set('pixelPage', {{ $pixelPagination['page'] - 1 }})">上一页像素</button>@endif
            @if($pixelPagination['page'] < $pixelPagination['last_page'])<button class="h5-button" wire:click="$set('pixelPage', {{ $pixelPagination['page'] + 1 }})">下一页像素</button>@endif</section>
        </x-admin.panel>

        <x-admin.panel group="acquisition-tabs" name="payments" :active="$tab">
            <section class="h5-card"><h2>本站付款汇总</h2>
                @if($summary)<p>{{ $summary['definition'] }}</p><p>落地访问：待 D05 和 04 插槽接入；预加载不计真实观看。以下金额为本站核实付款，不是平台归因、ROAS 或平台确认。</p>
                    <div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>环境</th><th>付款类型</th><th>笔数</th><th>核实付款总额</th></tr></thead><tbody>
                    @forelse($summary['payments'] as $row)<tr><td>{{ $row['is_test'] ? '测试' : '真实' }}</td><td>{{ ['first_subscription'=>'首次订阅','resubscription'=>'再次订阅','renewal'=>'续费'][$row['transaction_kind']] ?? $row['transaction_kind'] }}</td><td>{{ $row['payments'] }}</td><td>{{ \App\Support\Admin\UsdAmount::format($row['amount_minor']) }}</td></tr>@empty<tr><td colspan="4">暂无对应付款事实。</td></tr>@endforelse
                    </tbody></table></div>
                @else<p class="h5-muted">先在“推广链接”中选择一条有权限的链接，再点击“查看本站汇总”。仅展示该链接的核实付款，不提供用户联系方式或逐人订单。</p>@endif
            </section>
        </x-admin.panel>

        <x-admin.panel group="acquisition-tabs" name="deliveries" :active="$tab">
            <section class="h5-card"><h2>发送状态</h2>
                @if($summary)<p>对应链接的脱敏聚合结果；失败重试仅适用于尚未成功发送的项目。重试不变更订单，也不会在页面中直接发送。</p>
                    @forelse($summary['deliveries'] as $row)<p><span class="h5-badge">{{ $row['state'] }}</span> {{ $row['count'] }} 项 @if($row['reason'])（{{ $row['reason'] }}）@endif</p>@empty<p>暂无对应发送记录。</p>@endforelse
                @else<p class="h5-muted">先在“推广链接”中选择一条有权限的链接，再点击“查看本站汇总”。这里仅展示发送状态和失败原因聚合，不显示订单或用户标识。</p>@endif
            </section>
        </x-admin.panel>

        @if($owner)<x-admin.panel group="acquisition-tabs" name="assignments" :active="$tab">
            <section class="h5-card"><h2>负责人分配</h2>
                <p>创建人保留资产访问权；Owner 可为资产分配多位员工。被移除人员访问权会重新核验。投流员工只查看自身或获分配资产及本站汇总，不查看用户联系方式或逐人订单。</p>
                @if($shareId)<p>正在分配：{{ $shareName }} · {{ $shareType === 'pixel' ? '像素' : '链接' }} · 版本 {{ $shareVersion }}</p>
                    <p class="h5-muted">{{ $staffMessage }}</p><button class="h5-button" wire:click="loadStaff">{{ $staffCursor ? '读取下一页人员' : '读取可分配人员' }}</button>
                    <form data-editor="assignment" data-save="share">@foreach($staffCandidates as $candidate)<label class="h5-field"><input type="checkbox" wire:model="staffIds" value="{{ $candidate['id'] }}"> {{ $candidate['name'] }}</label>@endforeach<button class="h5-button h5-primary" type="submit">保存共享分配</button></form>
                @else<p class="h5-muted">从像素或链接列表选择“分配”以管理共享人员。</p>@endif
            </section>
        </x-admin.panel>@endif
    </x-admin.workspace>
    <script>
        (() => {
            const root = document.querySelector('[data-admin-ui="1"]');
            if (!root || root.dataset.acquisitionGuard === '1') return;
            root.dataset.acquisitionGuard = '1';
            const dirty = () => [...root.querySelectorAll('form[data-editor]')].some(form => form.dataset.dirty === '1');
            root.addEventListener('input', event => { const form = event.target.closest('form[data-editor]'); if (form) form.dataset.dirty = '1'; });
            root.addEventListener('change', event => { const form = event.target.closest('form[data-editor]'); if (form) form.dataset.dirty = '1'; });
            const livewire = () => {
                const host = root.closest('[wire\\:id]');
                const id = host?.getAttribute('wire:id');
                return id && window.Livewire?.find(id);
            };
            const save = async () => {
                const form = root.querySelector('form[data-editor][data-dirty="1"]');
                const component = livewire();
                if (!form || !component) return false;
                const saved = await component.call(form.dataset.save);
                if (saved === true) {
                    form.dataset.dirty = '0';
                }
                return saved === true;
            };
            const discard = async () => {
                const form = root.querySelector('form[data-editor][data-dirty="1"]');
                const component = livewire();
                if (!form || !component) return false;
                const restored = await component.call('restoreEditor', form.dataset.editor);
                if (restored === true) {
                    form.dataset.dirty = '0';
                }
                return restored === true;
            };
            // Resolve the current component after confirmation: Livewire may replace the tab button.
            root.addEventListener('h5-admin:tab-request', async event => {
                if (event.detail.group !== 'acquisition-tabs' || !dirty()) return;
                event.preventDefault();
                if (await window.H5Admin.confirmUnsaved({ save, discard })) {
                    await livewire()?.call('selectTab', event.detail.id);
                }
            });
            root.addEventListener('click', async event => {
                const button = event.target.closest('[data-editor-action]');
                if (!button) return;
                event.preventDefault();
                const method = button.dataset.editorAction;
                const args = method === 'selectAssignment' ? [button.dataset.editorType, button.dataset.editorId]
                    : button.dataset.editorId ? [button.dataset.editorId] : [];
                if (dirty() && !await window.H5Admin?.confirmUnsaved({ save, discard })) return;
                await livewire()?.call(method, ...args);
            });
            const attach = () => { if (window.H5Admin?.protect) window.H5Admin.protect(root, { isDirty: dirty, save, discard }); };
            attach();
            document.addEventListener('h5-admin:ready', attach, { once: true });
            root.addEventListener('submit', async event => {
                const form = event.target.closest('form[data-editor]');
                if (!form) return;
                event.preventDefault();
                const component = livewire();
                if (!component) return;
                const saved = await component.call(form.dataset.save);
                if (saved === true) form.dataset.dirty = '0';
            });
        })();
    </script>
</x-filament-panels::page>
