<x-filament-panels::page>
<x-admin.workspace id="content-settings" title="全局设置" description="设置新剧默认试看规则；单剧覆盖由内容管理维护。" wire:ignore>
    <link rel="stylesheet" href="{{ asset('content/admin.css') }}?v={{ substr(hash_file('sha256', public_path('content/admin.css')), 0, 12) }}">
    <form id="content-default-form" class="h5-card">
        <h3>默认免费集数</h3>
        <label class="h5-field ct-default-count">新剧默认前多少集免费<input class="h5-input" name="free_count" type="number" min="0" max="10000" required></label>
        <label class="h5-choice"><input type="radio" name="scope" value="new_only" checked><span>只影响之后新建的剧</span></label>
        <label class="h5-choice"><input type="radio" name="scope" value="sync"><span>同时同步未单独设置的已有剧<small class="h5-muted ct-choice-note">已单独设置的重点剧保留原值</small></span></label>
        <p class="h5-muted">手动设置过的剧目（包括同值设置）不受全局同步影响。已经开始播放的当集按现有许可规则处理。</p>
        <div class="ct-formactions"><span class="h5-muted">下一步先查看影响范围</span><button type="submit" class="h5-button h5-primary">查看影响并确认</button></div>
    </form>
    <p id="content-default-status" role="status" hidden></p>
    <x-admin.dialog id="content-default-dialog" title="确认默认试看规则">
        <div id="content-default-impact"></div>
        <p id="content-default-error" role="alert" class="h5-error" hidden></p>
        <x-slot:actions><button class="h5-button" type="button" data-admin-close>取消</button><button id="content-default-confirm" class="h5-button h5-primary" type="button">确认修改</button></x-slot:actions>
    </x-admin.dialog>
</x-admin.workspace>
<script src="{{ asset('content/errors.js') }}?v={{ substr(hash_file('sha256', public_path('content/errors.js')), 0, 12) }}" defer></script>
<script src="{{ asset('content/settings.js') }}?v={{ substr(hash_file('sha256', public_path('content/settings.js')), 0, 12) }}" defer></script>
</x-filament-panels::page>
