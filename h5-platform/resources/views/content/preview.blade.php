<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>内容草稿预览</title><style>body{font:16px/1.65 system-ui;background:#111827;color:#e2e8f0;margin:24px auto;max-width:800px;padding:0 20px}video{display:block;max-width:100%;height:72vh;margin:auto;background:#000}a{color:#93c5fd}.notice{padding:14px;background:#1e293b;border-radius:10px}</style></head><body>
<h1>{{ $episode->drama->title_zh ?: $episode->drama->title_en }} · 第 {{ $episode->number }} 集</h1>
<p class="notice">员工草稿预览。每次媒体和 Range 请求均核验当前员工权限。此页不表示公开发布、版权已核实或会员链路通过。字幕为实际登记的 {{ $episode->subtitle_language ?: '无' }}，未自动翻译。</p>
@if($episode->video?->state === 'ready')
<video controls playsinline preload="metadata" src="{{ route('content.preview-media', ['id' => $episode->id, 'file' => 'media.mp4']) }}">
@if($episode->subtitle?->state === 'ready')<track kind="subtitles" src="{{ route('content.preview-media', ['id' => $episode->id, 'file' => 'subtitles.vtt']) }}" srclang="{{ $episode->subtitle_language === 'zh_CN' ? 'zh-Hans' : 'en' }}" label="{{ $episode->subtitle_language === 'zh_CN' ? '简体中文' : 'English' }}" default>@endif
</video>
@else<p role="alert">视频尚未就绪：{{ $episode->video?->state ?? '未绑定视频' }} {{ $episode->video?->error_code }}</p>@endif
<p><a href="/admin/content-library">返回内容后台</a></p>
</body></html>
