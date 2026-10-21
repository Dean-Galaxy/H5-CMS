<x-filament-panels::page>
    <x-admin.workspace id="acquisition-workspace" title="投流管理" description="管理获授权的推广链接与像素；外部平台联调待完成。">
        <x-slot:actions>
            @if($tab === 'links')<button type="button" class="h5-button h5-primary" data-editor-action="newLink">新增链接</button>@endif
            @if($tab === 'pixels')<button type="button" class="h5-button h5-primary" data-editor-action="newPixel">登记像素</button>@endif
        </x-slot:actions>
        <x-admin.tabs id="acquisition-tabs" :items="$tabs" :active="$tab" method="selectTab" label="投流管理功能分栏" />

        <x-admin.panel group="acquisition-tabs" name="links" :active="$tab">
            <section class="h5-card"><h3>推广链接</h3>
                <div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>链接</th><th>复制与状态</th><th>管理</th></tr></thead><tbody>
                @forelse($links as $entry)
                    <tr wire:key="link-{{ $entry['id'] }}"><td><strong>{{ $entry['name'] }}</strong><br>{{ $entry['drama_title'] }}<br>{{ ucfirst($entry['platform']) }} · 版本 {{ $entry['version'] }}</td>
                        <td><input class="h5-input" readonly value="{{ $entry['url'] }}" aria-label="推广链接" onclick="this.select()"><button type="button" class="h5-button" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText(@js($entry['url'])).then(() => copied = true)" x-text="copied ? '已复制' : '复制链接'">复制链接</button><br>{{ $entry['active'] ? '启用配置' : '停用' }} · 落地待 D05</td>
                        <td><div class="h5-toolbar"><button class="h5-link" data-editor-action="selectLink" data-editor-id="{{ $entry['id'] }}">编辑 / 停用</button><button class="h5-link" data-editor-action="showSummary" data-editor-id="{{ $entry['id'] }}">查看本站汇总</button><button class="h5-link" wire:click="retry('{{ $entry['id'] }}')">重试待处理发送</button>@if($owner)<button class="h5-link" data-editor-action="selectAssignment" data-editor-type="link" data-editor-id="{{ $entry['id'] }}">分配链接</button>@endif</div></td></tr>
                @empty<tr><td colspan="3">尚无可见推广链接。</td></tr>@endforelse
                </tbody></table></div>
                <p class="h5-muted">共 {{ $linkPagination['total'] }} 项 · 第 {{ $linkPagination['page'] }} / {{ $linkPagination['last_page'] }} 页</p>
                @if($linkPagination['page'] > 1)<button class="h5-button" wire:click="$set('linkPage', {{ $linkPagination['page'] - 1 }})">上一页链接</button>@endif
                @if($linkPagination['page'] < $linkPagination['last_page'])<button class="h5-button" wire:click="$set('linkPage', {{ $linkPagination['page'] + 1 }})">下一页链接</button>@endif
            </section>
        </x-admin.panel>

        <x-admin.panel group="acquisition-tabs" name="pixels" :active="$tab">
            <section class="h5-card"><h3>像素资产</h3><div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>资产</th><th>状态</th><th>验证与操作</th></tr></thead><tbody>
            @forelse($pixels as $asset)<tr wire:key="pixel-{{ $asset['id'] }}"><td><strong>{{ $asset['name'] }}</strong><br>{{ ucfirst($asset['platform']) }} · {{ $asset['external_id'] }}<br><span class="h5-badge">{{ $asset['environment'] === 'test' ? '测试' : '正式' }}</span> · 版本 {{ $asset['version'] }}</td>
                <td><span class="h5-badge {{ $asset['active'] ? 'h5-ok' : 'h5-warn' }}">{{ $asset['active'] ? '启用' : '停用' }}</span><br>可见关联链接 {{ $asset['affected_links'] }} 个<br>共享人员 {{ count($asset['assigned_staff_ids']) }} 名</td>
                <td>本站模拟：{{ $asset['local_status'] === 'simulated' ? '通过' : '未测试' }}<br>平台：待联调<div class="h5-toolbar"><button class="h5-link" data-editor-action="selectPixel" data-editor-id="{{ $asset['id'] }}">编辑 / 启停</button>@if($asset['environment'] === 'test')<button class="h5-link" wire:click="simulatePixel('{{ $asset['id'] }}')">本地模拟校验</button>@endif @if($owner)<button class="h5-link" data-editor-action="selectAssignment" data-editor-type="pixel" data-editor-id="{{ $asset['id'] }}">分配像素</button>@endif</div></td></tr>
            @empty<tr><td colspan="3">尚无可见像素，请登记你拥有或获授权的测试资产。</td></tr>@endforelse
            </tbody></table></div><p class="h5-muted">共 {{ $pixelPagination['total'] }} 项 · 第 {{ $pixelPagination['page'] }} / {{ $pixelPagination['last_page'] }} 页</p>
            @if($pixelPagination['page'] > 1)<button class="h5-button" wire:click="$set('pixelPage', {{ $pixelPagination['page'] - 1 }})">上一页像素</button>@endif
            @if($pixelPagination['page'] < $pixelPagination['last_page'])<button class="h5-button" wire:click="$set('pixelPage', {{ $pixelPagination['page'] + 1 }})">下一页像素</button>@endif</section>
        </x-admin.panel>

        <x-admin.panel group="acquisition-tabs" name="payments" :active="$tab">
                @if($summary)
                    <div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>环境</th><th>交易类型</th><th>笔数</th><th>已核实付款（USD）</th></tr></thead><tbody>
                    @forelse($summary['payments'] as $row)<tr><td>{{ $row['is_test'] ? '测试' : '真实' }}</td><td>{{ ['first_subscription'=>'首次订阅','resubscription'=>'再次订阅','renewal'=>'续费'][$row['transaction_kind']] ?? $row['transaction_kind'] }}</td><td>{{ $row['payments'] }}</td><td>{{ \App\Support\Admin\UsdAmount::format($row['amount_minor']) }}</td></tr>@empty<tr><td colspan="4">暂无对应付款事实。</td></tr>@endforelse
                    </tbody></table></div>
                    <p class="h5-muted">{{ $summary['definition'] }} 落地访问尚未接入，预加载不计真实观看。</p>
                @else<section class="h5-card"><h3>付款汇总</h3><p class="h5-muted">先在“推广链接”中选择一条有权限的链接，再点击“查看本站汇总”。仅展示该链接的核实付款，不提供用户联系方式或逐人订单。</p></section>@endif
        </x-admin.panel>

        <x-admin.panel group="acquisition-tabs" name="deliveries" :active="$tab">
            <section class="h5-card"><h3>发送记录</h3>
                @if($summary)<p>对应链接的脱敏聚合结果；失败重试仅适用于尚未成功发送的项目。重试不变更订单，也不会在页面中直接发送。</p>
                    @forelse($summary['deliveries'] as $row)<p><span class="h5-badge">{{ $row['state'] }}</span> {{ $row['count'] }} 项 @if($row['reason'])（{{ $row['reason'] }}）@endif</p>@empty<p>暂无对应发送记录。</p>@endforelse
                @else<p class="h5-muted">先在“推广链接”中选择一条有权限的链接，再点击“查看本站汇总”。这里仅展示发送状态和失败原因聚合，不显示订单或用户标识。</p>@endif
            </section>
        </x-admin.panel>

        <p class="h5-muted" data-acquisition-environment>外部发送：{{ config('acquisition.transport') === 'disabled' ? '关闭' : config('acquisition.transport') }} · 来源与落地规则待确认；无来源不阻塞订阅。</p>
        <p class="h5-error" data-editor-error role="alert" hidden></p>
        <x-admin.dialog id="acquisition-link-editor" :title="$linkId ? '编辑推广链接' : '新增链接'" wire:ignore.self data-editor-dialog="link">
                <p class="h5-muted">选择可投放的已发布剧目、平台与获授权的像素。</p><button class="h5-button" wire:click="loadCatalog">{{ $catalogCursor ? '读取下一页剧目' : '读取可投放剧目' }}</button>
                <form id="acquisition-link-form" data-editor="link" data-save="saveLink">
                    <div class="h5-form-grid">
                        <label class="h5-field">推广名称<input class="h5-input" wire:model="link.name" maxlength="100" required></label>
                        <label class="h5-field">平台<select class="h5-input" wire:model="link.platform"><option value="meta">Meta</option><option value="tiktok">TikTok</option></select></label>
                        <label class="h5-field">已发布剧目<select class="h5-input" wire:model="link.drama_id" required><option value="">请选择</option>@foreach($catalog as $drama)<option value="{{ $drama['id'] }}">{{ $drama['title'] }}</option>@endforeach</select></label>
                        <label class="h5-field">授权像素<select class="h5-input" wire:model="link.pixel_id" required><option value="">请选择</option>@foreach($pixelOptions as $asset)<option value="{{ $asset['id'] }}">{{ $asset['name'] }} · {{ $asset['platform'] }}{{ $asset['active'] ? '' : '（停用）' }}</option>@endforeach</select></label>
                        <label class="h5-field">启用状态<select class="h5-input" wire:model="link.active"><option value="1">启用</option><option value="0">停用</option></select></label>
                    </div>
                    <p class="h5-muted">{{ $catalogMessage }}。版本 {{ $link['version'] }}；落地与来源规则待确认。已选像素无权使用时仅可停用已有链接。</p>
                    @foreach($errors->all() as $error)<p class="h5-error" role="alert">{{ $error }}</p>@endforeach

                </form>
            <p class="h5-error" data-editor-error role="alert" hidden></p>
            <x-slot:actions><button type="button" class="h5-button" data-admin-close>取消</button><button type="submit" form="acquisition-link-form" class="h5-button h5-primary">保存链接</button></x-slot:actions>
        </x-admin.dialog>
        <x-admin.dialog id="acquisition-pixel-editor" :title="$pixelId ? '编辑像素' : '登记像素'" wire:ignore.self data-editor-dialog="pixel">
                <form id="acquisition-pixel-form" data-editor="pixel" data-save="savePixel"><div class="h5-form-grid">
                    <label class="h5-field">名称<input class="h5-input" wire:model="pixel.name" maxlength="100" required></label>
                    <label class="h5-field">平台<select class="h5-input" wire:model="pixel.platform"><option value="meta">Meta</option><option value="tiktok">TikTok</option></select></label>
                    <label class="h5-field">平台像素编号<input class="h5-input" wire:model="pixel.external_id" maxlength="64" required></label>
                    <label class="h5-field">资产用途<select class="h5-input" wire:model="pixel.environment"><option value="test">专用测试资产</option><option value="production">正式资产（默认不发送）</option></select></label>
                    <label class="h5-field">启用状态<select class="h5-input" wire:model="pixel.active"><option value="1">启用</option><option value="0">停用</option></select></label>
                </div><p class="h5-muted">只登记平台已有资产；不会创建平台像素。不接受任意脚本或令牌。版本 {{ $pixel['version'] }}。</p>
                @foreach($errors->all() as $error)<p class="h5-error" role="alert">{{ $error }}</p>@endforeach
