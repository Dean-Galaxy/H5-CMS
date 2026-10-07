<?php

use App\Contracts\AttributionService;
use App\Contracts\BillingService;
use App\Contracts\IdentityContext;
use App\Contracts\MembershipReader;
use App\Data\CheckoutInput;
use App\Data\ResumeInput;
use App\Modules\Billing\Services\WebhookService;
use App\Support\DependencyNotReady;
use App\Support\UnavailableServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

$ready = static function (): BillingService {
    $service = app(BillingService::class);
    if ($service instanceof UnavailableServices) {
        throw new DependencyNotReady('Billing');
    }

    return $service;
};
$only = static function (Request $request, array $keys): void {
    $unknown = array_diff(array_keys($request->except('_token')), $keys);
    if ($unknown) {
        throw ValidationException::withMessages(['request' => '包含不允许的字段']);
    }
};
$private = static fn ($result) => response()->json($result)->header('Cache-Control', 'private, no-store');

Route::get('/api/v1/billing/offer', fn (IdentityContext $identity) => $private($ready()->offer($identity->viewer())));
Route::post('/api/v1/billing/checkout', function (Request $request, IdentityContext $identity) use ($ready, $only, $private) {
    $viewer = $identity->requireViewer(verified: true);
    $service = $ready();
    $only($request, ['offer_id', 'price_version', 'return_to']);
    $data = $request->validate(['offer_id' => 'required|uuid', 'price_version' => 'required|integer|min:1', 'return_to' => 'required|string|max:1024']);
    $source = null;
    try {
        $attribution = app(AttributionService::class);
        if ($attribution->enabled()) {
            $source = $attribution->snapshot(null);
        }
    } catch (Throwable $e) { /* Unavailable attribution must not block payment. */
    }

    return $private($service->checkout($viewer, CheckoutInput::fromArray($data), (string) $request->header('Idempotency-Key', ''), $source));
})->middleware('throttle:30,1');
Route::middleware('auth:web')->group(function () use ($ready, $only, $private) {
    Route::get('/api/v1/me/membership', function (IdentityContext $identity, MembershipReader $reader) use ($private) {
        return $private($reader->membership($identity->requireViewer()->id, now()->toDateTimeImmutable()));
    });
    Route::get('/api/v1/billing/orders/{id}', fn (IdentityContext $identity, string $id) => $private($ready()->order($identity->requireViewer(), $id)));
    Route::post('/api/v1/billing/subscription/cancel', function (Request $request, IdentityContext $identity) use ($ready, $only, $private) {
        $service = $ready();
        $only($request, []);

        return $private($service->cancel($identity->requireViewer()));
    });
    Route::get('/api/v1/billing/subscription/resume-quote', fn (IdentityContext $identity) => $private($ready()->resumeQuote($identity->requireViewer())));
    Route::post('/api/v1/billing/subscription/resume', function (Request $request, IdentityContext $identity) use ($ready, $only, $private) {
        $service = $ready();
        $only($request, ['quote_id', 'confirmation_version', 'confirmed_next_renewal_at', 'confirmed_amount_minor', 'confirmed_currency']);
        $data = $request->validate(['quote_id' => 'required|uuid', 'confirmation_version' => 'required|integer|min:1',
            'confirmed_next_renewal_at' => 'required|date', 'confirmed_amount_minor' => 'required|integer|min:1', 'confirmed_currency' => 'required|in:USD']);

        return $private($service->resume($identity->requireViewer(), ResumeInput::fromArray($data)));
    });
});
Route::post('/webhooks/stripe', function (Request $request) use ($ready) {
    $ready();
    app(WebhookService::class)->receive($request->getContent(), (string) $request->header('Stripe-Signature', ''));

    return response()->json(['status' => 'accepted', 'message' => '通知已接收，付款事实需核对', 'request_id' => $request->attributes->get('request_id')]);
});
