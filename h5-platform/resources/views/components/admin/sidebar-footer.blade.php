@if($staff = auth('staff')->user())
    <div class="h5-side-foot">
        <div class="h5-side-role"><img src="{{ asset('admin-ui/staff-avatar.svg') }}" alt="">{{ $staff->role->label() }}</div>
        <p>北京时间 UTC+8<br>金额统一 USD</p>
    </div>
@endif
