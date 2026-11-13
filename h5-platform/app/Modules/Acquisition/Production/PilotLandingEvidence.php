<?php

namespace App\Modules\Acquisition\Production;

use App\Contracts\PromotionLandingEvidence;
use App\Modules\Acquisition\Analytics\Clock;
use App\Modules\Acquisition\Services\AttributionRecorder;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class PilotLandingEvidence implements PromotionLandingEvidence
{
    public function record(string $dramaId): void
    {
        try {
            $request = request();
            $session = $request->hasSession() ? $request->session() : null;
            $pending = $session?->get('tracking.pending');
            if (! $pending || ! Str::isUuid($dramaId) || ! $request->isMethod('GET')
                || $request->getPathInfo() !== '/dramas/'.$dramaId || ! $this->trustedRequest(false)) {
                return;
            }
            [$scope, $link] = app(PilotScope::class)->target($pending['link_id']);
            if ($link->drama_id === $dramaId && $this->pendingInScope($pending, $scope)) {
                $session->put('tracking.production_get', ['key' => $pending['key'], 'drama_id' => $dramaId,
                    'scope' => $scope, 'at' => Clock::stamp()]);
            }
        } catch (Throwable) {
            // Evidence is optional for rendering a drama.
        }
    }

    public function confirms(array $pending, string $dramaId): bool
    {
        $request = request();
        $proof = $request->hasSession() ? $request->session()->get('tracking.production_get') : null;

        try {
            [$scope] = app(PilotScope::class)->target($pending['link_id']);
        } catch (LogicException) {
            return false;
        }

        return $this->pendingInScope($pending, $scope)
            && is_array($proof) && ($proof['scope'] ?? null) === $scope && $proof['key'] === $pending['key'] && $proof['drama_id'] === $dramaId
            && $request->isMethod('POST') && $request->getPathInfo() === '/api/v1/tracking/landing'
            && $this->trustedRequest(true) && ! CarbonImmutable::parse($proof['at'])->greaterThan(now())
            && CarbonImmutable::parse($proof['at'])->addMinutes(10)->greaterThan(now());
    }

    private function pendingInScope(array $pending, array $scope): bool
    {
        $created = CarbonImmutable::parse($pending['created_at']);

        return $created->greaterThanOrEqualTo(CarbonImmutable::parse($scope['from']))
            && $created->lessThanOrEqualTo(now()) && $created->addDay()->greaterThan(now());
    }

    public function trustedRequest(bool $post): bool
    {
        $request = request();
        $origin = app(RuntimeEnvironmentGuard::class)->productionAcquisitionPilotOrigin();
        $site = $request->header('Sec-Fetch-Site');
        $sameSite = in_array($site, $post ? [null, 'same-origin'] : [null, 'same-origin', 'none'], true);
        $externalNavigation = ! $post && in_array($site, ['cross-site', 'same-site'], true)
            && $request->header('Sec-Fetch-Mode') === 'navigate' && $request->header('Sec-Fetch-Dest') === 'document';
        if (! $request->isSecure() || $request->getSchemeAndHttpHost() !== $origin
            || ($post && $request->header('Origin') !== $origin)
            || (! $sameSite && ! $externalNavigation)
            || ! app(AttributionRecorder::class)->eligibleRequest()) {
            return false;
        }
        foreach (['Forwarded', 'X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port'] as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        return true;
    }
}
