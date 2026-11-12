<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Meta 单次页面测试</title>
<link rel="stylesheet" href="{{ asset('admin-ui/admin.css') }}">
</head><body><main class="h5-card" style="max-width:640px;margin:24px auto;padding:20px;overflow-wrap:anywhere">
<h1>Meta 单次页面测试</h1>
<p>仅负责人在受控测试站操作。本页不会自动向 Meta 发送事件。</p>
<p>确认后，将本页访问时间、去除查询参数的页面地址、本次请求的 IP 和浏览器信息，准备给所选 Meta 测试资产，用于核对 Test Events。意图有效 10 分钟；工程精确执行一次。无付款事件、金额、联系方式、账号标识或持续访问采集。</p>
<p>接口接受后仍须由资产持有人在 Meta Test Events 确认，不代表广告归因或投放效果。网络结果未知不自动重试，也不要重新准备意图替代重试；先由工程与资产持有人核对。</p>
<section class="h5-card" aria-label="本轮测试隐私选择">
<p>请点击下方“隐私选择”，阅读现有测试告知后自主选择广告用途；分析保持关闭。保存成功后刷新本页，再准备单次测试。本页不会自动授予同意。</p>
@include('audience.partials.tracking')
</section>
@if(!$granted)<p role="status">尚无广告同意，暂不能准备测试。请先保存上述隐私选择，再刷新本页。</p>@endif
<form method="post" action="{{ \App\Modules\Acquisition\Probe\PageTestProbe::PATH }}">@csrf
<label class="h5-field">Meta 测试资产<select class="h5-input" name="pixel_id" required><option value="">请选择</option>@foreach($assets as $asset)<option value="{{ $asset->id }}">{{ $asset->name }}</option>@endforeach</select></label>
<label class="h5-field" style="margin:16px 0"><span><input type="checkbox" name="agree" value="1" required> 我已阅读上述说明，同意本轮准备一次 Meta PageView 测试，交由工程在有效期内发送。</span></label>
<button class="h5-button h5-primary" type="submit" @disabled(!$granted)>准备单次测试（此步不发送）</button></form>
@if($intent)<p role="status">最近准备的意图：<code>{{ $intent }}</code>。请交工程核对，不要重复点击准备。发送结果由工程回告。</p>@endif
<form method="post" action="{{ \App\Modules\Acquisition\Probe\PageTestProbe::PATH }}/withdraw" style="margin-top:20px">@csrf<button class="h5-button" type="submit">撤回广告同意，阻止尚未发送的测试</button></form>
<p>已在途请求无法撤回。无需提供密码或把 Token、测试码发到聊天。</p>
</main></body></html>
