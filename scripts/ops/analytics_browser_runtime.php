<?php
/** Read loaded local runtime flags only; no database/provider query. Run before opening the browser. */
$root = dirname(__DIR__, 2);
require $root.'/h5-platform/vendor/autoload.php';
$app = require $root.'/h5-platform/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
if ($c['root'] !== $root || ! $app->environment('local', 'testing')
    || ! in_array(config('database.connections.pgsql.database'), [$c['db'], $c['db'].'_test'], true)
    || (string) config('database.connections.pgsql.port') !== (string) $c['db_port']
    || config('database.connections.pgsql.host') !== '127.0.0.1'
    || config('billing.paypal.enabled') !== false || config('billing.new_purchase_provider') !== 'unconfigured'
    || config('acquisition.external_enabled') !== false || config('acquisition.transport') !== 'disabled') {
    throw new LogicException('06 browser requires own local runtime with disabled payment/ad transports.');
}
echo json_encode(['runtime_safe' => true, 'application_environment' => $app->environment(),
    'external_enabled' => false, 'purchase_enabled' => false], JSON_THROW_ON_ERROR)."\n";
