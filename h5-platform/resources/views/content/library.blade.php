<x-filament-panels::page>
<link rel="stylesheet" href="{{ asset('content/admin.css') }}?v={{ substr(hash_file('sha256', public_path('content/admin.css')), 0, 12) }}">
<x-admin.workspace id="content-app" title="内容管理" description="剧目内管理视频、字幕与封面；首页横图在 Banner 中管理。" data-owner="{{ $isOwner ? '1' : '0' }}" wire:ignore>
    <x-slot:actions><div id="content-list-actions" class="h5-toolbar"></div></x-slot:actions>
    <div id="content-crumb" class="ct-crumb" hidden><button type="button" class="h5-link" data-action="back">剧目库</button><span>/</span><span id="content-crumb-title"></span></div>
    <div id="content-list-screen">
        <x-admin.tabs id="content-tabs" :items="[['id'=>'dramas','label'=>'剧目库'],['id'=>'releases','label'=>'发布排期'],['id'=>'categories','label'=>'分类管理'],['id'=>'banners','label'=>'首页 Banner']]" active="dramas" />
        @foreach(['dramas','releases','categories','banners'] as $section)
            <x-admin.panel group="content-tabs" :name="$section" active="dramas"><div id="content-view-{{ $section }}"></div></x-admin.panel>
        @endforeach
    </div>
    <div id="content-detail-screen" hidden>
        <x-admin.tabs id="content-detail-tabs" :items="[['id'=>'basics','label'=>'基本资料'],['id'=>'episodes','label'=>'分集与字幕'],['id'=>'rights','label'=>'授权与试看'],['id'=>'release','label'=>'发布设置'],['id'=>'history','label'=>'操作记录']]" active="episodes" />
        @foreach(['basics','episodes','rights','release','history'] as $section)
            <x-admin.panel group="content-detail-tabs" :name="$section" active="episodes"><div id="content-detail-{{ $section }}"></div></x-admin.panel>
        @endforeach
    </div>
    <p id="content-message" role="status" aria-live="polite" hidden></p>
    <x-admin.dialog id="content-dialog" title="内容编辑">
        <div id="content-dialog-content"></div>
        <p id="content-dialog-error" class="h5-error" role="alert" hidden></p>
        <x-slot:actions><div id="content-dialog-actions" class="h5-toolbar"></div></x-slot:actions>
    </x-admin.dialog>
</x-admin.workspace>
<script src="{{ asset('content/admin.js') }}?v={{ substr(hash_file('sha256', public_path('content/admin.js')), 0, 12) }}" defer></script>
</x-filament-panels::page>
