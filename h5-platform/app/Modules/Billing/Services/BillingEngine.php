<?php

namespace App\Modules\Billing\Services;

use App\Contracts\AuditRecorder;
use App\Contracts\BillingService;
use App\Contracts\IdentityContext;
use App\Contracts\MembershipReader;
use App\Contracts\RegionResolver;
use App\Data\AttributionSnapshot;
use App\Data\Checkout;
use App\Data\CheckoutInput;
use App\Data\Membership;
use App\Data\Offer;
use App\Data\Order;
use App\Data\ResourceScope;
use App\Data\ResumeInput;
use App\Data\ResumeQuote;
use App\Data\StaffIdentity;
use App\Data\ViewerIdentity;
use App\Enums\OrderState;
use App\Enums\PaymentState;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionState;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\ProviderGatewayRouter;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Support\CheckoutSafety;
use App\Modules\Identity\Services\SafeReturn;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class BillingEngine implements BillingService, MembershipReader
{
    public function __construct(private PaymentGateway $gateway) {}

    public function offer(?ViewerIdentity $viewer): Offer
    {
        $price = DB::table('billing_prices')->orderByDesc('version')->first() ?? throw new DependencyNotReady('Billing price');
        $account = $viewer ? DB::table('billing_accounts')->where('viewer_id', $viewer->id)->first() : null;
        $eligible = $viewer ? $account?->first_paid_at === null : null;
        $id = BillingStore::id();
        $expires = now()->addSeconds(config('billing.offer_ttl_seconds'));
        DB::table('billing_offers')->insert(['id' => $id, 'viewer_id' => $viewer?->id, 'price_version' => $price->version,
            'regular_amount_minor' => $price->regular_amount_minor, 'first_week_amount_minor' => $price->first_week_amount_minor,
            'currency' => $price->currency, 'is_test' => $price->is_test, 'eligible_first_offer' => $eligible, 'expires_at' => $expires, 'created_at' => now()]);
        $account = $viewer ? DB::table('billing_accounts')->where('viewer_id', $viewer->id)->first() : null;

        return new Offer($id, $price->version, $price->currency, $price->regular_amount_minor, $price->first_week_amount_minor,
            $viewer ? $account?->first_paid_at === null : null, BillingStore::iso($expires), $price->regular_amount_minor);
    }

    public function checkout(ViewerIdentity $viewer, CheckoutInput $input, string $idempotencyKey, ?AttributionSnapshot $source): Checkout
    {
        if (! $viewer->emailVerified) {
            throw new ApiProblem('EMAIL_UNVERIFIED', 403, '首次付款前请先验证邮箱');
        }
        Validator::make(['offer_id' => $input->offer_id, 'price_version' => $input->price_version, 'key' => $idempotencyKey, 'return_to' => $input->return_to],
            ['offer_id' => 'required|uuid', 'price_version' => 'required|integer|min:1', 'key' => 'required|string|min:16|max:128', 'return_to' => 'required|string|max:1024'])->validate();
        $region = app(RegionResolver::class)->resolve();
        if ($region->country_code !== 'US'
            || (! app()->environment(['local', 'testing']) && $region->source !== 'trusted')) {
            throw new ApiProblem('REGION_BLOCKED', 403, '当前地区尚未开放购买');
        }
        if (! config('billing.quote_policy_approved')) {
            throw new DependencyNotReady('Billing quote policy D02');
        }
        $priorOrder = DB::table('billing_orders')->where('viewer_id', $viewer->id)->where('idempotency_key', 'client:'.$idempotencyKey)->first();
        $provider = $priorOrder?->provider ?? config('billing.new_purchase_provider', 'unconfigured');
        if ($priorOrder && $provider === 'paypal') {
            $purchaseScope = ProviderScope::fromRecord($priorOrder);
            app(SandboxApi::class)->configurationStamp($purchaseScope);
        } elseif ($provider === 'paypal') {
            $purchaseScope = app(PayPalRuntime::class)->assertPurchaseReady();
        } else {
            if ($provider === 'unconfigured') {
                throw new DependencyNotReady('Billing / new purchase provider');
            }
            $this->gateway->assertAvailable();
            $purchaseScope = ProviderScope::legacyStripe();
        }
        $idempotencyKey = 'client:'.$idempotencyKey;
        $return = app(SafeReturn::class)->path($input->return_to);
        $fingerprint = hash('sha256', json_encode([$input->offer_id, $input->price_version, $return], JSON_THROW_ON_ERROR));
        $order = DB::transaction(function () use ($viewer, $input, $idempotencyKey, $source, $return, $fingerprint, $purchaseScope) {
            $account = BillingStore::lock($viewer->id);
            $existing = DB::table('billing_orders')->where('viewer_id', $viewer->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    $this->stale();
                }

                return $existing;
            }
            $open = $this->openSubscription($viewer->id);
            if (DB::table('billing_subscriptions')->where('viewer_id', $viewer->id)->where('resolution_required', true)->exists()) {
                $this->pending();
            }
            if ($open) {
                throw new ApiProblem($open->resolution_required ? 'BILLING_PENDING' : 'SUBSCRIPTION_EXISTS', 409, '请先处理本人原订阅', true);
            }
            $offer = DB::table('billing_offers')->where('id', $input->offer_id)->first();
            if (! $offer || ($offer->viewer_id !== $viewer->id)
                || $offer->price_version !== $input->price_version || now()->gte(CarbonImmutable::parse($offer->expires_at))) {
                $this->stale();
            }
            $orderId = BillingStore::id();
            $subId = BillingStore::id();
            $first = $account->first_paid_at === null;
            if ($offer->eligible_first_offer !== $first) {
                $this->stale();
            }
            $discount = $first && $offer->first_week_amount_minor < $offer->regular_amount_minor;
            DB::table('billing_subscriptions')->insert($purchaseScope->columns() + ['id' => $subId, 'viewer_id' => $viewer->id, 'first_order_id' => $orderId, 'state' => 'pending',
                'price_version' => $offer->price_version, 'regular_amount_minor' => $offer->regular_amount_minor, 'first_week_amount_minor' => $offer->first_week_amount_minor,
                'currency' => $offer->currency, 'is_test' => $offer->is_test, 'renewal_enabled' => false, 'resolution_required' => true,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('billing_orders')->insert($purchaseScope->columns() + ['id' => $orderId, 'viewer_id' => $viewer->id, 'subscription_id' => $subId, 'offer_id' => $offer->id,
                'idempotency_key' => $idempotencyKey, 'fingerprint' => $fingerprint, 'transaction_kind' => $first ? 'first_subscription' : 'resubscription',
                'price_version' => $offer->price_version, 'amount_minor' => $first ? $offer->first_week_amount_minor : $offer->regular_amount_minor,
                'regular_amount_minor' => $offer->regular_amount_minor, 'discount_applied' => $discount, 'currency' => $offer->currency, 'is_test' => $offer->is_test,
                'state' => 'processing', 'payment_state' => 'pending', 'return_to' => $return,
                'attribution_snapshot' => $source ? json_encode($source->toArray(), JSON_THROW_ON_ERROR) : null,
                'expires_at' => null, 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('billing_orders')->find($orderId);
        });
        if ($order->state === 'processing' && $order->checkout_id === null) {
            $this->executeCheckout($order->id);
        }
        if ($priorOrder && $order->provider === 'paypal' && $order->checkout_id
            && in_array($order->state, ['processing', 'pending'], true)) {
            try {
                // Explicit retry of this viewer's original checkout. No provider lifecycle writes.
                app(PayPalReconciliation::class)->sync($order->subscription_id, allowLifecycleChanges: false);
            } catch (\Throwable) {
                // Preserve occupancy; an incomplete observation never grants membership.
            }
        }
        $order = DB::table('billing_orders')->find($order->id);
        if ($order->checkout_id === null && in_array($order->state, ['processing', 'review_required'], true)) {
            $this->pending();
        }

        return $this->checkoutResult($order);
    }

    public function executeCheckout(string $orderId): void
    {
        $initial = DB::table('billing_orders')->find($orderId);
        if (! $initial) {
            return;
        }
        if ($initial->provider === 'paypal') {
            try {
                app(PayPalRuntime::class)->checkout($orderId);
            } catch (\Throwable) {
                DB::table('billing_orders')->where('id', $orderId)->update(['last_error' => 'PAYPAL_CHECKOUT_UNRESOLVED', 'updated_at' => now()]);
            }

            return;
        }
        // Commit the complete immutable remote request and attempted marker BEFORE making a payable Session.
        $attempt = DB::transaction(function () use ($initial) {
            BillingStore::lock($initial->viewer_id);
            $order = DB::table('billing_orders')->find($initial->id);
            if ($order->checkout_id || $order->state !== 'processing') {
                return null;
            }
            try {
                if ($order->checkout_request === null) {
                    $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
                    $intent = $this->gatewayFor($order)->prepareCheckout($order, $sub);
                    DB::table('billing_orders')->where('id', $order->id)->update([
                        'checkout_request' => json_encode($intent, JSON_THROW_ON_ERROR),
                        'checkout_prepared_at' => $intent['prepared_at'], 'expires_at' => $intent['expires_at'], 'updated_at' => now(),
                    ]);
                    DB::table('billing_subscriptions')->where('id', $sub->id)->update(['customer_id' => $intent['customer_id'], 'updated_at' => now()]);
                    $order = DB::table('billing_orders')->find($order->id);
                }
                // Return the pre-marker snapshot only to this first execution. Every re-entry reconciles first.
                DB::table('billing_orders')->where('id', $order->id)->update(['checkout_attempted_at' => $order->checkout_attempted_at ?? now(), 'updated_at' => now()]);

                return $order;
            } catch (\Throwable $e) {
                DB::table('billing_orders')->where('id', $order->id)->update(['last_error' => 'CHECKOUT_PREPARATION_UNRESOLVED', 'updated_at' => now()]);

                return null;
            }
        });
        if (! $attempt) {
            return;
        }
        DB::transaction(function () use ($attempt) {
            BillingStore::lock($attempt->viewer_id);
            $order = DB::table('billing_orders')->find($attempt->id);
            if ($order->checkout_id || $order->state !== 'processing') {
                return;
            }
            $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
            try {
                $result = $this->gatewayFor($order)->createCheckout($attempt, $sub);
                if (! isset($result['session_id'],$result['expires_at'],$result['customer_id'])
                    || BillingStore::iso($result['expires_at']) !== BillingStore::iso($order->expires_at)) {
                    $this->pending();
                }
                $safety = app(CheckoutSafety::class);
                $url = $safety->requestIsSafe($order, $order->checkout_request) && $safety->checkoutUrlIsSafe($result['url'] ?? null)
                    ? $result['url'] : null;
                DB::table('billing_orders')->where('id', $order->id)->update(['checkout_id' => $result['session_id'], 'checkout_url' => $url,
                    'expires_at' => $result['expires_at'], 'state' => 'pending', 'last_error' => null, 'updated_at' => now()]);
                DB::table('billing_subscriptions')->where('id', $sub->id)->update(['customer_id' => $result['customer_id'],
                    'provider_id' => $result['subscription_id'] ?? null, 'updated_at' => now()]);
            } catch (\Throwable $e) {
                DB::table('billing_orders')->where('id', $order->id)->update(['last_error' => 'CHECKOUT_RESULT_UNKNOWN', 'updated_at' => now()]);
            }
        });
    }

    public function order(ViewerIdentity $viewer, string $orderId): Order
    {
        if (! Str::isUuid($orderId)) {
            $this->notFound();
        }
        $order = DB::table('billing_orders')->where('id', $orderId)->where('viewer_id', $viewer->id)->first() ?? $this->notFound();

        return new Order($order->id, OrderState::from($order->state), PaymentState::from($order->payment_state), $order->amount_minor, $order->currency, BillingStore::iso($order->created_at));
    }

    public function membership(string $viewerId, \DateTimeImmutable $at): Membership
    {
        $at = CarbonImmutable::instance($at)->utc();
        $from = null;
        $until = null;
        $intervals = DB::table('billing_entitlements')->where('viewer_id', $viewerId)->whereNull('revoked_at')->orderBy('access_from')->get();
        // Merge only connected paid intervals; an unpaid gap never becomes paid access.
        $ranges = [];
        foreach ($intervals as $interval) {
            $a = CarbonImmutable::parse($interval->access_from);
            $b = CarbonImmutable::parse($interval->access_until);
            $i = count($ranges) - 1;
            if ($i >= 0 && $a->lte($ranges[$i][1])) {
                if ($b->gt($ranges[$i][1])) {
                    $ranges[$i][1] = $b;
                }
            } else {
                $ranges[] = [$a, $b];
            }
        }
        foreach ($ranges as [$a,$b]) {
            if ($a->lte($at) && $at->lt($b)) {
                $from = $a;
                $until = $b;
                break;
            }
        }
        $sub = $this->openSubscription($viewerId) ?? DB::table('billing_subscriptions')->where('viewer_id', $viewerId)->orderByDesc('created_at')->first();
        $state = $sub ? SubscriptionState::from($sub->state) : SubscriptionState::None;
        $pending = DB::table('billing_subscriptions')->where('viewer_id', $viewerId)->where('resolution_required', true)->exists() || ($sub && in_array($sub->state, ['unknown', 'pending', 'past_due'], true));
        $renewal = $sub && $sub->renewal_enabled && $sub->period_end !== null && $sub->state !== 'ended';
        $actions = [];
        if ((! $sub || $sub->state === 'ended') && ! $pending) {
            $actions[] = SubscriptionAction::Checkout;
        } elseif ($pending) {
            $actions[] = SubscriptionAction::ResolvePayment;
            if ($sub?->provider_id) {
                $actions[] = SubscriptionAction::Cancel;
            }
        } elseif (ProviderScope::legacyStripe()->matches($sub) && $sub->state === 'canceling' && $sub->provider_status === 'active' && $until && $sub->period_end && $at->lt(CarbonImmutable::parse($sub->period_end))) {
            $actions[] = SubscriptionAction::Resume;
        } elseif ($renewal) {
            $actions[] = SubscriptionAction::Cancel;
        }

        return new Membership($from !== null, BillingStore::iso($from), BillingStore::iso($until), (bool) $renewal, $state, (bool) $pending,
            $renewal ? BillingStore::iso($sub->period_end) : null, $renewal ? $sub->regular_amount_minor : null, $actions);
    }

    public function resumeQuote(ViewerIdentity $viewer): ResumeQuote
    {
        return DB::transaction(function () use ($viewer) {
            BillingStore::lock($viewer->id);
            $sub = $this->openSubscription($viewer->id);
            $this->requireResumable($sub);
            $membership = $this->membership($viewer->id, now()->toDateTimeImmutable());
            $expires = now()->addMinutes(5)->min(CarbonImmutable::parse($sub->period_end))->min(CarbonImmutable::parse($membership->access_until));
            $id = BillingStore::id();
            DB::table('billing_resume_quotes')->insert(['id' => $id, 'viewer_id' => $viewer->id, 'subscription_id' => $sub->id,
                'confirmation_version' => $sub->confirmation_version, 'amount_minor' => $sub->regular_amount_minor, 'currency' => $sub->currency,
                'next_renewal_at' => $sub->period_end, 'expires_at' => $expires, 'created_at' => now()]);

            return new ResumeQuote($id, $sub->id, $sub->confirmation_version, $sub->currency, BillingStore::iso($sub->period_end), $sub->regular_amount_minor, BillingStore::iso($expires));
        });
    }

    public function resume(ViewerIdentity $viewer, ResumeInput $confirmation): Membership
    {
        Validator::make($confirmation->toArray(), ['quote_id' => 'required|uuid', 'confirmation_version' => 'required|integer|min:1',
            'confirmed_next_renewal_at' => 'required|date', 'confirmed_amount_minor' => 'required|integer|min:1', 'confirmed_currency' => 'required|in:USD'])->validate();
        $operation = DB::transaction(function () use ($viewer, $confirmation) {
            BillingStore::lock($viewer->id);
            $quote = DB::table('billing_resume_quotes')->where('id', $confirmation->quote_id)->where('viewer_id', $viewer->id)->first() ?? $this->notFound();
            $sub = DB::table('billing_subscriptions')->find($quote->subscription_id);
            if ($quote->confirmation_version !== $confirmation->confirmation_version || $quote->amount_minor !== $confirmation->confirmed_amount_minor
                || $quote->currency !== $confirmation->confirmed_currency || BillingStore::iso($quote->next_renewal_at) !== BillingStore::iso($confirmation->confirmed_next_renewal_at)) {
                $this->stale();
            }
            if ($quote->state === 'succeeded') {
                if ($sub->confirmation_version !== $quote->completed_version || ! $sub->renewal_enabled || $sub->resolution_required) {
                    $this->stale();
                }

                return null;
            }
            if ($quote->state === 'pending') {
                $this->pending();
            }
            if (now()->gte(CarbonImmutable::parse($quote->expires_at)) || $sub->confirmation_version !== $quote->confirmation_version) {
                $this->stale();
            }
            $this->requireResumable($sub);
            if ($sub->regular_amount_minor !== $quote->amount_minor || $sub->currency !== $quote->currency || BillingStore::iso($sub->period_end) !== BillingStore::iso($quote->next_renewal_at)) {
                $this->stale();
            }
            $this->gatewayFor($sub)->assertAvailable();
            DB::table('billing_resume_quotes')->where('id', $quote->id)->update(['state' => 'pending']);

            return $this->reserveOperation($sub, 'resume', $quote->id);
        });
        if ($operation) {
            $this->executeOperation($operation);
        }
        $member = $this->membership($viewer->id, now()->toDateTimeImmutable());
        if ($member->payment_resolution_required) {
            $this->pending();
        }

        return $member;
    }

    public function cancel(ViewerIdentity $viewer): Membership
    {
        return $this->cancelAccount($viewer->id);
    }

    public function cancelForSupport(StaffIdentity $staff, string $viewerId, string $verifiedCaseId): Membership
    {
        if (! Str::isUuid($verifiedCaseId)) {
            $this->notFound();
        }
        $result = $this->cancelAccount($viewerId, supportRequest: [$staff, $verifiedCaseId]);
        app(AuditRecorder::class)->record('billing.support.cancel', 'billing_case', $verifiedCaseId, 'confirmed');

        return $result;
    }

    public function cancelSubscriptionForRefund(string $subscriptionId, ?int $expectedVersion = null): void
    {
        $sub = DB::table('billing_subscriptions')->find($subscriptionId);
        if (! $sub || $sub->state === 'ended') {
            return;
        }
        try {
            $this->cancelAccount($sub->viewer_id, $sub->id, $expectedVersion ?? $sub->confirmation_version);
        } catch (ApiProblem $e) {
            if (! in_array($e->errorCode, ['BILLING_PENDING', 'DEPENDENCY_NOT_READY'], true)) {
                throw $e;
            }
        }
    }

    private function cancelAccount(string $viewerId, ?string $expectedSubscription = null, ?int $expectedIntentVersion = null, ?array $supportRequest = null): Membership
    {
        $operation = DB::transaction(function () use ($viewerId, $expectedSubscription, $expectedIntentVersion, $supportRequest) {
            BillingStore::lock($viewerId);
            if ($supportRequest) {
                $expectedSubscription = $this->verifiedSupportSubscription($supportRequest[0], $viewerId, $supportRequest[1]);
            }
            $sub = $this->openSubscription($viewerId);
            if (! $sub) {
                return null;
            }
            if ($expectedIntentVersion !== null && ($sub->confirmation_version !== $expectedIntentVersion || ($sub->desired_renewal !== false && ! $sub->refund_stopped))) {
                return null;
            }
            if ($expectedSubscription !== null && $sub->id !== $expectedSubscription) {
                $this->stale();
            }
            $pending = DB::table('billing_operations')->where('subscription_id', $sub->id)->where('state', 'pending')->first();
            if ($pending) {
                if ($pending->kind === 'cancel') {
                    $this->consumeSupportRequest($supportRequest, $sub->confirmation_version);

                    return $pending->id;
                }
                // A newer explicit cancellation invalidates every retry of the old resume.
                DB::table('billing_operations')->where('id', $pending->id)->update(['state' => 'superseded', 'last_error' => 'NEWER_CANCEL_REQUEST', 'updated_at' => now()]);
                if ($pending->quote_id) {
                    DB::table('billing_resume_quotes')->where('id', $pending->quote_id)->update(['state' => 'superseded']);
                }
            }
            if ($sub->state === 'canceling' && ! $sub->renewal_enabled && ! $sub->resolution_required) {
                $this->consumeSupportRequest($supportRequest, $sub->confirmation_version);

                return null;
            }
            if (! $sub->provider_id) {
                $this->pending();
            }
            if ($sub->provider === 'paypal') {
                app(PayPalReconciliation::class)->assertAvailable($sub);
            } else {
                $this->gatewayFor($sub)->assertAvailable();
            }

            $id = $this->reserveOperation($sub, 'cancel');
            $this->consumeSupportRequest($supportRequest, $sub->confirmation_version + 1);

            return $id;
        });
        if ($operation) {
            $this->executeOperation($operation);
        }
        $member = $this->membership($viewerId, now()->toDateTimeImmutable());
        if ($expectedIntentVersion === null && ($member->payment_resolution_required || $member->renewal_enabled)) {
            $this->pending();
        }

        return $member;
    }

    private function verifiedSupportSubscription(StaffIdentity $staff, string $viewerId, string $caseId): string
    {
        // Re-read under the account reservation lock, then hold the case lock until accepted.
        // A concurrently withdrawn verification must not authorize a stale cancellation.
        $case = DB::table('billing_cases')->where('id', $caseId)->where('viewer_id', $viewerId)->lockForUpdate()->first() ?? $this->notFound();
        $order = $case->order_id ? DB::table('billing_orders')->find($case->order_id) : null;
        $verified = $case->identity_verified && $case->cancel_requested && $case->verified_at !== null;
        $current = app(IdentityContext::class)->requireStaff(Permission::SubscriptionCancel, new ResourceScope(caseLookup: true, verifiedCancellationRequest: $verified));
        if (! $verified || $current->id !== $staff->id || ($current->role !== Role::Owner && $case->staff_id !== $current->id)
            || ! $order || $order->viewer_id !== $viewerId) {
            throw new ApiProblem('NOT_AUTHORIZED', 403, '请核实本人当前取消请求');
        }

        return $order->subscription_id;
    }

    private function consumeSupportRequest(?array $supportRequest, int $version): void
    {
        if (! $supportRequest) {
            return;
        }
        $caseId = $supportRequest[1];
        DB::table('billing_cases')->where('id', $caseId)->update(['identity_verified' => false, 'cancel_requested' => false, 'verified_at' => null]);
        // Persist attribution at acceptance, including when the provider subsequently times out.
        app(AuditRecorder::class)->record('billing.support.cancel_requested', 'billing_case', $caseId, 'accepted', ['version' => $version]);
    }

    private function reserveOperation(object $sub, string $kind, ?string $quoteId = null): string
    {
        $id = BillingStore::id();
        $version = $sub->confirmation_version + 1;
        DB::table('billing_operations')->insert(['id' => $id, 'viewer_id' => $sub->viewer_id, 'subscription_id' => $sub->id, 'quote_id' => $quoteId,
            'kind' => $kind, 'expected_version' => $version, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('billing_subscriptions')->where('id', $sub->id)->update(['confirmation_version' => $version, 'desired_renewal' => $kind === 'resume', 'refund_stopped' => $kind === 'resume' ? false : $sub->refund_stopped, 'resolution_required' => true, 'updated_at' => now()]);

        return $id;
    }

    public function executeOperation(string $operationId): void
    {
        $op = DB::table('billing_operations')->find($operationId);
        if (! $op) {
            return;
        }
        DB::transaction(function () use ($op) {
            BillingStore::lock($op->viewer_id);
            $op = DB::table('billing_operations')->find($op->id);
            if ($op->state !== 'pending') {
                return;
            }
            $sub = DB::table('billing_subscriptions')->find($op->subscription_id);
            $enabled = $op->kind === 'resume';
            if ($sub->confirmation_version !== $op->expected_version || ($enabled && $sub->refund_stopped)) {
                DB::table('billing_operations')->where('id', $op->id)->update(['state' => 'superseded', 'last_error' => 'STALE_VERSION', 'updated_at' => now()]);
                if ($op->quote_id) {
                    DB::table('billing_resume_quotes')->where('id', $op->quote_id)->update(['state' => 'superseded']);
                }

                return;
            }
            try {
                if ($sub->provider === 'paypal') {
                    if ($enabled) {
                        throw new DependencyNotReady('PayPal resume official evidence');
                    }
                    app(PayPalReconciliation::class)->cancelOperation($sub);
                    DB::table('billing_operations')->where('id', $op->id)->update(['state' => 'succeeded', 'last_error' => null, 'updated_at' => now()]);
                    app(AuditRecorder::class)->record('billing.cancel', 'billing_subscription', $sub->id, 'confirmed', ['version' => $sub->confirmation_version + 1]);

                    return;
                }
                $current = $this->gatewayFor($sub)->subscription($sub->provider_id);
                $terminal = in_array($current['status'], ['canceled', 'incomplete_expired'], true);
                $resumeQuote = $enabled && $op->quote_id
                    ? DB::table('billing_resume_quotes')->where('id', $op->quote_id)
                        ->where('viewer_id', $op->viewer_id)->where('subscription_id', $sub->id)->first()
                    : null;
                // The accepted confirmation owns this boundary. Reconciliation may advance the
                // subscription's current cycle while an earlier restore result is still unknown.
                if ($enabled && (! $resumeQuote || $terminal
                    || $resumeQuote->confirmation_version + 1 !== $op->expected_version
                    || now()->gte(CarbonImmutable::parse($resumeQuote->next_renewal_at))
                    || BillingStore::iso($current['period_end']) !== BillingStore::iso($resumeQuote->next_renewal_at)
                    || BillingStore::iso($sub->period_end) !== BillingStore::iso($resumeQuote->next_renewal_at))) {
                    DB::table('billing_operations')->where('id', $op->id)->update(['state' => 'superseded', 'last_error' => 'RESUME_WINDOW_CLOSED', 'updated_at' => now()]);
                    DB::table('billing_resume_quotes')->where('id', $op->quote_id)->update(['state' => 'superseded']);
                    DB::table('billing_subscriptions')->where('id', $sub->id)->update(['confirmation_version' => DB::raw('confirmation_version + 1'), 'resolution_required' => true, 'updated_at' => now()]);

                    return;
                }
                $this->checkOriginalTerms($sub, $current, $enabled);
                if (! $terminal && $current['cancel_at_period_end'] === $enabled) {
                    $current = $this->gatewayFor($sub)->setRenewal($sub, $enabled, 'billing-operation-'.$op->id);
                    $this->checkOriginalTerms($sub, $current, $enabled);
                }
                if (! $terminal && $current['cancel_at_period_end'] === $enabled) {
                    $this->pending();
                }
                if ($enabled && $current['status'] !== 'active') {
                    $this->pending();
                }
                $version = $sub->confirmation_version + 1;
                $latestPaid = ! empty($current['latest_invoice_id']) && DB::table('billing_payments')->where(ProviderScope::fromRecord($sub)->columns())->where('subscription_id', $sub->id)->where('provider_invoice_id', $current['latest_invoice_id'])->exists();
                $hasAccess = DB::table('billing_entitlements')->where('subscription_id', $sub->id)->whereNull('revoked_at')->where('access_until', '>', now())->exists();
                $requiresCheck = ! $latestPaid || DB::table('billing_orders')->where('subscription_id', $sub->id)->where('state', 'review_required')->exists();
                DB::table('billing_subscriptions')->where('id', $sub->id)->update(['state' => $terminal ? ($requiresCheck ? 'pending' : ($hasAccess ? 'canceling' : 'ended')) : ($enabled ? 'active' : 'canceling'),
                    'provider_status' => $current['status'], 'renewal_enabled' => $enabled && ! $terminal, 'resolution_required' => $requiresCheck,
                    'period_start' => $current['period_start'], 'period_end' => $current['period_end'],
                    'confirmation_version' => $version, 'updated_at' => now()]);
                DB::table('billing_operations')->where('id', $op->id)->update(['state' => 'succeeded', 'last_error' => null, 'updated_at' => now()]);
                if ($op->quote_id) {
                    DB::table('billing_resume_quotes')->where('id', $op->quote_id)->update(['state' => 'succeeded', 'completed_version' => $version]);
                }
                app(AuditRecorder::class)->record('billing.'.$op->kind, 'billing_subscription', $sub->id, 'confirmed', ['version' => $version]);
            } catch (\Throwable $e) {
                DB::table('billing_operations')->where('id', $op->id)->update(['last_error' => 'PROVIDER_RESULT_UNRESOLVED', 'updated_at' => now()]);
            }
        });
        $sub = DB::table('billing_subscriptions')->find($op->subscription_id);
        if ($sub?->provider === 'paypal') {
            try {
                app(PayPalReconciliation::class)->sync($sub->id);
            } catch (\Throwable) {
                // The confirmed cancellation is retained; unresolved payment facts stay pending.
            }
        }
    }

    public function retryPendingOperations(): int
    {
        $count = 0;
        foreach (DB::table('billing_operations')->where('state', 'pending')->orderBy('updated_at')->limit(100)->get() as $op) {
            $this->executeOperation($op->id);
            $count++;
        }
        foreach (DB::table('billing_orders')->where('state', 'processing')->whereNull('checkout_id')->orderBy('updated_at')->limit(100)->get() as $order) {
            $this->executeCheckout($order->id);
        }
        foreach (DB::table('billing_subscriptions')->where(fn ($q) => $q->where('refund_stopped', true)->orWhere('desired_renewal', false))->where('state', '<>', 'ended')->where('renewal_enabled', true)->limit(100)->get() as $sub) {
            $this->cancelSubscriptionForRefund($sub->id);
        }

        return $count;
    }

    private function checkOriginalTerms(object $sub, array $fact, bool $lockPeriod = true): void
    {
        if ($fact['id'] !== $sub->provider_id || $fact['customer_id'] !== $sub->customer_id || $fact['order_id'] !== $sub->first_order_id
            || $fact['regular_amount_minor'] !== $sub->regular_amount_minor || $fact['currency'] !== $sub->currency || $fact['is_test'] !== (bool) $sub->is_test
            || ($lockPeriod && BillingStore::iso($fact['period_end']) !== BillingStore::iso($sub->period_end))) {
            $this->pending();
        }
    }

    private function requireResumable(?object $sub): void
    {
        if (! $sub || $sub->state === 'ended') {
            $this->stale();
        }
        $this->gatewayFor($sub);
        if ($sub->resolution_required || DB::table('billing_operations')->where('subscription_id', $sub->id)->where('state', 'pending')->exists()) {
            $this->pending();
        }
        if ($sub->state !== 'canceling' || $sub->provider_status !== 'active' || $sub->renewal_enabled || ! $sub->provider_id || ! $sub->period_end
            || now()->gte(CarbonImmutable::parse($sub->period_end)) || ! $this->membership($sub->viewer_id, now()->toDateTimeImmutable())->access_active) {
            $this->stale();
        }
    }

    private function openSubscription(string $viewerId): ?object
    {
        return DB::table('billing_subscriptions')->where('viewer_id', $viewerId)->where('state', '<>', 'ended')->first();
    }

    private function checkoutResult(object $order): Checkout
    {
        if ($order->provider === 'paypal') {
            $url = null;
            try {
                $url = app(PayPalReconciliation::class)->approvalUrl($order);
            } catch (\Throwable) {
                // Never expose an unsafe or stale persisted approval URL.
            }

            return new Checkout($order->id, OrderState::from($order->state), $url, null);
        }
        $safety = app(CheckoutSafety::class);
        $url = $order->state === 'pending' && $order->checkout_id && $order->expires_at
            && now()->lt(CarbonImmutable::parse($order->expires_at))
            && $safety->requestIsSafe($order, $order->checkout_request)
            && $safety->checkoutUrlIsSafe($order->checkout_url) ? $order->checkout_url : null;

        return new Checkout($order->id, OrderState::from($order->state), $url, BillingStore::iso($order->expires_at));
    }

    private function gatewayFor(object $record): PaymentGateway
    {
        return (new ProviderGatewayRouter($this->gateway))->forRecord($record);
    }

    private function stale(): never
    {
        throw new ApiProblem('STALE_VERSION', 409, '信息已变化，请重新读取并确认');
    }

    private function pending(): never
    {
        throw new ApiProblem('BILLING_PENDING', 409, '付款或订阅正在核对，请稍后刷新', true);
    }

    private function notFound(): never
    {
        throw new ApiProblem('NOT_FOUND', 404, '记录不存在');
    }
}
