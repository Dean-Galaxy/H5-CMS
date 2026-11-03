<?php

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Gateway\PayPal\LiveApi;

/** The server chooses the price pool; no browser flag can enable production pricing. */
final class BillingPriceMode
{
    public function live(): bool
    {
        return app()->environment('production') || (app()->environment('testing')
            && config('billing.new_purchase_provider') === 'paypal_live' && app(LiveApi::class)->offlineFake());
    }
}
