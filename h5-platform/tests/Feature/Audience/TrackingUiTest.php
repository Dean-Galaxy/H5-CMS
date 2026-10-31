<?php

namespace Tests\Feature\Audience;

use App\Modules\Audience\AudienceServiceProvider;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Billing\BillingTestCase;

final class TrackingUiTest extends BillingTestCase
{
    public function test_privacy_controls_are_bilingual_separate_optional_and_only_mounted_when_ready(): void
    {
        config(['h5.modules.audience.ready' => true, 'h5.modules.acquisition.ready' => true]);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        foreach (['en' => 'Privacy choices', 'zh_CN' => '隐私选择'] as $locale => $title) {
            $response = $this->get('/membership?lang='.$locale)->assertOk()->assertSee($title)
                ->assertSee('name="analytics"', false)->assertSee('name="advertising"', false)
                ->assertSee('data-privacy-reject', false)->assertSee('data-privacy-withdraw', false)
                ->assertSee('/audience/analytics.js', false)->assertDontSee('checked', false);
            // Opt-in export for the offline real-Chrome test. Contains synthetic anonymous test HTML only.
            if (getenv('H5_ANALYTICS_RENDER') === '1') {
                $dir = base_path('../.runtime/audience');
                if (! is_dir($dir)) {
                    mkdir($dir, 0700, true);
                }
                file_put_contents($dir.'/analytics-'.$locale.'.html', $response->getContent());
            }
        }
        config(['h5.modules.acquisition.ready' => false]);
        $this->get('/membership')->assertOk()->assertDontSee('/audience/analytics.js', false)->assertDontSee('data-privacy-form', false);
    }

    public function test_dbip_attribution_is_a_fixed_plain_footer_link_only_for_enabled_driver(): void
    {
        config(['h5.modules.audience.ready' => true]);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        foreach (['disabled', null, 'dbip_lite'] as $driver) {
            config(['geoip.driver' => $driver]);
            $html = $this->get('/membership')->assertOk()->getContent();
            if ($driver === 'dbip_lite') {
                $this->assertStringContainsString('<small><a href="https://db-ip.com">IP Geolocation by DB-IP</a></small>', $html);
                $this->assertSame(1, substr_count($html, 'https://db-ip.com'));
            } else {
                $this->assertStringNotContainsString('https://db-ip.com', $html);
            }
        }
    }
}
