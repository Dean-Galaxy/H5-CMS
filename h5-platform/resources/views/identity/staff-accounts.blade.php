<x-filament-panels::page>
    <x-admin.workspace
        id="identity-staff-workspace"
        title="员工与权限"
        description="每人独立账号；账号与观众身份分开"
        x-data="{
            busy: false,
            dirty: false,
            dispose: null,
            ready: false,
            onReady: null,
            opener: null,
            init() {
                this.onReady = () => {
                    this.dispose = H5Admin.protect(this.$el, {
                        isDirty: () => this.dirty,
                        save: () => this.save(),
                        discard: () => this.discard(),
                    });
                    this.ready = true;
                };
                if (window.H5Admin) this.onReady();
                else document.addEventListener('h5-admin:ready', this.onReady, { once: true });
            },
            destroy() {
                this.dispose?.();
                document.removeEventListener('h5-admin:ready', this.onReady);
            },
            restoreFocus() {
                this.$nextTick(() => {
                    const dialog = document.getElementById('identity-staff-editor');
                    if (dialog && !dialog.open && this.opener?.isConnected) {
                        this.opener.focus();
                    }
                });
            },
            async open(id = null, opener = null) {
                if (this.busy || !this.ready) return;
                this.opener = opener ?? document.activeElement;
                this.busy = true;
                try {
                    if (id === null) await $wire.openCreate();
                    else await $wire.openStaff(id);
                    this.dirty = false;
                    H5Admin.openDialog('identity-staff-editor');
                } finally { this.busy = false; }
            },
            async save() {
                if (this.busy) return false;
                this.busy = true;
                try {
                    const saved = await $wire.saveEditor();
                    if (saved !== true) return false;
                    this.dirty = false;
                    H5Admin.closeDialog('identity-staff-editor');
                    return true;
                } finally { this.busy = false; this.restoreFocus(); }
            },
            async discard() {
                if (this.busy) return false;
                this.busy = true;
                try {
                    if (await $wire.discardEditor() !== true) return false;
                    this.dirty = false;
                    H5Admin.closeDialog('identity-staff-editor');
                    return true;
                } finally { this.busy = false; this.restoreFocus(); }
            }
        }"
    >
        @if($tab === 'accounts')
            <x-slot:actions>
                <button type="button" class="h5-button h5-primary" x-on:click="open(null, $event.currentTarget)" :disabled="busy || !ready"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>新增员工</button>
            </x-slot:actions>
        @endif

        <x-admin.tabs id="identity-staff-tabs" label="员工管理分栏"
            :items="[['id' => 'accounts', 'label' => '员工账号'], ['id' => 'audit', 'label' => '操作审计']]"
            :active="$tab" method="selectTab" />

        @if($tab === 'accounts')
            <section id="identity-staff-tabs-panel-accounts" role="tabpanel" aria-labelledby="identity-staff-tabs-tab-accounts" class="h5-tab-panel" tabindex="0">
                <div class="h5-table-wrap">
                    <table class="h5-table">
                        <thead><tr><th scope="col">员工</th><th scope="col">角色</th><th scope="col">账号状态</th><th scope="col">二次验证</th><th scope="col">操作</th></tr></thead>
                        <tbody>
                        @forelse($staffAccounts as $staff)
                            <tr wire:key="staff-{{ $staff->id }}">
                                <td>{{ $staff->name }}<br><span class="h5-muted">{{ $staff->email }}</span></td>
                                <td>{{ $staff->role->label() }}</td>
                                <td><span class="h5-badge {{ $staff->active ? 'h5-ok' : '' }}">{{ $staff->active ? '启用' : '已停用' }}</span></td>
                                <td>{{ filled($staff->getAppAuthenticationSecret()) ? '已设置' : '待本人配置' }}</td>
                                <td>
                                    @if($staff->id === auth('staff')->id())
                                        <span class="h5-muted">当前账号</span>
                                    @else
                                        <button type="button" class="h5-link" x-on:click="open(@js($staff->id), $event.currentTarget)" :disabled="busy || !ready" aria-label="管理 {{ $staff->name }}">管理</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5">暂无员工账号。</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="h5-note">新增员工后由本人配置二次验证。权限或状态变更会撤销旧会话；不能停用或降级当前账号。</p>
            </section>
        @else
            <section id="identity-staff-tabs-panel-audit" role="tabpanel" aria-labelledby="identity-staff-tabs-tab-audit" class="h5-tab-panel" tabindex="0">
                <div class="h5-table-wrap">
                    <table class="h5-table">
                        <thead><tr><th scope="col">时间（北京时间）</th><th scope="col">操作</th><th scope="col">结果</th><th scope="col">操作人</th></tr></thead>
                        <tbody>
                        @forelse($auditEvents as $event)
                            <tr wire:key="audit-{{ $event['id'] }}">
                                <td>{{ $event['time'] }}</td>
                                <td>{{ $event['action'] }}<details class="h5-muted"><summary>排查编号</summary><small>{{ $event['request_id'] }}</small></details></td>
                                <td>{{ $event['result'] }}</td>
                                <td>{{ $event['actor'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4">暂无审计记录。</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="h5-note">最近 30 条脱敏记录，时间以北京时间显示。员工姓名按当前账号脱敏展示。</p>
            </section>
        @endif

        <x-admin.dialog id="identity-staff-editor" :title="$editingId ? '管理'.$editingName : '新增员工'" :content-start-gap="$editingId ? 18 : 20" wire:ignore.self
            x-on:h5-admin:dialog-close-request="if (busy) $event.preventDefault()">
            <form id="identity-staff-form" x-on:submit.prevent="save()" x-on:input="dirty = true" x-on:change="dirty = true">
                <fieldset class="{{ $editingId === null ? 'h5-form-grid' : '' }}" :disabled="busy || !ready">
                    @if($editingId === null)
                        <label class="h5-field">姓名
                            <input class="h5-input" wire:model="newStaff.name" required maxlength="80" autocomplete="name">
                            @error('newStaff.name')<span class="h5-error" role="alert">{{ $message }}</span>@enderror
                        </label>
                        <label class="h5-field">角色
                            <select class="h5-input" aria-label="角色" wire:model="newStaff.role" required>
                                @foreach($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach
                            </select>
                            @error('newStaff.role')<span class="h5-error" role="alert">{{ $message }}</span>@enderror
                        </label>
                        <label class="h5-field h5-span">邮箱
                            <input class="h5-input" type="email" wire:model="newStaff.email" required maxlength="254" autocomplete="off">
                            @error('newStaff.email')<span class="h5-error" role="alert">{{ $message }}</span>@enderror
                        </label>
                        <label class="h5-field h5-span">初始密码
                            <input class="h5-input" type="password" wire:model="newStaff.password" required
                                minlength="{{ \App\Modules\Identity\Services\StaffPassword::MIN_LENGTH }}"
                                maxlength="{{ \App\Modules\Identity\Services\StaffPassword::MAX_LENGTH }}" autocomplete="new-password"
                                aria-describedby="staff-password-help">
                            <small id="staff-password-help">{{ \App\Modules\Identity\Services\StaffPassword::HELP }}</small>
                            @error('newStaff.password')<span class="h5-error" role="alert">{{ $message }}</span>@enderror
                        </label>
                        <p class="h5-note h5-span">请私密交付初始密码；首次登录须配置验证器并保存恢复码。不会自动发送邀请邮件。</p>
                    @else
                        <p class="h5-muted"><small>{{ $editingEmail }}</small></p>
                        <label class="h5-field" style="margin-top:18px">角色
                            <select class="h5-input" aria-label="角色" wire:model="editStaff.role" required>
                                @foreach($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach
                            </select>
                            @error('editStaff.role')<span class="h5-error" role="alert">{{ $message }}</span>@enderror
                        </label>
                        <label class="h5-choice h5-span"><input type="checkbox" wire:model="editStaff.active"> 启用账号</label>
                        @error('editStaff.active')<span class="h5-error h5-span" role="alert">{{ $message }}</span>@enderror
                        <p class="h5-note h5-span">取消启用后不能登录。权限变更后旧会话失效，须按新权限重新登录并完成二次验证。</p>
                    @endif
                    @error('editor')<p class="h5-error h5-span" role="alert">{{ $message }}</p>@enderror
                    @error('editStaff.version')<p class="h5-error h5-span" role="alert">{{ $message }}</p>@enderror
                </fieldset>
            </form>
            <x-slot:actions>
                <button type="button" class="h5-button" data-admin-close :disabled="busy || !ready">取消</button>
                <button type="submit" form="identity-staff-form" class="h5-button h5-primary" :disabled="busy || !ready">{{ $editingId ? '保存修改' : '创建员工' }}</button>
            </x-slot:actions>
        </x-admin.dialog>
    </x-admin.workspace>
</x-filament-panels::page>
