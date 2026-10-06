<x-filament-panels::page>
<x-filament::section heading="新增员工"><p>每人一个独立账号。密码通过你认可的私密方式交给本人，首次登录后配置验证器并保存恢复码。</p>
<form wire:submit="createStaff" style="display:grid;gap:16px;max-width:520px">
<label>姓名 <input wire:model="newStaff.name" required maxlength="80" class="fi-input" style="width:100%;border:1px solid #aaa;padding:8px;border-radius:6px"></label>
<label>邮箱 <input type="email" wire:model="newStaff.email" required class="fi-input" style="width:100%;border:1px solid #aaa;padding:8px;border-radius:6px"></label>
<label>初始密码（至少 12 位，含字母和数字） <input type="password" wire:model="newStaff.password" autocomplete="new-password" required class="fi-input" style="width:100%;border:1px solid #aaa;padding:8px;border-radius:6px"></label>
<label>角色 <select wire:model="newStaff.role" style="padding:8px">@foreach($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach</select></label>
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif<x-filament::button type="submit">创建员工</x-filament::button></form></x-filament::section>
<x-filament::section heading="员工与角色"><div style="overflow:auto"><table style="width:100%;text-align:left"><thead><tr><th>姓名 / 邮箱</th><th>角色</th><th>启用</th><th>二次验证</th><th>操作</th></tr></thead><tbody>
@foreach($staffAccounts as $staff)<tr wire:key="staff-{{ $staff->id }}"><td style="padding:14px 0">{{ $staff->name }}<br>{{ $staff->email }}</td><td><select wire:model="edits.{{ $staff->id }}.role">@foreach($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach</select></td><td><input type="checkbox" wire:model="edits.{{ $staff->id }}.active" aria-label="启用 {{ $staff->name }}"></td><td>{{ filled($staff->getAppAuthenticationSecret()) ? '已配置' : '待本人配置' }}</td><td><x-filament::button wire:click="saveStaff('{{ $staff->id }}')">保存</x-filament::button></td></tr>@endforeach
</tbody></table></div><p>保存权限变更会撤销旧会话。不能停用或降级当前负责人的账号。</p></x-filament::section>
<x-filament::section heading="最近 30 条脱敏审计"><div style="overflow:auto"><table style="width:100%;text-align:left"><tr><th>UTC 时间</th><th>动作</th><th>结果</th><th>请求编号</th></tr>@foreach($auditEvents as $event)<tr><td>{{ $event->occurred_at }}</td><td>{{ $event->action }}</td><td>{{ $event->result }}</td><td><code>{{ $event->request_id }}</code></td></tr>@endforeach</table></div></x-filament::section>
</x-filament-panels::page>
