<?php

namespace Tests\Integration\Support;

use App\Enums\Role;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Models\Plan;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\DB;

/** A testing-only recovery manifest; no lookup may choose an arbitrary existing account. */
final class RecoveryScenario
{
    public static function prepareUnpaidAccounts(): array
    {
        self::isolated();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $viewer = Viewer::forceCreate(['id' => BillingStore::id(), 'name' => '06 preexisting unpaid fixture',
                'email' => BillingStore::id().'@example.test', 'password' => bcrypt(bin2hex(random_bytes(24))),
                'active' => true, 'email_verified_at' => now()]);
            DB::transaction(fn () => BillingStore::lock($viewer->id));
            $ids[] = $viewer->id;
        }

        return $ids;
    }

    public static function seed(): array
    {
        self::isolated();
        self::require(! DB::table('billing_payments')->exists(), 'Refuse existing payment data.');
        $unrelatedIds = DB::table('billing_accounts')->orderBy('viewer_id')->pluck('viewer_id')->all();
        $unrelated = self::unrelatedSnapshot($unrelatedIds);
        $p = PaymentScenario::paid();
        $refundId = 're_restore06_'.$p['order']->id;
        $p['gateway']->refunds[$p['charge']] = [['id' => $refundId, 'amount_minor' => 100, 'status' => 'succeeded', 'currency' => 'USD']];
        app(SettlementService::class)->syncRefunds($p['charge']);
        $payment = DB::table('billing_payments')->where('order_id', $p['order']->id)->where('viewer_id', $p['viewer']->id)->sole();
        $entitlement = DB::table('billing_entitlements')->where('payment_id', $payment->id)->where('viewer_id', $p['viewer']->id)->sole();
        $outbox = DB::table('billing_outbox')->where('payment_id', $payment->id)->sole();

        $staff = Staff::create(['name' => '06 recovery fixture owner', 'email' => BillingStore::id().'@example.test',
            'password' => bin2hex(random_bytes(24)), 'role' => Role::Owner]);
        $secret = app(AppAuthentication::class)->generateSecret();
        $staff->saveAppAuthenticationSecret($secret);
        $drama = DramaRecord::create(['created_by' => $staff->id, 'title_en' => '06 overdue recovery fixture', 'title_zh' => '',
            'synopsis_en' => '', 'synopsis_zh' => '', 'regions' => ['US'], 'rights_note' => 'Testing only; never publish automatically', 'state' => 'draft']);
        $plan = Plan::create(['drama_id' => $drama->id, 'state' => 'active', 'mode' => 'updating', 'version' => 1,
            'launch_at' => now()->subMinutes(5)->startOfMinute(), 'next_at' => now()->subMinutes(5)->startOfMinute()]);
        $manifest = ['schema' => 1, 'targets' => [
            'viewer_id' => $p['viewer']->id, 'offer_id' => $p['order']->offer_id, 'order_id' => $p['order']->id,
            'subscription_id' => $p['sub']->id, 'payment_id' => $payment->id, 'entitlement_id' => $entitlement->id,
            'refund_id' => $refundId, 'outbox_id' => $outbox->id, 'staff_id' => $staff->id, 'drama_id' => $drama->id, 'plan_id' => $plan->id,
        ], 'mfa_secret_sha256' => hash('sha256', $secret), 'unrelated_viewer_ids' => $unrelatedIds, 'unrelated_snapshot' => $unrelated];

        return ['manifest' => $manifest, 'summary' => self::verify($manifest)];
    }

    public static function verify(array $manifest): array
    {
        self::isolated();
        self::require(($manifest['schema'] ?? null) === 1 && is_array($manifest['targets'] ?? null)
            && is_array($manifest['unrelated_viewer_ids'] ?? null) && is_array($manifest['unrelated_snapshot'] ?? null)
            && is_string($manifest['mfa_secret_sha256'] ?? null), 'Recovery manifest required.');
        $ids = $manifest['targets'];
        foreach (['viewer_id', 'offer_id', 'order_id', 'subscription_id', 'payment_id', 'entitlement_id', 'refund_id', 'outbox_id', 'staff_id', 'drama_id', 'plan_id'] as $key) {
            self::require(is_string($ids[$key] ?? null) && $ids[$key] !== '', 'Missing recovery target: '.$key);
        }
        self::require(! in_array($ids['viewer_id'], $manifest['unrelated_viewer_ids'], true), 'Recovery target is a preexisting unrelated viewer.');
        self::row('identity_viewers', $ids['viewer_id']);
        self::row('billing_accounts', $ids['viewer_id'], 'viewer_id');
        $offer = self::row('billing_offers', $ids['offer_id']);
        $order = self::row('billing_orders', $ids['order_id']);
        $subscription = self::row('billing_subscriptions', $ids['subscription_id']);
        $payment = self::row('billing_payments', $ids['payment_id']);
        $entitlement = self::row('billing_entitlements', $ids['entitlement_id']);
        $refund = self::row('billing_refunds', $ids['refund_id'], 'provider_refund_id');
        $outbox = self::row('billing_outbox', $ids['outbox_id']);
        foreach (['offer' => $offer, 'order' => $order, 'subscription' => $subscription, 'payment' => $payment, 'entitlement' => $entitlement] as $label => $row) {
            self::matches($row, ['viewer_id' => $ids['viewer_id']], $label);
        }
        self::matches($order, ['subscription_id' => $ids['subscription_id'], 'offer_id' => $ids['offer_id']], 'order');
        self::matches($subscription, ['first_order_id' => $ids['order_id']], 'subscription');
        self::matches($payment, ['order_id' => $ids['order_id'], 'subscription_id' => $ids['subscription_id'],
            'amount_minor' => 300, 'refunded_amount_minor' => 100, 'currency' => 'USD', 'is_test' => true], 'payment');
        self::matches($entitlement, ['subscription_id' => $ids['subscription_id'], 'payment_id' => $ids['payment_id'], 'revoked_at' => null], 'entitlement');
        self::matches($refund, ['payment_id' => $ids['payment_id'], 'amount_minor' => 100, 'status' => 'succeeded'], 'refund');
        self::matches($outbox, ['payment_id' => $ids['payment_id']], 'outbox');
        $staff = Staff::findOrFail($ids['staff_id']);
        $drama = self::row('content_dramas', $ids['drama_id']);
        $plan = self::row('content_plans', $ids['plan_id']);
        self::matches($drama, ['created_by' => $ids['staff_id'], 'state' => 'draft'], 'drama');
        self::matches($plan, ['drama_id' => $ids['drama_id']], 'plan');
        $secret = $staff->getAppAuthenticationSecret();
        self::require(filled($secret) && hash_equals($manifest['mfa_secret_sha256'], hash('sha256', $secret)), 'Recovery target MFA mismatch.');
        self::require(self::unrelatedSnapshot($manifest['unrelated_viewer_ids']) === $manifest['unrelated_snapshot'], 'Unrelated recovery accounts changed.');
        $membership = app(BillingEngine::class)->membership($ids['viewer_id'], now()->toDateTimeImmutable());

        return ['contract' => config('h5.contract'), 'targets' => $ids,
            'orders' => DB::table('billing_orders')->where('viewer_id', $ids['viewer_id'])->count(),
            'payments' => DB::table('billing_payments')->where('viewer_id', $ids['viewer_id'])->count(),
            'entitlements' => DB::table('billing_entitlements')->where('viewer_id', $ids['viewer_id'])->count(),
            'refunds' => DB::table('billing_refunds')->where('payment_id', $ids['payment_id'])->count(),
            'outbox' => DB::table('billing_outbox')->where('payment_id', $ids['payment_id'])->count(),
            'access_active' => $membership->access_active, 'renewal_enabled' => $membership->renewal_enabled,
            'public_episodes' => DB::table('content_episodes')->whereNotNull('published_at')->count(),
            'publications' => DB::table('content_publications')->count(),
            'published_publications' => DB::table('content_publications')->where('result', 'published')->count(),
            'target_publication_results' => DB::table('content_publications')->where('plan_id', $ids['plan_id'])->orderBy('id')->pluck('result')->all(),
            'target_plan_state' => $plan->state, 'target_drama_state' => $drama->state, 'mfa_ciphertext_decrypts' => true,
            'unrelated_accounts' => count($manifest['unrelated_viewer_ids']), 'unrelated_accounts_unchanged' => true,
            'unrelated_snapshot_sha256' => hash('sha256', json_encode($manifest['unrelated_snapshot'], JSON_THROW_ON_ERROR))];
    }

    private static function unrelatedSnapshot(array $ids): array
    {
        $tables = ['identity_viewers' => DB::table('identity_viewers')->whereIn('id', $ids)];
        foreach (['billing_accounts', 'billing_offers', 'billing_subscriptions', 'billing_orders', 'billing_payments',
            'billing_entitlements', 'billing_resume_quotes', 'billing_operations', 'billing_cases'] as $table) {
            $tables[$table] = DB::table($table)->whereIn('viewer_id', $ids);
        }
        $payments = DB::table('billing_payments')->whereIn('viewer_id', $ids)->pluck('id')->all();
        foreach (['billing_refunds', 'billing_outbox'] as $table) {
            $tables[$table] = DB::table($table)->whereIn('payment_id', $payments);
        }
        $tables['billing_notes'] = DB::table('billing_notes')->whereIn('case_id', DB::table('billing_cases')->whereIn('viewer_id', $ids)->pluck('id')->all());
        $result = [];
        foreach ($tables as $table => $query) {
            $rows = $query->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $result[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
        }

        return $result;
    }

    private static function row(string $table, string $id, string $key = 'id'): object
    {
        return DB::table($table)->where($key, $id)->sole();
    }

    private static function matches(object $row, array $expected, string $label): void
    {
        foreach ($expected as $field => $value) {
            self::require($value === $row->$field, 'Recovery target mismatch: '.$label.'.'.$field);
        }
    }

    private static function isolated(): void
    {
        $db = config('database.connections.pgsql.database');
        self::require(app()->environment('testing') && is_string($db) && str_ends_with($db, '_test')
            && $db === getenv('H5_TEST_DATABASE'), '06 recovery scenario requires an explicitly isolated test database.');
    }

    private static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \LogicException($message);
        }
    }
}
