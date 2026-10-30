<?php

namespace Tests\Feature\Audience;

use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Audience\AudienceServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Content\ContentTestCase;

/** Real Content catalog, C core and PostgreSQL. No payment or ad provider. */
final class MeasurementCUiTest extends ContentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['h5.modules.audience.ready' => true, 'h5.modules.acquisition.ready' => true,
            'h5.measurement_c_experience' => true]);
        $this->app->register(AudienceServiceProvider::class);
        $this->app->register(AcquisitionServiceProvider::class);
        config(['acquisition.measurement.enabled' => true, 'acquisition.measurement.synthetic_policy' => true,
            'acquisition.measurement.synthetic_until' => now()->addHour()->toIso8601String()]);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
    }

    public function test_c_page_is_bilingual_nonblocking_and_stays_c_when_core_is_disabled(): void
    {
        foreach (['en' => 'Privacy management', 'zh_CN' => '隐私管理'] as $locale => $title) {
            foreach ([true, false] as $enabled) {
                config(['acquisition.measurement.enabled' => $enabled]);
                $this->get('/?lang='.$locale)->assertOk()->assertSee($title)
                    ->assertSeeInOrder(['site-header', 'data-c-notice', '<main'], false)
                    ->assertSee('data-c-use', false)->assertSee('/audience/measurement-c.js', false)
                    ->assertDontSee('/audience/analytics.js', false)->assertDontSee('data-privacy-form', false)
                    ->assertDontSee('name="analytics"', false)->assertDontSee('name="advertising"', false)
                    ->assertDontSee('<dialog open', false);
            }
        }
        config(['h5.measurement_c_experience' => false]);
        $this->get('/')->assertOk()->assertSee('/audience/analytics.js', false)->assertDontSee('data-c-notice', false);
    }

    public function test_actual_core_start_and_deny_do_not_block_actual_free_permission_or_create_billing_facts(): void
    {
        $drama = $this->fixture(published: true);
        $episode = $drama->episodes()->where('number', 1)->first();
        $this->get('/dramas/'.$drama->id.'/episodes/'.$episode->id)->assertOk()->assertSee('data-page-type="player"', false);
        $this->getJson('/api/v2/measurement/context')->assertOk()->assertJsonPath('site_effective', 'off');
        $this->assertDatabaseCount('acquisition_c_subjects', 0);
        $this->postJson('/api/v2/measurement/start', ['notice_version' => 'c-notice-c-core-1', 'page_instance_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('site_effective', 'on')->assertJsonPath('site_choice', 'unset');
        $this->postJson('/api/v2/measurement/events', ['events' => [[
            'event_id' => (string) Str::uuid(), 'event_name' => 'page_view', 'properties' => [
                'page_instance_id' => (string) Str::uuid(), 'page_type' => 'player', 'drama_id' => $drama->id, 'episode_id' => $episode->id,
            ],
        ]]])->assertOk()->assertJsonPath('accepted', 1);
        $this->patchJson('/api/v2/measurement/preferences', ['site_session' => 'deny', 'notice_version' => 'c-notice-c-core-1', 'expected_revision' => 0])
            ->assertOk()->assertJsonPath('site_choice', 'deny')->assertJsonPath('site_effective', 'off');
        $this->getJson('/api/v2/measurement/context')->assertOk()->assertJsonPath('site_choice', 'deny');
        $this->postJson('/api/v1/episodes/'.$episode->id.'/playback-grants', ['play_session_id' => null])
            ->assertOk()->assertJsonPath('allowed', true);
        $this->assertDatabaseCount('acquisition_c_events', 1);
        $this->assertDatabaseCount('billing_orders', 0);
    }

    public function test_r1_management_is_flagged_bilingual_and_false_keeps_existing_c_controls(): void
    {
        foreach (['en', 'zh_CN'] as $locale) {
            config(['h5.measurement_c_registration' => false]);
            $this->get('/?lang='.$locale)->assertOk()->assertDontSee('/audience/purposes-r1.js', false)->assertDontSee('data-c-purpose-manager', false);
            config(['h5.measurement_c_registration' => true]);
            $this->get('/?lang='.$locale)->assertOk()->assertSee('data-c-registration', false)->assertSee('data-c-purpose-manager', false)
                ->assertSee('/audience/purposes-r1.js', false)->assertSee('purpose-account_link', false)->assertSee('purpose-advertising', false)
                ->assertDontSee('checked', false)->assertDontSee('data-privacy-form', false);
        }
    }
}
