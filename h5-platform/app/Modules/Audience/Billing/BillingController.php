<?php

namespace App\Modules\Audience\Billing;

use App\Contracts\AttributionService;
use App\Contracts\BillingService;
use App\Contracts\IdentityContext;
use App\Contracts\MembershipReader;
use App\Data\AttributionSnapshot;
use App\Data\CheckoutInput;
use App\Data\ResumeInput;
use App\Enums\SubscriptionAction;
use App\Modules\Identity\Services\SafeReturn;
use App\Support\ApiProblem;
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
            $source = null;
            try {
                $attribution = app(AttributionService::class);
                if ($attribution->enabled()) {
                    $source = $attribution->snapshot(null)?->toArray();
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
            $attempt['state'] = 'completed';
            $request->session()->put($attemptKey, $attempt);
            $request->session()->put('audience.billing.checkouts.'.$checkout->order_id, ['viewer_id' => $viewer->id,
                'checkout' => $checkout->toArray(), 'return_to' => $attempt['return_to']]);

            return redirect('/billing/return?order_id='.rawurlencode($checkout->order_id))->header('Cache-Control', 'private, no-store');
        } catch (ApiProblem $e) {
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
            $membership = $this->memberships->membership($viewer->id, now()->toDateTimeImmutable());
            $saved = $request->session()->get('audience.billing.checkouts.'.$orderId);
            $checkout = $saved && $saved['viewer_id'] === $viewer->id ? $saved['checkout'] : null;
            $returnTo = $saved && $saved['viewer_id'] === $viewer->id ? $saved['return_to'] : $this->playback($request);

            return $this->page('return', compact('order', 'membership', 'checkout', 'returnTo'));
        } catch (ApiProblem $e) {
            return $this->page('error', ['problem' => $this->problemKey($e)], $e->status);
        }
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
