<?php

namespace Tests\Feature\Contracts;

use App\Contracts\BillingService;
use App\Contracts\CatalogReader;
use App\Data\CatalogPage;
use App\Data\CategoryList;
use App\Data\Drama;
use App\Data\HomePage;
use App\Data\Membership;
use App\Data\Offer;
use App\Data\ResumeInput;
use App\Data\ResumeQuote;
use App\Data\ViewerIdentity;
use App\Enums\SubscriptionAction;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use App\Support\DependencyNotReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedContractsTest extends TestCase
{
    use RefreshDatabase;

    private function sample(string $file): array
    {
        return json_decode(file_get_contents(base_path('../docs/contracts/examples/'.$file.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_consumer_can_confirm_original_terms_after_the_public_price_changes(): void
    {
        // A consumer contract scenario, not a simulated successful Stripe operation.
        $membership = Membership::fromArray($this->sample('membership-canceled-paid'));
        $newPurchase = Offer::fromArray($this->sample('offer-after-price-change'));
        $quote = ResumeQuote::fromArray($this->sample('resume-quote'));

        $this->assertContains(SubscriptionAction::Resume, $membership->allowed_actions);
        $this->assertNull($membership->next_renewal_at);
        $this->assertNull($membership->next_renewal_amount_minor);
        $this->assertNotSame($newPurchase->next_renewal_amount_minor, $quote->next_renewal_amount_minor);
        $this->assertSame(1200, $quote->next_renewal_amount_minor);

        // These five displayed/confirmed values are all supplied by the quote.
        $confirmation = new ResumeInput(
            quote_id: $quote->quote_id,
            confirmation_version: $quote->confirmation_version,
            confirmed_next_renewal_at: $quote->next_renewal_at,
            confirmed_amount_minor: $quote->next_renewal_amount_minor,
            confirmed_currency: $quote->currency,
        );
        $this->assertSame($this->sample('resume-input'), $confirmation->toArray());
        $this->assertLessThanOrEqual(strtotime($membership->access_until), strtotime($quote->expires_at));
        $this->assertLessThanOrEqual(strtotime($quote->next_renewal_at), strtotime($quote->expires_at));
    }

    public function test_home_consumer_can_follow_a_category_and_banner_without_media_or_external_targets(): void
    {
        $home = HomePage::fromArray($this->sample('home-en'));
        $categories = CategoryList::fromArray($this->sample('categories-en'));
        $catalog = CatalogPage::fromArray($this->sample('catalog-category'));
        $drama = Drama::fromArray($this->sample('drama-from-home'));

        $this->assertSame($categories->items[0]->id, $home->categories[0]->id);
        $this->assertSame($categories->toArray()['items'], $home->toArray()['categories']);
        $this->assertSame($catalog->items[0]->id, $drama->id);
        $this->assertSame($home->banners[0]->target_drama_id, $drama->id);
        $this->assertSame($home->recommendations[0]->id, $drama->id);
        $this->assertSame('/dramas/'.$drama->id, '/dramas/'.$home->banners[0]->target_drama_id);
        $this->assertArrayNotHasKey('video_url', $home->recommendations[0]->toArray());
        $this->assertArrayNotHasKey('target_url', $home->banners[0]->toArray());
        $this->assertSame('membership', $drama->episodes[5]->access_requirement);
        $this->assertSame('free', $drama->episodes[4]->access_requirement);

        $zh = HomePage::fromArray($this->sample('home-zh_CN'));
        $zhCategories = CategoryList::fromArray($this->sample('categories-zh_CN'));
        $this->assertSame($zhCategories->toArray()['items'], $zh->toArray()['categories']);
        $this->assertSame($home->categories[0]->id, $zh->categories[0]->id);
        $this->assertNotSame($home->categories[0]->name, $zh->categories[0]->name);
        $this->assertSame($home->banners[0]->target_drama_id, $zh->banners[0]->target_drama_id);
        $this->assertSame($home->recommendations[0]->title, $zh->recommendations[0]->title); // English fallback, not invented translation.

        foreach ([$home->categories, $home->banners, $home->recommendations] as $items) {
            $actual = array_map(fn ($item) => [$item->sort_order, $item->id], $items);
            $sorted = $actual;
            sort($sorted);
            $this->assertSame($sorted, $actual);
        }
        $this->assertSame([], HomePage::fromArray($this->sample('home-empty'))->recommendations);
    }

    public function test_new_ports_require_the_real_provider_even_when_empty_examples_exist(): void
    {
        $viewer = new ViewerIdentity('019a2b3c-1234-7000-8000-000000000001', 'contract@example.test', true);
        $catalog = app(CatalogReader::class);
        $billing = app(BillingService::class);
        foreach ([
            fn () => $catalog->homepage('zh_CN'),
            fn () => $catalog->categories(),
            fn () => $catalog->catalog(categoryId: $this->sample('home-en')['categories'][0]['id']),
            fn () => $billing->resumeQuote($viewer),
            fn () => $billing->resume($viewer, ResumeInput::fromArray($this->sample('resume-input'))),
        ] as $call) {
            try {
                $call();
                $this->fail('An unimplemented business provider must not return a successful fixture.');
            } catch (DependencyNotReady $e) {
                $this->assertSame(503, $e->status);
            }
        }
    }

    public function test_public_home_and_categories_remain_accessible_to_guests_but_unavailable(): void
    {
        foreach (['/api/v1/home?locale=zh_CN', '/api/v1/categories?locale=en', '/api/v1/catalog?category_id='.$this->sample('home-en')['categories'][0]['id']] as $url) {
            $this->getJson($url)->assertStatus(503)->assertHeader('X-Request-ID')->assertJsonPath('error.code', 'DEPENDENCY_NOT_READY');
        }
    }

    public function test_resume_quote_and_submission_require_an_audience_session(): void
    {
        $this->getJson('/api/v1/billing/subscription/resume-quote')->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $this->postJson('/api/v1/billing/subscription/resume', $this->sample('resume-input'))->assertUnauthorized();

        $staff = new Staff;
        $staff->id = '019a2b3c-1234-7000-8000-000000000901';
        $staff->active = true;
        $staff->session_version = 1;
        $this->actingAs($staff, 'staff');
        $this->getJson('/api/v1/billing/subscription/resume-quote')->assertUnauthorized();

        $viewer = Viewer::create(['name' => 'Contract viewer', 'email' => 'contract@example.test', 'password' => 'TestOnlyPassword1234', 'email_verified_at' => now()]);
        $this->actingAs($viewer, 'web')->withSession(['identity.version.web' => $viewer->session_version]);
        $this->getJson('/api/v1/billing/subscription/resume-quote')->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_NOT_READY');
        $this->postJson('/api/v1/billing/subscription/resume', $this->sample('resume-input'))->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_NOT_READY');
    }
}
