<?php

namespace Tests\Feature\Audience;

use App\Contracts\AttributionService;
use App\Contracts\CheckoutAttribution;
use App\Data\AttributionSnapshot;
use App\Data\CheckoutPageContext;
use App\Modules\Audience\AudienceServiceProvider;
use App\Modules\Identity\Models\Viewer;
use App\Support\DisabledCheckoutAttribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Billing\BillingTestCase;

/** Real Audience/Identity/Billing; attribution and external gateway are explicit test doubles. */
final class CheckoutAttributionUiTest extends BillingTestCase
{
    private object $attribution;

    private object $capture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['h5.modules.audience.ready' => true]);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        $this->attribution = new class implements AttributionService
        {
            public ?AttributionSnapshot $source;

            public int $reads = 0;

            public function __construct()
            {
                $this->source = new AttributionSnapshot((string) Str::uuid(), (string) Str::uuid(), 'meta', 1, now()->toIso8601String(), 'granted');
            }

            public function enabled(): bool
            {
                return true;
            }

            public function snapshot(?string $untrustedMarker): ?AttributionSnapshot
            {
                $this->reads++;

                return $this->source;
            }
        };
        $this->capture = new class implements CheckoutAttribution
        {
            public array $calls = [];

            public bool $fail = false;

            public function capture(AttributionSnapshot $source, CheckoutPageContext $page): ?AttributionSnapshot
            {
                $this->calls[] = [$source, $page];
                if ($this->fail) {
                    throw new \RuntimeException('explicit synthetic attribution failure');
                }

                return new AttributionSnapshot($source->source_id, $source->link_id, $source->channel,
                    $source->pixel_config_version, $source->captured_at, $source->consent, (string) Str::uuid());
            }
        };
        $this->app->instance(AttributionService::class, $this->attribution);
        $this->app->instance(CheckoutAttribution::class, $this->capture);
    }

    private function purchase(bool $verified = true): array
    {
        $viewer = $this->viewer($verified);
        $this->actingAs(Viewer::findOrFail($viewer->id), 'web')->withSession(['identity.version.web' => 1]);
        $offer = $this->get('/membership?purchase=1&return_to=%2Fdramas%2Fexample')->assertOk()->viewData('offer');

        return ['offer_id' => $offer->offer_id, 'price_version' => $offer->price_version, 'confirmed' => '1'];
    }

    private function submit(array $input, array $headers = []): TestResponse
    {
        return $this->post('/membership/checkout', $input, $headers + ['Origin' => rtrim(config('app.url'), '/'), 'Sec-Fetch-Site' => 'same-origin']);
    }

    public function test_only_verified_submittable_offer_gets_canonical_server_page_evidence(): void
    {
        $this->get('/membership?purchase=1')->assertOk()->assertSessionMissing('audience.billing.offers');
        $input = $this->purchase(false);
        $this->assertArrayNotHasKey('checkout_page', session('audience.billing.offers.'.$input['offer_id']));
        $input = $this->purchase();
        $this->assertSame(['path' => '/membership', 'rendered_at' => now()->utc()->toIso8601String()], session('audience.billing.offers.'.$input['offer_id'].'.checkout_page'));
        $this->submit($input)->assertRedirect();
        $this->assertCount(1, $this->capture->calls);
        $this->assertSame('/membership', $this->capture->calls[0][1]->path);
        $this->assertNotNull(session('audience.billing.attempts.'.$input['offer_id'].'.source.checkout_context_id'));
        $this->assertSame('pending', DB::table('billing_orders')->first()->state);
        $this->assertDatabaseCount('billing_entitlements', 0);
    }

    public function test_head_and_headerless_post_are_not_page_or_same_origin_evidence(): void
    {
        $viewer = $this->viewer();
        $this->actingAs(Viewer::findOrFail($viewer->id), 'web')->withSession(['identity.version.web' => 1]);
        $this->head('/membership?purchase=1')->assertOk();
        foreach (session('audience.billing.offers') as $draft) {
            $this->assertArrayNotHasKey('checkout_page', $draft);
        }
        $input = $this->purchase();
        $this->post('/membership/checkout', $input, ['Referer' => rtrim(config('app.url'), '/').'/membership?purchase=1'])->assertRedirect();
        $this->assertSame([], $this->capture->calls);
        $this->assertDatabaseCount('billing_orders', 1);
    }

    public function test_missing_page_evidence_keeps_original_six_fields_and_does_not_block_purchase(): void
    {
        $input = $this->purchase();
        session()->forget('audience.billing.offers.'.$input['offer_id'].'.checkout_page');
        $this->submit($input)->assertRedirect();
        $this->assertSame([], $this->capture->calls);
        $this->assertSame($this->attribution->source->toArray(), session('audience.billing.attempts.'.$input['offer_id'].'.source'));
    }

    public function test_cross_site_or_missing_origin_evidence_never_captures_and_does_not_block_payment(): void
    {
        foreach ([['Origin' => 'https://evil.invalid'], ['Sec-Fetch-Site' => 'cross-site'], ['Origin' => 'null'], ['Origin' => '', 'Sec-Fetch-Site' => '']] as $headers) {
            $input = $this->purchase();
            $this->submit($input, $headers)->assertRedirect();
        }
        $this->assertSame([], $this->capture->calls);
        $this->assertDatabaseCount('billing_orders', 4);
    }

    public function test_missing_consent_capture_failure_and_default_null_port_preserve_payment(): void
    {
        $input = $this->purchase();
        $savedSource = $this->attribution->source;
        $this->attribution->source = null;
        $this->submit($input)->assertRedirect();
        $this->assertNull(session('audience.billing.attempts.'.$input['offer_id'].'.source'));
        $this->assertSame([], $this->capture->calls);
        $this->attribution->source = $savedSource;
        $this->capture->fail = true;
        $input = $this->purchase();
        $this->submit($input)->assertRedirect();
        $this->assertSame($savedSource->toArray(), session('audience.billing.attempts.'.$input['offer_id'].'.source'));
        $this->app->instance(CheckoutAttribution::class, new DisabledCheckoutAttribution);
        $input = $this->purchase();
        $this->submit($input)->assertRedirect();
        $this->assertSame($savedSource->toArray(), session('audience.billing.attempts.'.$input['offer_id'].'.source'));
        $this->assertDatabaseCount('billing_orders', 3);
    }

    public function test_unknown_retry_uses_same_frozen_context_and_key_after_expiry(): void
    {
        $input = $this->purchase();
        $this->gateway->failCreate = true;
        $this->submit($input)->assertRedirect('/membership');
        $attempt = session('audience.billing.attempts.'.$input['offer_id']);
        $this->travel(35)->minutes();
        $this->attribution->source = null;
        $this->submit($input)->assertRedirect();
        $this->assertCount(1, $this->capture->calls);
        $this->assertSame(1, $this->attribution->reads);
        $this->assertSame($attempt['source'], session('audience.billing.attempts.'.$input['offer_id'].'.source'));
        $this->assertSame($attempt['key'], session('audience.billing.attempts.'.$input['offer_id'].'.key'));
        $this->assertDatabaseCount('billing_orders', 1);
    }

    public function test_later_consent_cannot_backfill_an_attempt_or_direct_api_checkout(): void
    {
        $input = $this->purchase();
        $source = $this->attribution->source;
        $this->attribution->source = null;
        $this->submit($input)->assertRedirect();
        $this->attribution->source = $source;
        $this->submit($input)->assertRedirect();
        $this->assertNull(session('audience.billing.attempts.'.$input['offer_id'].'.source'));
        $this->assertSame([], $this->capture->calls);
        $input = $this->purchase();
        $this->postJson('/api/v1/billing/checkout', ['offer_id' => $input['offer_id'], 'price_version' => $input['price_version'], 'return_to' => '/billing/return'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertSame([], $this->capture->calls);
    }

    public function test_expired_changed_price_other_actor_and_missing_draft_cannot_capture_or_create(): void
    {
        $input = $this->purchase();
        $this->travel(31)->minutes();
        $this->submit($input)->assertRedirect('/membership?purchase=1');
        $input = $this->purchase();
        $input['price_version']++;
        $this->submit($input)->assertRedirect('/membership?purchase=1');
        $input = $this->purchase();
        $this->purchase();
        $this->submit($input)->assertRedirect('/membership?purchase=1');
        $this->submit(['offer_id' => (string) Str::uuid(), 'price_version' => 1, 'confirmed' => '1'])->assertRedirect('/membership?purchase=1');
        $this->assertSame([], $this->capture->calls);
        $this->assertDatabaseCount('billing_orders', 0);
    }
}
