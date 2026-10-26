<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\PayPal\SandboxSubscriptionRequest;
use App\Modules\Billing\Gateway\PayPal\SandboxWeeklyPlan;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\PayPalCheckout;
use App\Modules\Billing\Services\PayPalPlanProvisioner;
use App\Modules\Billing\Services\ProviderOperationStore;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Billing\Support\ProviderLedgerFixture;
use Tests\TestCase;

/** All HTTP is a MockHandler fixture; no official payment, approval or sandbox asset. */
final class PayPalCheckoutTest extends TestCase
{
    use DatabaseMigrations;

    private const PLAN = 'P-ABCDEFGHIJKLMNOPQRSTUVWX';

    private const PRODUCT = 'PROD-ABCDEFGHIJKLMNOPQ';

    private const APPROVE = 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-LOCAL';

    private array $history = [];

    private ProviderScope $scope;

    private ProviderOperationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://127.0.0.1:8303', 'billing.paypal' => ['enabled' => true, 'client_id' => 'local-fixture-client',
            'client_secret' => 'local-fixture-secret', 'merchant_id' => 'LOCAL-MERCHANT', 'merchant_scope' => 'local-fixture', 'webhook_id' => 'LOCAL-WEBHOOK']]);
        $this->scope = new ProviderScope('paypal', 'sandbox', 'local-fixture');
        $this->store = new ProviderOperationStore;
    }

    protected function tearDown(): void
    {
        DB::table('billing_provider_resources')->delete();
        DB::table('billing_provider_intents')->delete();
        foreach (['billing_orders', 'billing_subscriptions'] as $table) {
            DB::table($table)->where('provider', 'paypal')->delete();
        }
        parent::tearDown();
    }

    public function test_server_order_preparation_and_remote_reread_never_grant_membership(): void
    {
        [$order, $owner] = $this->fixture();
        $api = $this->api([$this->token(), $this->reply($this->plan()), $this->reply(['id' => 'I-LOCAL'], 201), $this->reply($this->subscription($order))]);
        $flow = new PayPalCheckout($api, $this->store);
        $flow->prepare($order->id, $owner);
        self::assertCount(0, $this->history);
        $prepared = DB::table('billing_orders')->find($order->id);
        self::assertNull($prepared->expires_at);
        $params = BillingStore::json($prepared->checkout_request)['params'];
        self::assertSame($order->id, $params['custom_id']);
        self::assertSame('http://127.0.0.1:8303/identity/account?order_id='.$order->id, $params['application_context']['return_url']);
        self::assertArrayNotHasKey('plan', $params);
        self::assertArrayNotHasKey('subscriber', $params);
        self::assertArrayNotHasKey('start_time', $params);
        self::assertSame(self::APPROVE, $flow->execute($order->id));
        self::assertEquals($params, json_decode((string) $this->history[2]['request']->getBody(), true));
        $intent = DB::table('billing_provider_intents')->where('operation', 'create_subscription')->first();
        self::assertSame($intent->request_key, $this->history[2]['request']->getHeaderLine('PayPal-Request-Id'));
        self::assertSame('succeeded', $intent->state);
        self::assertSame('I-LOCAL', DB::table('billing_subscriptions')->find($order->subscription_id)->provider_id);
        self::assertTrue(DB::table('billing_subscriptions')->find($order->subscription_id)->resolution_required);
        self::assertDatabaseCount('billing_payments', 0);
        self::assertDatabaseCount('billing_entitlements', 0);
        self::assertDatabaseCount('billing_outbox', 0);
    }

    public function test_timeout_retries_original_request_and_key_without_new_intent(): void
    {
        [$order, $owner] = $this->fixture();
        $api = $this->api([$this->token(), $this->reply($this->plan()), $this->timeout(),
            $this->reply($this->plan()), $this->reply(['id' => 'I-LOCAL'], 201), $this->reply($this->subscription($order))]);
        $flow = new PayPalCheckout($api, $this->store);
        $flow->prepare($order->id, $owner);
        $this->failure(fn () => $flow->execute($order->id));
        self::assertSame('unknown', DB::table('billing_provider_intents')->where('operation', 'create_subscription')->first()->state);
        self::assertSame(self::APPROVE, $flow->execute($order->id));
        self::assertSame((string) $this->history[2]['request']->getBody(), (string) $this->history[4]['request']->getBody());
        self::assertSame($this->history[2]['request']->getHeaderLine('PayPal-Request-Id'), $this->history[4]['request']->getHeaderLine('PayPal-Request-Id'));
        self::assertSame(1, DB::table('billing_provider_intents')->where('operation', 'create_subscription')->count());
    }

    public function test_returned_identity_survives_followup_failure_and_reentry_only_reads_original(): void
    {
        [$order, $owner] = $this->fixture();
        $api = $this->api([$this->token(), $this->reply($this->plan()), $this->reply(['id' => 'I-LOCAL'], 201), $this->timeout(),
            $this->reply($this->plan()), $this->reply($this->subscription($order))]);
        $flow = new PayPalCheckout($api, $this->store);
        $flow->prepare($order->id, $owner);
        self::assertNull($flow->execute($order->id));
        self::assertSame('I-LOCAL', DB::table('billing_orders')->find($order->id)->checkout_id);
        self::assertSame(self::APPROVE, $flow->execute($order->id));
        self::assertSame(['POST', 'GET', 'POST', 'GET', 'GET', 'GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
    }

    public function test_unknown_creation_after_local_deadline_keeps_occupancy_and_never_posts_again(): void
    {
        [$order, $owner] = $this->fixture();
        $api = $this->api([$this->token(), $this->reply($this->plan()), $this->timeout()]);
        $flow = new PayPalCheckout($api, $this->store);
        $flow->prepare($order->id, $owner);
        $this->failure(fn () => $flow->execute($order->id));
        $this->travel(31)->minutes();
        $this->failure(fn () => $flow->execute($order->id));
        self::assertCount(3, $this->history);
        self::assertNull(DB::table('billing_orders')->find($order->id)->expires_at);
        self::assertSame('pending', DB::table('billing_subscriptions')->find($order->subscription_id)->state);
        self::assertTrue(DB::table('billing_subscriptions')->find($order->subscription_id)->resolution_required);
    }

    public function test_old_approval_link_is_removed_after_local_deadline_without_claiming_platform_expiry(): void
    {
        [$order, $owner] = $this->fixture();
        $api = $this->api([$this->token(), $this->reply($this->plan()), $this->reply(['id' => 'I-LOCAL']), $this->reply($this->subscription($order)),
            $this->reply($this->plan()), $this->reply($this->subscription($order))]);
        $flow = new PayPalCheckout($api, $this->store);
        $flow->prepare($order->id, $owner);
        self::assertSame(self::APPROVE, $flow->execute($order->id));
        $this->travel(31)->minutes();
        self::assertNull($flow->execute($order->id));
        $row = DB::table('billing_orders')->find($order->id);
        self::assertNull($row->checkout_url);
        self::assertNull($row->expires_at);
        self::assertSame('pending', $row->state);
    }

    #[DataProvider('invalidPlans')]
    public function test_changed_plan_is_rejected_before_creating_subscription(string $path, mixed $value): void
    {
        [$order, $owner] = $this->fixture();
        $plan = $this->plan();
        data_set($plan, $path, $value);
        $api = $this->api([$this->token(), $this->reply($plan)]);
        $flow = new PayPalCheckout($api, $this->store);
        $flow->prepare($order->id, $owner);
        $this->failure(fn () => $flow->execute($order->id));
        self::assertCount(2, $this->history);
        self::assertNull(DB::table('billing_orders')->find($order->id)->checkout_id);
    }

    public static function invalidPlans(): array
    {
        return [['billing_cycles.1.pricing_scheme.fixed_price.value', '15.00'], ['billing_cycles.0.frequency.interval_unit', 'DAY'],
            ['payment_preferences.auto_bill_outstanding', true], ['payment_preferences.setup_fee', ['value' => '1.00', 'currency_code' => 'USD']],
            ['taxes', ['percentage' => '5', 'inclusive' => false]], ['quantity_supported', true], ['status', 'INACTIVE']];
    }

    #[DataProvider('invalidSubscriptions')]
    public function test_mismatched_server_subscription_has_no_link_but_preserves_identity(string $path, mixed $value): void
    {
        [$order, $owner] = $this->fixture();
        $sub = $this->subscription($order);
        data_set($sub, $path, $value);
        $api = $this->api([$this->token(), $this->reply($this->plan()), $this->reply(['id' => 'I-LOCAL']), $this->reply($sub)]);
        $flow = new PayPalCheckout($api, $this->store);
        $flow->prepare($order->id, $owner);
        self::assertNull($flow->execute($order->id));
        self::assertSame('I-LOCAL', DB::table('billing_orders')->find($order->id)->checkout_id);
        self::assertDatabaseCount('billing_payments', 0);
    }

    public static function invalidSubscriptions(): array
    {
        return [['custom_id', 'other-order'], ['plan_id', 'P-OTHER'], ['plan_overridden', true], ['quantity', '2'], ['id', 'I-OTHER'],
            ['status', 'ACTIVE'], ['links.0.href', 'https://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-LIVE'],
            ['links.0.href', self::APPROVE.'&redirect=https://attacker.test']];
    }

    public function test_cancel_between_preparation_and_dispatch_blocks_post(): void
    {
        [$order, $owner] = $this->fixture();
        $api = $this->api([$this->token(), $this->reply($this->plan())]);
        $flow = new PayPalCheckout($api, $this->store);
        $flow->prepare($order->id, $owner);
        DB::table('billing_subscriptions')->where('id', $order->subscription_id)->update(['desired_renewal' => false]);
        $this->failure(fn () => $flow->execute($order->id));
        self::assertCount(2, $this->history);
    }

    public function test_plan_provisioning_preserves_id_on_read_failure_and_never_updates_old_price(): void
    {
        $owner = BillingStore::id();
        $api = $this->api([$this->token(), $this->reply(['id' => self::PLAN], 201), $this->timeout(), $this->reply($this->plan())]);
        $provisioner = new PayPalPlanProvisioner($api, $this->store);
        $this->failure(fn () => $provisioner->ensure($this->scope, $owner, new SandboxWeeklyPlan(1, 1200, 300, true), self::PRODUCT));
        self::assertDatabaseCount('billing_provider_resources', 0);
        self::assertSame(self::PLAN, $provisioner->ensure($this->scope, $owner, new SandboxWeeklyPlan(1, 1200, 300, true), self::PRODUCT));
        $this->failure(fn () => $provisioner->ensure($this->scope, $owner, new SandboxWeeklyPlan(2, 1500, 300, true), self::PRODUCT));
        self::assertCount(4, $this->history);
        self::assertSame('GET', $this->history[3]['request']->getMethod());
    }

    public function test_configuration_switch_after_create_preserves_identity_but_blocks_followup(): void
    {
        [$order, $owner] = $this->fixture();
        $api = $this->api([$this->token(), $this->reply($this->plan()), function () {
            config(['billing.paypal.merchant_scope' => 'different-merchant']);

            return $this->reply(['id' => 'I-LOCAL'], 201);
        }]);
        $flow = new PayPalCheckout($api, $this->store);
        $flow->prepare($order->id, $owner);
        self::assertNull($flow->execute($order->id));
        self::assertSame('I-LOCAL', DB::table('billing_orders')->find($order->id)->checkout_id);
        self::assertCount(3, $this->history);
    }

    public function test_unknown_or_other_merchant_plan_cannot_be_prepared(): void
    {
        [$order] = $this->fixture();
        $flow = new PayPalCheckout($this->api([]), $this->store);
        $this->failure(fn () => $flow->prepare($order->id, BillingStore::id()));
        self::assertCount(0, $this->history);
        self::assertSame(0, DB::table('billing_provider_intents')->where('operation', 'create_subscription')->count());
    }

    public function test_only_single_exact_sandbox_approval_relation_is_accepted(): void
    {
        $valid = ['status' => 'APPROVAL_PENDING', 'links' => [['rel' => 'approve', 'method' => 'GET', 'href' => self::APPROVE]]];
        self::assertSame(self::APPROVE, SandboxSubscriptionRequest::approvalUrl($valid));
        $valid['links'][] = $valid['links'][0];
        self::assertNull(SandboxSubscriptionRequest::approvalUrl($valid));
    }

    public function test_prepared_request_tampering_is_rejected_before_http(): void
    {
        [$order, $owner] = $this->fixture();
        $flow = new PayPalCheckout($this->api([]), $this->store);
        $flow->prepare($order->id, $owner);
        $stored = DB::table('billing_orders')->find($order->id);
        $payload = BillingStore::json($stored->checkout_request);
        $payload['params']['plan'] = ['billing_cycles' => [['pricing_scheme' => ['fixed_price' => ['value' => '0.01', 'currency_code' => 'USD']]]]];
        DB::table('billing_orders')->where('id', $order->id)->update(['checkout_request' => json_encode($payload)]);
        $this->failure(fn () => $flow->execute($order->id));
        self::assertCount(0, $this->history);
    }

    public function test_repreparing_the_same_order_does_not_extend_approval_window(): void
    {
        [$order, $owner] = $this->fixture();
        $flow = new PayPalCheckout($this->api([]), $this->store);
        $flow->prepare($order->id, $owner);
        $original = DB::table('billing_orders')->find($order->id)->checkout_prepared_at;
        $this->travel(32)->minutes();
        $flow->prepare($order->id, $owner);
        self::assertSame($original, DB::table('billing_orders')->find($order->id)->checkout_prepared_at);
        $this->failure(fn () => $flow->execute($order->id));
        self::assertCount(0, $this->history);
    }

    private function fixture(): array
    {
        [, $order] = ProviderLedgerFixture::create($this->scope);
        DB::table('billing_subscriptions')->where('id', $order->subscription_id)->update(['provider_id' => null, 'customer_id' => null]);
        DB::table('billing_orders')->where('id', $order->id)->update(['return_to' => '/account?order_id=untrusted']);
        $owner = BillingStore::id();
        $intent = $this->store->reserve($this->scope, 'create_plan', $owner, 1, (new SandboxWeeklyPlan(1, 1200, 300, true))->createRequest(self::PRODUCT));
        $this->store->beginAttempt($this->scope, $intent->id);
        $this->store->complete($this->scope, $intent->id, self::PLAN);
        $this->store->link($this->scope, 'plan', self::PLAN, 'plan', $owner);

        return [DB::table('billing_orders')->find($order->id), $owner];
    }

    private function plan(): array
    {
        return ['id' => self::PLAN] + (new SandboxWeeklyPlan(1, 1200, 300, true))->createRequest(self::PRODUCT);
    }

    private function subscription(object $order): array
    {
        return ['id' => 'I-LOCAL', 'plan_id' => self::PLAN, 'custom_id' => $order->id, 'plan_overridden' => false, 'quantity' => '1',
            'status' => 'APPROVAL_PENDING', 'links' => [['rel' => 'approve', 'method' => 'GET', 'href' => self::APPROVE]]];
    }

    private function api(array $responses): SandboxApi
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($this->history));

        return new SandboxApi(new Client(['handler' => $handler]));
    }

    private function reply(array $value, int $status = 200): Response
    {
        return new Response($status, [], json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function token(): Response
    {
        return $this->reply(['access_token' => 'local-fixture-token', 'token_type' => 'Bearer', 'expires_in' => 3600]);
    }

    private function timeout(): ConnectException
    {
        return new ConnectException('local simulated unknown outcome', new Request('POST', SandboxApi::ORIGIN));
    }

    private function failure(callable $action): void
    {
        try {
            $action();
            self::fail('Expected fail closed');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }
}
