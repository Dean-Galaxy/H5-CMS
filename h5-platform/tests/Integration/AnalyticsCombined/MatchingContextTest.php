<?php

namespace Tests\Integration\AnalyticsCombined;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Integration\AnalyticsCombined\Support\CombinedTestCase;

/** Consent/matching port assertions written for fbafae0; full candidate execution is still required. */
final class MatchingContextTest extends CombinedTestCase
{
    private function visible(array $context): array
    {
        return ['page_instance_id' => (string) Str::uuid(), 'drama_id' => $this->drama->id,
            'path' => '/dramas/'.$this->drama->id, 'click_context' => $context];
    }

    public function test_slow_or_reordered_landing_does_not_freeze_empty_context_or_leak_platform_identifiers(): void
    {
        $this->consent();
        $click = 'SYNTHETIC06_META_CLICK';
        $this->get('/r/'.basename($this->link['url']).'?fbclid='.$click.'&ttclid=SYNTHETIC06_WRONG_PLATFORM&token=SYNTHETIC06_DO_NOT_FORWARD')
            ->assertRedirect();
        // Deliberately simulate generic arrival first. The dedicated confirmation must still populate this pending click.
        $this->browser('landing_view', ['path' => '/dramas/'.$this->drama->id, 'drama_id' => $this->drama->id]);
        $this->travel(1)->seconds();
        $this->postJson('/api/v1/tracking/landing', $this->visible(['fbclid' => $click,
            'fbc' => 'fb.1.1600000000000.SYNTHETIC06_OLD_CLICK', 'fbp' => 'fb.1.1600000000000.1060000001',
            'ttclid' => 'SYNTHETIC06_WRONG_PLATFORM', 'ttp' => 'SYNTHETIC06_TIKTOK_COOKIE']))
            ->assertOk()->assertJsonPath('confirmed', true);
        $source = DB::table('acquisition_sources')->sole();
        $context = json_decode(Crypt::decryptString($source->context_encrypted), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($click, json_encode($context));
        $this->assertStringNotContainsString('SYNTHETIC06_OLD_CLICK', json_encode($context));
        $this->assertStringNotContainsString('SYNTHETIC06_WRONG_PLATFORM', json_encode($context));
        $this->assertStringNotContainsString('SYNTHETIC06_TIKTOK_COOKIE', json_encode($context));
        $this->assertStringNotContainsString($click, $source->context_encrypted);
        $this->assertStringNotContainsString($click, json_encode(DB::table('acquisition_behaviors')->get()));
        $report = $this->finance();
        $this->assertStringNotContainsString($click, json_encode($report));
        Http::assertNothingSent();
    }

    public function test_unknown_or_denied_advertising_never_persists_matching_identifiers(): void
    {
        $this->get('/r/'.basename($this->link['url']).'?fbclid=SYNTHETIC06_NOT_CONSENTED')->assertRedirect();
        $this->postJson('/api/v1/tracking/landing', $this->visible(['fbclid' => 'SYNTHETIC06_NOT_CONSENTED']))->assertOk();
        $this->consent(true, false);
        $this->postJson('/api/v1/tracking/landing', $this->visible(['fbclid' => 'SYNTHETIC06_DENIED']))->assertOk();
        $this->assertDatabaseCount('acquisition_sources', 0);
        foreach (DB::table('acquisition_behaviors')->get() as $row) {
            $this->assertStringNotContainsString('SYNTHETIC06_', json_encode($row));
        }
        Http::assertNothingSent();
    }

    public function test_matching_context_size_and_value_limits_are_enforced_on_the_dedicated_api(): void
    {
        $this->consent();
        $this->get('/r/'.basename($this->link['url']))->assertRedirect();
        $this->postJson('/api/v1/tracking/landing', $this->visible(['fbclid' => str_repeat('x', 513)]))->assertUnprocessable();
        $this->postJson('/api/v1/tracking/landing', $this->visible(['fbclid' => 'SYNTHETIC06', 'url' => 'https://forbidden.invalid/']))->assertUnprocessable();
        $body = $this->visible(['fbclid' => str_repeat('x', 4097)]);
        $this->postJson('/api/v1/tracking/landing', $body)->assertStatus(413);
        $this->assertDatabaseCount('acquisition_sources', 0);
        Http::assertNothingSent();
    }
}