</form>
            <p class="h5-error" data-editor-error role="alert" hidden></p>
            <x-slot:actions><button type="button" class="h5-button" data-admin-close>取消</button><button type="submit" form="acquisition-pixel-form" class="h5-button h5-primary">保存像素</button></x-slot:actions>
        </x-admin.dialog>
        @if($owner)<x-admin.dialog id="acquisition-assignment-editor" title="负责人分配" wire:ignore.self data-editor-dialog="assignment">
                <p>创建人保留资产访问权；Owner 可为资产分配多位员工。被移除人员访问权会重新核验。投流员工只查看自身或获分配资产及本站汇总，不查看用户联系方式或逐人订单。</p>
                @if($shareId)<p>正在分配：{{ $shareName }} · {{ $shareType === 'pixel' ? '像素' : '链接' }} · 版本 {{ $shareVersion }}</p>
                    <p class="h5-muted">{{ $staffMessage }}</p><button class="h5-button" wire:click="loadStaff">{{ $staffCursor ? '读取下一页人员' : '读取可分配人员' }}</button>
                    <form id="acquisition-assignment-form" data-editor="assignment" data-save="share">@foreach($staffCandidates as $candidate)<label class="h5-field"><input type="checkbox" wire:model="staffIds" value="{{ $candidate['id'] }}"> {{ $candidate['name'] }}</label>@endforeach</form>
                @else<p class="h5-muted">从像素或链接列表选择“分配”以管理共享人员。</p>@endif
            <p class="h5-error" data-editor-error role="alert" hidden></p>
            <x-slot:actions><button type="button" class="h5-button" data-admin-close>取消</button><button type="submit" form="acquisition-assignment-form" class="h5-button h5-primary">保存共享分配</button></x-slot:actions>
        </x-admin.dialog>@endif
    </x-admin.workspace>
    <script>
        (() => {
            const root = document.getElementById('acquisition-workspace');
            if (!root || root.dataset.acquisitionGuard === '1') return;
            root.dataset.acquisitionGuard = '1';
            let activeEditor = null, opener = null, busy = false;
            const lock = value => { busy = value; root.querySelectorAll('dialog[data-editor-dialog] input, dialog[data-editor-dialog] select, dialog[data-editor-dialog] button').forEach(control => control.disabled = value); };
            const component = () => window.Livewire?.find(root.closest('[wire\\:id]')?.getAttribute('wire:id'));
            const form = () => activeEditor && root.querySelector(`form[data-editor="${activeEditor}"]`);
            const dirty = () => form()?.dataset.dirty === '1';
            const failure = () => { const error = root.querySelector(`dialog[open] [data-editor-error]`) || root.querySelector('[data-editor-error]'); error.textContent = '未确认操作成功，请保留修改并检查表单或网络。'; error.hidden = false; };
            const close = () => { if (activeEditor) H5Admin.closeDialog(`acquisition-${activeEditor}-editor`); };
            const finish = async save => {
                if (busy || !form()) return false;
                lock(true);
                try {
                    const current = form();
                    const ok = save ? await component().call(current.dataset.save) : await component().call('restoreEditor', activeEditor);
                    if (ok !== true) return false;
                    form().dataset.dirty = '0';
                    close();
                    return true;
                } catch { failure(); return false; }
                finally { lock(false); }
            };
            const save = () => finish(true), discard = () => finish(false);
            root.addEventListener('input', event => { const editor = event.target.closest('form[data-editor]'); if (editor) editor.dataset.dirty = '1'; });
            root.addEventListener('change', event => { const editor = event.target.closest('form[data-editor]'); if (editor) editor.dataset.dirty = '1'; });
            root.addEventListener('close', event => {
                if (!event.target.matches('dialog[data-editor-dialog]')) return;
                activeEditor = null;
                requestAnimationFrame(() => { if (opener?.isConnected) opener.focus(); });
            }, true);
            root.addEventListener('h5-admin:dialog-close-request', event => { if (busy) event.preventDefault(); });
            root.addEventListener('click', async event => {
                const button = event.target.closest('[data-editor-action]');
                if (!button || busy || !window.H5Admin) return;
                event.preventDefault();
                if (dirty() && !await H5Admin.confirmUnsaved({ save, discard })) return;
                const method = button.dataset.editorAction;
                const args = method === 'selectAssignment' ? [button.dataset.editorType, button.dataset.editorId]
                    : button.dataset.editorId ? [button.dataset.editorId] : [];
                lock(true);
                try {
                    const ok = await component().call(method, ...args);
                    if (method === 'showSummary' || ok !== true) return;
                    activeEditor = ({ newLink: 'link', selectLink: 'link', newPixel: 'pixel', selectPixel: 'pixel', selectAssignment: 'assignment' })[method];
                    if (!activeEditor) return;
                    opener = button;
                    form().dataset.dirty = '0';
                    root.querySelectorAll('[data-editor-error]').forEach(error => error.hidden = true);
                    H5Admin.openDialog(`acquisition-${activeEditor}-editor`);
                } catch { failure(); }
                finally { lock(false); }
            });
            root.addEventListener('submit', event => {
                if (!event.target.matches('form[data-editor]')) return;
                event.preventDefault();
                save();
            });
            const attach = () => window.H5Admin?.protect(root, { isDirty: dirty, save, discard });
            attach();
            document.addEventListener('h5-admin:ready', attach, { once: true });
        })();
    </script>
</x-filament-panels::page>
