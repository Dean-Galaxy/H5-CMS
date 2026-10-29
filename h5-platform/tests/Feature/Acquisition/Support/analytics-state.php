<?php

// Safe local browser assertion projection: no credentials, viewer IDs or click identifiers printed.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(base_path('..'));
$c = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
if (! app()->environment('local') || $c['root'] !== $root || $c['port'] !== 8205 || $c['db_port'] !== 5605 || DB::connection()->getDatabaseName() !== $c['db'] || config('acquisition.transport') !== 'disabled' || config('acquisition.external_enabled')) {
    throw new RuntimeException('Isolated 05 external-off only.');
}
$f = json_decode(file_get_contents($root.'/.runtime/analytics-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$links = array_column($f['links'], 'id');
$q = DB::table('acquisition_behaviors')->whereIn('link_id', $links);
$contexts = [];
foreach ($f['links'] as $platform => $link) {
    $s = DB::table('acquisition_sources')->where('link_id', $link['id'])->orderByDesc('captured_at')->first();
    $v = $s?->context_encrypted ? json_decode(Crypt::decryptString($s->context_encrypted), true, flags: JSON_THROW_ON_ERROR) : [];
    $contexts[$platform] = ['sources' => DB::table('acquisition_sources')->where('link_id', $link['id'])->count(), 'revoked' => $s?->revoked_at !== null, 'query_free' => ! isset(parse_url($v['page_url'] ?? '')['query']), 'has_fbc' => isset($v['fbc']), 'has_fbp' => isset($v['fbp']), 'has_ttclid' => isset($v['ttclid']), 'has_ttp' => isset($v['ttp']), 'synthetic_click' => $platform === 'meta' ? str_contains($v['fbc'] ?? '', 'LOCAL_BROWSER_META') : str_starts_with($v['ttclid'] ?? '', 'E.C.P.LOCAL_BROWSER_TIKTOK_')];
}
$counts = (clone $q)->select('event_name')->selectRaw('count(*) as count')->groupBy('event_name')->pluck('count', 'event_name')->all();
$watched = (clone $q)->whereIn('event_name', ['watch_progress', 'episode_completed'])->get(['properties'])->sum(fn ($r) => json_decode($r->properties, true)['watched_delta'] ?? 0);
echo json_encode(['enabled' => config('acquisition.analytics_enabled'), 'external_disabled' => config('acquisition.transport') === 'disabled' && ! config('acquisition.external_enabled'), 'contexts' => $contexts, 'events' => $counts, 'watched_seconds' => $watched, 'billing_counts' => array_map(fn ($t) => DB::table($t)->count(), ['billing_orders', 'billing_payments', 'billing_entitlements']), 'leaked_fields' => (clone $q)->whereRaw("properties::text ~ '(fbclid|ttclid|fbc|fbp|ttp|@|https?://)' ")->count()], JSON_THROW_ON_ERROR);
