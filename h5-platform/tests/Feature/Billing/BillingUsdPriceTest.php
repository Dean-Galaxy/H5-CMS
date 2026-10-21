<?php

namespace Tests\Feature\Billing;

use App\Data\CheckoutInput;
use App\Enums\Role;
use App\Modules\Billing\Services\BillingAdministration;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Identity\Models\Staff;
use App\Support\Admin\UsdAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BillingUsdPriceTest extends BillingTestCase
{
    public function test_decimal_strings_and_business_boundaries_save_exact_minor_units_and_reload(): void
    {
        $this->signIn(Role::Owner);
        $admin = app(BillingAdministration::class);
        $version = 1;
        foreach ([['12', '3', 1200, 300], ['12.3', '3.1', 1230, 310], ['12.30', '3.01', 1230, 301],
            ['0.01', '0.01', 1, 1], ['999999.99', '999999.99', 99999999, 99999999]] as [$regular, $first, $regularMinor, $firstMinor]) {
            $saved = $admin->savePriceUsd($this->price(['version' => $version, 'regular_amount_usd' => $regular, 'first_week_amount_usd' => $first]));
            $this->assertSame(++$version, $saved['version']);
            $this->assertSame($regularMinor, $saved['regular_amount_minor']);
            $this->assertSame($firstMinor, $saved['first_week_amount_minor']);
            $this->assertSame('USD', $saved['currency']);
            $this->assertTrue($saved['is_test']);
            $reloaded = $admin->currentPrice();
            $this->assertSame($saved['id'], $reloaded['id']);
            $this->assertSame($regularMinor, $reloaded['regular_amount_minor']);
            $this->assertSame($firstMinor, $reloaded['first_week_amount_minor']);
            $this->assertSame($regularMinor, UsdAmount::fromDecimal(UsdAmount::toDecimal($reloaded['regular_amount_minor'])));
            $this->assertSame($firstMinor, UsdAmount::fromDecimal(UsdAmount::toDecimal($reloaded['first_week_amount_minor'])));
        }
        $this->assertSame('999999.99', UsdAmount::toDecimal($admin->currentPrice()['regular_amount_minor']));
        $this->assertDatabaseCount('billing_prices', 6);
        $this->assertSame(5, DB::table('identity_audit_events')->where('action', 'billing.price.created')->count());
    }

    public function test_malformed_usd_and_non_string_types_map_errors_to_the_input_field_without_writes(): void
    {
        $this->signIn(Role::Owner);
        $invalid = ['', null, 12, 12.30, true, false, [], (object) ['amount' => '12.00'],
            ' 12.00', '12.00 ', "12.00\n", '+12', '$12', '1e2', '1,200.00', '12,50', '12.345', '.50', '12.', '１２.００', str_repeat('9', 40)];
        foreach (['regular_amount_usd', 'first_week_amount_usd'] as $field) {
            foreach ($invalid as $value) {
                $this->assertValidationFields([$field], $this->price([$field => $value]));
            }
        }
        $this->assertDatabaseCount('billing_prices', 1);
        $this->assertSame(0, DB::table('identity_audit_events')->where('action', 'billing.price.created')->count());
    }

    public function test_positive_price_range_and_test_acknowledgement_remain_module_rules(): void
    {
        $this->signIn(Role::Owner);
        foreach ([
            [['regular_amount_usd' => '0'], 'regular_amount_usd'],
            [['regular_amount_usd' => '-0.01'], 'regular_amount_usd'],
            [['regular_amount_usd' => '1000000.00'], 'regular_amount_usd'],
            [['first_week_amount_usd' => '0.00'], 'first_week_amount_usd'],
            [['first_week_amount_usd' => '-3.00'], 'first_week_amount_usd'],
            [['first_week_amount_usd' => '12.01'], 'first_week_amount_usd'],
            [['first_week_amount_usd' => '1000000.00'], 'first_week_amount_usd'],
            [['version' => -1], 'version'],
            [['is_test' => false], 'is_test'],
        ] as [$change, $field]) {
            $this->assertValidationFields([$field], $this->price($change));
        }
        $this->assertDatabaseCount('billing_prices', 1);
        $this->assertSame(0, DB::table('identity_audit_events')->where('action', 'billing.price.created')->count());
    }

    public function test_legacy_minor_fields_cannot_override_or_replace_usd_input(): void
    {
        $this->signIn(Role::Owner);
        $admin = app(BillingAdministration::class);
        $saved = $admin->savePriceUsd($this->price([
            'regular_amount_usd' => '12.34', 'first_week_amount_usd' => '3.21',
            'regular_amount_minor' => 1, 'first_week_amount_minor' => 99999999, 'currency' => 'EUR', 'id' => BillingStore::id(),
        ]));
        $this->assertSame(1234, $saved['regular_amount_minor']);
        $this->assertSame(321, $saved['first_week_amount_minor']);
        $this->assertSame('USD', $saved['currency']);
        $this->assertValidationFields(['regular_amount_usd', 'first_week_amount_usd'], [
            'version' => 2, 'regular_amount_minor' => 1500, 'first_week_amount_minor' => 500, 'is_test' => true,
        ]);
        $this->assertDatabaseCount('billing_prices', 2);
        $this->assertSame($saved['id'], $admin->currentPrice()['id']);
    }

    public function test_stale_usd_submission_cannot_overwrite_a_newer_price_version(): void
    {
        $this->signIn(Role::Owner);
        $admin = app(BillingAdministration::class);
        $before = (array) DB::table('billing_prices')->where('version', 1)->first();
        $saved = $admin->savePriceUsd($this->price(['regular_amount_usd' => '15.00', 'first_week_amount_usd' => '5.00']));
        $this->assertProblem('STALE_VERSION', fn () => $admin->savePriceUsd($this->price(['regular_amount_usd' => '18.00'])));
        $this->assertSame((array) DB::table('billing_prices')->where('version', 1)->first(), $before);
        $this->assertSame($saved['id'], $admin->currentPrice()['id']);
        $this->assertDatabaseCount('billing_prices', 2);
        $this->assertSame(1, DB::table('identity_audit_events')->where('action', 'billing.price.created')->count());
    }

    public function test_staff_permissions_and_mfa_are_checked_before_parsing_usd(): void
    {
        $admin = app(BillingAdministration::class);
        foreach ([Role::Support, Role::ContentOperator, Role::Acquisition] as $role) {
            $this->signIn($role);
            $this->assertProblem('NOT_AUTHORIZED', fn () => $admin->savePriceUsd(['regular_amount_usd' => []]));
        }
        $this->signIn(Role::Owner);
        request()->session()->forget('identity.mfa_staff');
        $this->assertProblem('MFA_REQUIRED', fn () => $admin->savePriceUsd(['regular_amount_usd' => []]));
        $this->assertDatabaseCount('billing_prices', 1);
        $this->assertSame(0, DB::table('identity_audit_events')->where('action', 'billing.price.created')->count());
    }

    public function test_usd_price_change_preserves_existing_offer_checkout_intent_and_subscription_snapshot(): void
    {
        $this->signIn(Role::Owner);
        $viewer = $this->viewer();
        $offer = $this->engine->offer($viewer);
        $offerBefore = DB::table('billing_offers')->find($offer->offer_id);
        $pending = $this->checkoutFor($this->viewer());
        $subscriptionBefore = DB::table('billing_subscriptions')->find($pending->subscription_id);
        $createCalls = $this->gateway->createCalls;
        app(BillingAdministration::class)->savePriceUsd($this->price(['regular_amount_usd' => '15.25', 'first_week_amount_usd' => '4.05']));
        $this->assertSame((array) $offerBefore, (array) DB::table('billing_offers')->find($offer->offer_id));
        $this->assertSame((array) $pending, (array) DB::table('billing_orders')->find($pending->id));
        $this->assertSame((array) $subscriptionBefore, (array) DB::table('billing_subscriptions')->find($pending->subscription_id));
        $this->assertSame($createCalls, $this->gateway->createCalls);
        $fresh = $this->engine->offer($viewer);
        $this->assertSame(2, $fresh->price_version);
        $this->assertSame(1525, $fresh->regular_amount_minor);
        $this->assertSame(405, $fresh->first_week_amount_minor);
        $checkout = $this->engine->checkout($viewer, new CheckoutInput($offer->offer_id, $offer->price_version, '/membership'), BillingStore::id(), null);
        $this->assertDatabaseHas('billing_orders', ['id' => $checkout->order_id, 'price_version' => 1, 'amount_minor' => 300, 'regular_amount_minor' => 1200]);
    }

    public function test_usd_price_change_preserves_paid_facts_and_original_resume_quote(): void
    {
        $this->signIn(Role::Owner);
        [$viewer, $order, $sub] = $this->paidFixture();
        $this->engine->cancel($viewer);
        $subBefore = DB::table('billing_subscriptions')->find($sub->id);
        $paymentBefore = DB::table('billing_payments')->where('order_id', $order->id)->first();
        $entitlementBefore = DB::table('billing_entitlements')->where('payment_id', $paymentBefore->id)->first();
        $outboxBefore = DB::table('billing_outbox')->where('payment_id', $paymentBefore->id)->first();
        $renewalCalls = $this->gateway->renewalCalls;
        app(BillingAdministration::class)->savePriceUsd($this->price(['regular_amount_usd' => '15.00', 'first_week_amount_usd' => '5.00']));
        $this->assertSame((array) $subBefore, (array) DB::table('billing_subscriptions')->find($sub->id));
        $this->assertSame((array) $paymentBefore, (array) DB::table('billing_payments')->find($paymentBefore->id));
        $this->assertSame((array) $entitlementBefore, (array) DB::table('billing_entitlements')->find($entitlementBefore->id));
        $this->assertSame((array) $outboxBefore, (array) DB::table('billing_outbox')->find($outboxBefore->id));
        $quote = $this->engine->resumeQuote($viewer);
        $this->assertSame(1200, $quote->next_renewal_amount_minor);
        $this->assertSame(BillingStore::iso($subBefore->period_end), $quote->next_renewal_at);
        $this->assertFalse($this->engine->offer($viewer)->eligible_for_first_offer);
        $this->assertSame($renewalCalls, $this->gateway->renewalCalls);
    }

    private function signIn(Role $role): void
    {
        $staff = Staff::create(['name' => 'Billing USD test staff', 'email' => BillingStore::id().'@example.test', 'password' => 'TestPassword1234', 'role' => $role]);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->refresh();
        $this->actingAs($staff, 'staff')->withSession(['identity.version.staff' => $staff->session_version, 'identity.mfa_staff' => $staff->id]);
        request()->setLaravelSession(app('session.store'));
    }

    private function price(array $changes = []): array
    {
        return array_replace(['version' => 1, 'regular_amount_usd' => '12.00', 'first_week_amount_usd' => '3.00', 'is_test' => true], $changes);
    }

    private function assertValidationFields(array $fields, array $input): void
    {
        try {
            app(BillingAdministration::class)->savePriceUsd($input);
            $this->fail('Invalid dollar price must not be persisted.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            foreach ($fields as $field) {
                $this->assertArrayHasKey($field, $errors);
                $this->assertMatchesRegularExpression('/[\\x{4e00}-\\x{9fff}]/u', $errors[$field][0]);
            }
            $this->assertArrayNotHasKey('regular_amount_minor', $errors);
            $this->assertArrayNotHasKey('first_week_amount_minor', $errors);
        }
    }
}
