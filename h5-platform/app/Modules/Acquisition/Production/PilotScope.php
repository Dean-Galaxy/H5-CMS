<?php

namespace App\Modules\Acquisition\Production;

use App\Modules\Acquisition\Registration\LegacyAdvertisingBridge;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PilotScope
{
    public static function enabled(): bool
    {
        return config('h5.acquisition_production_pilot') === true;
    }

    public function active(): bool
    {
        try {
            $this->policy();

            return true;
        } catch (LogicException) {
            return false;
        }
    }

    public function policy(): array
    {
        $guard = app(RuntimeEnvironmentGuard::class);
        if (config('h5.acquisition_automatic_mode', 'off') === 'off') {
            return $guard->productionAcquisitionScope();
        }

        return $guard->productionAutomaticPurchasePolicy()
            ?? throw new LogicException('PRODUCTION_AUTOMATIC_WINDOW_CLOSED');
    }

    public function siteAnalyticsPolicy(): ?array
    {
        if (! self::enabled() || config('h5.acquisition_automatic_site_analytics') !== true
            || config('h5.acquisition_automatic_mode') !== 'single') {
            return null;
        }

        try {
            return app(RuntimeEnvironmentGuard::class)->productionAutomaticPurchasePolicy();
        } catch (LogicException) {
            return null;
        }
    }

    public function target(string $linkId): array
    {
        $scope = $this->policy();
        if (isset($scope['link_id']) && $scope['link_id'] !== $linkId) {
            throw new LogicException('PRODUCTION_PURCHASE_OUT_OF_SCOPE');
        }

        $link = DB::table('acquisition_links')->where('id', $linkId)->first();
        $pixel = $link ? DB::table('acquisition_pixels')->where('id', $link->pixel_id)->first() : null;
        if (! $link || ! $pixel || ! in_array($link->platform, ['meta', 'tiktok'], true)) {
            throw new LogicException('PRODUCTION_PURCHASE_ASSET_CHANGED');
        }
        $scope += ['link_id' => $linkId, 'platform' => $link->platform, 'pixel_id' => $pixel->id, 'pixel_version' => (int) $pixel->version];
        $version = DB::table('acquisition_pixel_versions')->where('pixel_id', $scope['pixel_id'])
            ->where('version', $scope['pixel_version'])->first();
        if (! $link || ! $link->active || $link->pixel_id !== $scope['pixel_id'] || $link->platform !== $scope['platform']
            || ! $pixel || ! $pixel->active || $pixel->platform !== $scope['platform'] || $pixel->environment !== 'production'
            || (int) $pixel->version !== $scope['pixel_version'] || ! $version || $version->platform !== $pixel->platform
            || $version->external_id !== $pixel->external_id || $version->environment !== 'production') {
            throw new LogicException('PRODUCTION_PURCHASE_ASSET_CHANGED');
        }

        return [$scope, $link, $pixel];
    }

    public function source(object $source): array
    {
        [$scope, $link, $pixel] = $this->target($source->link_id);
        if ($source->is_simulation || $source->consent !== 'granted' || $source->revoked_at !== null
            || $source->channel !== $scope['platform'] || $source->pixel_id !== $pixel->id
            || (int) $source->pixel_version !== $scope['pixel_version']
            || CarbonImmutable::parse($source->captured_at)->lessThan(CarbonImmutable::parse($scope['from']))
            || ($scope['until'] !== null && CarbonImmutable::parse($source->captured_at)->gte($scope['until']))
            || CarbonImmutable::parse($source->captured_at)->greaterThan(now())
            || CarbonImmutable::parse($source->expires_at)->lessThanOrEqualTo(now())) {
            throw new LogicException('PRODUCTION_PURCHASE_SOURCE_INVALID');
        }
        $privacy = DB::table('acquisition_privacy')->where('id', $source->privacy_id)->first();
        if (! $privacy || $privacy->advertising !== 'granted' || app(LegacyAdvertisingBridge::class)->markerDenied($privacy)) {
            throw new LogicException('PRODUCTION_PURCHASE_CONSENT_REQUIRED');
        }

        return [$scope, $link, $pixel];
    }
}
