<?php

namespace App\Modules\Audience\Billing;

use App\Contracts\AttributionService;
use App\Contracts\BillingService;
use App\Contracts\CheckoutAttribution;
use App\Contracts\IdentityContext;
use App\Contracts\MembershipReader;
use App\Data\AttributionSnapshot;
use App\Data\CheckoutInput;
use App\Data\CheckoutPageContext;
use App\Data\ResumeInput;
use App\Enums\SubscriptionAction;
use App\Modules\Audience\Analytics\BillingBehavior;
use App\Modules\Identity\Services\SafeReturn;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class BillingController
{
    public function __construct(private IdentityContext $identity, private BillingService $billing, private MembershipReader $memberships) {}

    public function index(Request $request)
    {
        $this->rememberPlayback($request);
        $viewer = $this->identity->viewer();
        $membership = null;
        $offer = null;
        $quote = null;
        $problem = null;
        $status = 200;
        try {
            $membership = $viewer ? $this->memberships->membership($viewer->id, now()->toDateTimeImmutable()) : null;
            if ($request->boolean('resume') && $viewer && in_array(SubscriptionAction::Resume, $membership->allowed_actions, true)) {
                $quote = $this->billing->resumeQuote($viewer);
            } elseif (! $viewer || ($request->boolean('purchase') && in_array(SubscriptionAction::Checkout, $membership->allowed_actions, true))) {
                $offer = $this->billing->offer($viewer);
                if ($viewer) {
                    $drafts = $request->session()->get('audience.billing.offers', []);
                    $drafts[$offer->offer_id] = ['viewer_id' => $viewer->id, 'price_version' => $offer->price_version,
                        'key' => (string) Str::uuid(), 'return_to' => $this->playback($request), 'expires_at' => $offer->expires_at];
                    if ($viewer->emailVerified && $request->isMethod('GET')) {
                        $drafts[$offer->offer_id]['checkout_page'] = ['path' => '/membership',
                            'rendered_at' => now()->utc()->toIso8601String()];
                    }
                    $request->session()->put('audience.billing.offers', array_slice($drafts, -10, null, true));
                }
            }
        } catch (ApiProblem $e) {
            $problem = $this->problemKey($e);
            $status = $e->status;
        }
        if ($viewer && ! $viewer->emailVerified) {
            app(SafeReturn::class)->remember('/membership?purchase=1');
        }

        $pendingAttempt = null;
        $knownOrderId = null;
        if ($viewer) {
            foreach (array_reverse($request->session()->get('audience.billing.attempts', []), true) as $attempt) {
                if ($attempt['viewer_id'] === $viewer->id && $attempt['state'] === 'pending') {
                    $pendingAttempt = $attempt;
                    break;
                }
            }
            foreach (array_reverse($request->session()->get('audience.billing.checkouts', []), true) as $orderId => $saved) {
                if ($saved['viewer_id'] === $viewer->id) {
                    $knownOrderId = $orderId;
                    break;
                }
            }
        }

        return $this->page('index', compact('viewer', 'membership', 'offer', 'quote', 'problem', 'pendingAttempt', 'knownOrderId'), $status);
    }

    public function checkout(Request $request)
    {
        $viewer = $this->identity->requireViewer(verified: true);
        $input = $request->validate(['offer_id' => 'required|uuid', 'price_version' => 'required|integer|min:1',
            'recheck' => 'sometimes|in:1', 'confirmed' => $request->boolean('recheck') ? 'sometimes|accepted' : 'accepted']);
        $attemptKey = 'audience.billing.attempts.'.$input['offer_id'];
        $attempt = $request->session()->get($attemptKey);
        if ($attempt && ($attempt['viewer_id'] !== $viewer->id || $attempt['price_version'] !== (int) $input['price_version'])) {
            return $this->redirect('/membership', 'recovery_missing');
        }
        if ($request->boolean('recheck') && (! $attempt || ! in_array($attempt['state'], ['pending', 'completed'], true))) {
            // A fresh session cannot reconstruct an unknown order or start a purchase as a status check.
            return $this->redirect('/membership', 'recovery_missing');
        }
        if (! $attempt) {
            $draft = $request->session()->get('audience.billing.offers.'.$input['offer_id']);
            if (! $draft || $draft['viewer_id'] !== $viewer->id || $draft['price_version'] !== (int) $input['price_version']) {
                return $this->redirect('/membership?purchase=1', 'stale');
            }
            if (! is_string($draft['expires_at'] ?? null) || CarbonImmutable::parse($draft['expires_at'])->lessThanOrEqualTo(now())) {
                return $this->redirect('/membership?purchase=1', 'stale');
            }
            $source = null;
            try {
                $attribution = app(AttributionService::class);
                if ($attribution->enabled()) {
                    $snapshot = $attribution->snapshot(null);
                    $source = $snapshot?->toArray();
                    $page = $draft['checkout_page'] ?? null;
                    if ($snapshot && is_array($page) && ($page['path'] ?? null) === '/membership'
                        && is_string($page['rendered_at'] ?? null) && $this->sameOriginCapture($request)) {
                        $captured = app(CheckoutAttribution::class)->capture($snapshot,
                            new CheckoutPageContext('/membership', $page['rendered_at']));
                        $source = $captured?->toArray() ?? $source;
                    }
                }
            } catch (\Throwable $e) {
                // Attribution availability must not prevent a viewer from buying.
            }
            // Keep exactly the confirmed request, including its key and attribution, across unknown outcomes.
            $attempt = $draft + ['offer_id' => $input['offer_id'], 'checkout_return_to' => '/billing/return',
                'source' => $source, 'state' => 'confirmed'];
            $request->session()->put($attemptKey, $attempt);
        }
        try {
            $checkout = $this->billing->checkout($viewer,
                new CheckoutInput($attempt['offer_id'], $attempt['price_version'], $attempt['checkout_return_to']),
                $attempt['key'], $attempt['source'] ? AttributionSnapshot::fromArray($attempt['source']) : null);
            app(BillingBehavior::class)->checkout($checkout, $viewer, $attempt['state'] !== 'completed' && empty($attempt['analytics_checkout_observed']));
            // A later consent must not backfill an already observed checkout through an explicit retry.
            $attempt['analytics_checkout_observed'] = true;
            $attempt['state'] = 'completed';
            $request->session()->put($attemptKey, $attempt);
            $checkoutData = $checkout->toArray();
            $paypalApprovalUrl = $this->isPaypalSandboxUrl($checkoutData['checkout_url'] ?? null) ? $checkoutData['checkout_url'] : null;
            if ($paypalApprovalUrl !== null) {
                // PayPal approval URLs are one-time navigation data. Never cache them in the browser session.
                $checkoutData['checkout_url'] = null;
            }
            $request->session()->put('audience.billing.checkouts.'.$checkout->order_id, ['viewer_id' => $viewer->id,
                'checkout' => $checkoutData, 'return_to' => $attempt['return_to'], 'offer_id' => $attempt['offer_id'],
                'price_version' => $attempt['price_version']]);

            if ($paypalApprovalUrl !== null && $checkout->state->value === 'pending') {
                return redirect()->away($paypalApprovalUrl)->header('Cache-Control', 'private, no-store');
            }

            return redirect('/billing/return?order_id='.rawurlencode($checkout->order_id))->header('Cache-Control', 'private, no-store');
        } catch (ApiProblem $e) {
            app(BillingBehavior::class)->rejected($e, $attempt['key'], $viewer);
            $terminalConflict = in_array($e->errorCode, ['STALE_VERSION', 'SUBSCRIPTION_EXISTS'], true);
            $attempt['state'] = ! $terminalConflict && ($e->errorCode === 'BILLING_PENDING' || $attempt['state'] === 'pending') ? 'pending' : 'failed';
            $request->session()->put($attemptKey, $attempt);
            if ($e->errorCode === 'STALE_VERSION') {
                return $this->redirect('/membership?purchase=1', 'stale');
            }
            if (in_array($e->errorCode, ['BILLING_PENDING', 'SUBSCRIPTION_EXISTS'], true)) {
                return $this->redirect('/membership', $this->problemKey($e));
            }

            return $this->page('error', ['problem' => $this->problemKey($e)], $e->status);
        }
    }

    public function paymentReturn(Request $request)
    {
        $orderId = is_string($request->query('order_id')) ? $request->query('order_id') : '';
        if (! $this->identity->viewer()) {
            $return = Str::isUuid($orderId) ? '/billing/return?order_id='.$orderId : '/membership';

            return redirect('/identity/login?return_to='.rawurlencode($return))->header('Cache-Control', 'private, no-store');
        }
        $viewer = $this->identity->requireViewer();
        try {
            // Browser return parameters never grant membership; the provider owns every fact.
            $order = $this->billing->order($viewer, $orderId);
            app(BillingBehavior::class)->order($order, $viewer);
            $membership = $this->memberships->membership($viewer->id, now()->toDateTimeImmutable());
            $saved = $request->session()->get('audience.billing.checkouts.'.$orderId);
            $checkout = $saved && $saved['viewer_id'] === $viewer->id ? $saved['checkout'] : null;
            $returnTo = $saved && $saved['viewer_id'] === $viewer->id ? $saved['return_to'] : $this->playback($request);
            $checkoutLinkSafe = $checkout && $this->checkoutUrlIsSafe($checkout['checkout_url'] ?? null);
            $checkoutDeadline = is_string($checkout['expires_at'] ?? null) ? $checkout['expires_at'] : null;
            $checkoutDeadlineKnown = $checkoutDeadline !== null;
            $checkoutCanPay = false;
            if ($checkoutLinkSafe && $order->state->value === 'pending') {
                try {
                    if ($checkoutDeadlineKnown) {
                        $checkoutCanPay = CarbonImmutable::parse($checkoutDeadline)->isFuture();
                    }
                } catch (\Throwable) {
                    $checkoutCanPay = false;
                }
            }
            $canRecheckCheckout = $saved && $saved['viewer_id'] === $viewer->id
                && $this->hasConfirmedCheckoutAttempt($request, $viewer->id, $saved);

            return $this->page('return', compact('order', 'membership', 'checkout', 'returnTo', 'checkoutCanPay',
                'checkoutLinkSafe', 'checkoutDeadline', 'checkoutDeadlineKnown', 'canRecheckCheckout'));
        } catch (ApiProblem $e) {
            return $this->page('error', ['problem' => $this->problemKey($e)], $e->status);
        }
    }

    public function recheckPaymentReturn(Request $request)
    {
        $viewer = $this->identity->requireViewer();
        $input = $request->validate(['order_id' => 'required|uuid']);
        $orderId = $input['order_id'];
        $returnPath = '/billing/return?order_id='.rawurlencode($orderId);
        $savedKey = 'audience.billing.checkouts.'.$orderId;
        $saved = $request->session()->get($savedKey);

        // A fresh browser can query the order with GET, but cannot reconstruct or retry a purchase.
        if (! is_array($saved) || ($saved['viewer_id'] ?? null) !== $viewer->id
            || ! $this->hasConfirmedCheckoutAttempt($request, $viewer->id, $saved)) {
            return redirect($returnPath)->with('audience.billing.notice', 'recovery_missing')->header('Cache-Control', 'private, no-store');
        }

        $attempt = $request->session()->get('audience.billing.attempts.'.$saved['offer_id']);
        try {
            $checkout = $this->billing->checkout($viewer,
                new CheckoutInput($saved['offer_id'], (int) $saved['price_version'], '/billing/return'),
                $attempt['key'], $attempt['source'] ? AttributionSnapshot::fromArray($attempt['source']) : null);
            if ($checkout->order_id !== $orderId) {
                return redirect($returnPath)->with('audience.billing.notice', 'pending')->header('Cache-Control', 'private, no-store');
            }
            app(BillingBehavior::class)->checkout($checkout, $viewer, false);
            $paypalApprovalUrl = $this->isPaypalSandboxUrl($checkout->checkout_url) ? $checkout->checkout_url : null;
            // Keep the original session identity; only Billing can refresh the Checkout facts.
            $saved['checkout'] = $checkout->toArray();
            if ($paypalApprovalUrl !== null) {
                $saved['checkout']['checkout_url'] = null;
            }
            $request->session()->put($savedKey, $saved);
            if ($paypalApprovalUrl !== null && $checkout->state->value === 'pending') {
                return redirect()->away($paypalApprovalUrl)->header('Cache-Control', 'private, no-store');
            }
        } catch (ApiProblem $e) {
            return redirect($returnPath)->with('audience.billing.notice', $this->problemKey($e))->header('Cache-Control', 'private, no-store');
        }

        return redirect($returnPath)->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request)
    {
        $request->validate(['confirmed' => 'accepted']);
        try {
            $this->billing->cancel($this->identity->requireViewer());

            return $this->redirect('/membership', 'canceled');
        } catch (ApiProblem $e) {
            return $this->redirect('/membership', $this->problemKey($e));
        }
    }

    public function resume(Request $request)
    {
        $input = $request->validate(['confirmed' => 'accepted', 'quote_id' => 'required|uuid', 'confirmation_version' => 'required|integer|min:1',
            'confirmed_next_renewal_at' => 'required|date', 'confirmed_amount_minor' => 'required|integer|min:1', 'confirmed_currency' => 'required|in:USD']);
        unset($input['confirmed']);
        $input['confirmation_version'] = (int) $input['confirmation_version'];
        $input['confirmed_amount_minor'] = (int) $input['confirmed_amount_minor'];
        try {
            $this->billing->resume($this->identity->requireViewer(), ResumeInput::fromArray($input));

            return $this->redirect('/membership', 'resumed');
        } catch (ApiProblem $e) {
            // Redirect is a fresh read and a new user confirmation, never an automatic resume retry.
            return $this->redirect($e->errorCode === 'STALE_VERSION' ? '/membership?resume=1' : '/membership', $this->problemKey($e));
        }
    }

    private function sameOriginCapture(Request $request): bool
    {
        $site = $request->header('Sec-Fetch-Site');
        $origin = $request->header('Origin');
        if ($site !== null && $site !== 'same-origin') {
            return false;
        }
        if ($origin !== null && $origin !== $request->getSchemeAndHttpHost()) {
            return false;
        }

        // Missing headers are not evidence; never substitute Referer or a submitted URL.
        return $site === 'same-origin' || $origin === $request->getSchemeAndHttpHost();
    }

    private function rememberPlayback(Request $request): void
    {
        if ($request->has('return_to') && is_string($request->query('return_to'))) {
            $path = app(SafeReturn::class)->path($request->query('return_to'));
            if (preg_match('#^/(?:dramas/[a-zA-Z0-9-]+/episodes/[a-zA-Z0-9-]+|watch/[a-zA-Z0-9-]+)(?:\?|$)#', $path)) {
                $request->session()->put('audience.billing.return_to', $path);
            }
        }
    }

    private function playback(Request $request): ?string
    {
        $path = $request->session()->get('audience.billing.return_to');

        return is_string($path) && app(SafeReturn::class)->path($path) === $path ? $path : null;
    }

    private function hasConfirmedCheckoutAttempt(Request $request, string $viewerId, array $saved): bool
    {
        $offerId = $saved['offer_id'] ?? null;
        $attempt = is_string($offerId) ? $request->session()->get('audience.billing.attempts.'.$offerId) : null;

        return is_array($attempt)
            && ($attempt['viewer_id'] ?? null) === $viewerId
            && ($attempt['offer_id'] ?? null) === $offerId
            && (int) ($attempt['price_version'] ?? 0) === (int) ($saved['price_version'] ?? 0)
            && in_array($attempt['state'] ?? null, ['completed', 'pending'], true)
            && is_string($attempt['key'] ?? null) && $attempt['key'] !== ''
            && ($attempt['checkout_return_to'] ?? null) === '/billing/return';
    }

    private function isPaypalSandboxUrl(mixed $url): bool
    {
        return is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https'
            && strtolower((string) parse_url($url, PHP_URL_HOST)) === 'www.sandbox.paypal.com'
            && $this->checkoutUrlIsSafe($url);
    }

    private function checkoutUrlIsSafe(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }
        $decoded = $url;
        for ($i = 0; $i < 4; $i++) {
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) {
                return false;
            }
            $decoded = rawurldecode($decoded);
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! in_array(strtolower($parts['host'] ?? ''), ['checkout.stripe.com', 'www.sandbox.paypal.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        return true;
    }

    private function problemKey(ApiProblem $problem): string
    {
        return match ($problem->errorCode) {
            'DEPENDENCY_NOT_READY' => 'unavailable', 'STALE_VERSION' => 'stale', 'BILLING_PENDING' => 'pending',
            'SUBSCRIPTION_EXISTS' => 'exists', 'NOT_FOUND' => 'not_found', 'REGION_BLOCKED' => 'region', 'EMAIL_UNVERIFIED' => 'verify',
            default => 'error',
        };
    }

    private function redirect(string $path, string $notice)
    {
        return redirect($path)->with('audience.billing.notice', $notice)->header('Cache-Control', 'private, no-store');
    }

    private function page(string $view, array $data, int $status = 200)
    {
        return response()->view('audience.billing.'.$view, $data + ['returnTo' => $this->playback(request())], $status)->header('Cache-Control', 'private, no-store');
    }
}
