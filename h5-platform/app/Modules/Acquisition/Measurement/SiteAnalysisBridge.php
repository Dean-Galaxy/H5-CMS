<?php

namespace App\Modules\Acquisition\Measurement;

use App\Contracts\IdentityContext;
use App\Modules\Acquisition\Analytics\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Denials stay on existing privacy proofs; never link a C subject to an account or conversion. */
final class SiteAnalysisBridge
{
    public static function experience(): bool
    {
        return config('h5.measurement_c_experience', false) === true;
    }

    public function browserDenial(): ?object
    {
        $r = request();
        $id = $r->cookie(config('h5.workspace', 'h5').'_c_preference')
            ?? ($r->hasSession() ? $r->session()->get('measurement_c.preference') : null);
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        // Savepoint protects a surrounding necessary-business transaction on storage failure.
        return DB::transaction(fn () => DB::table('acquisition_c_preferences')->where('id', $id)
            ->where('expires_at', '>', now())->where('choice', 'deny')->first());
    }

    public function browserDenied(): bool
    {
        try {
            return $this->browserDenial() !== null;
        } catch (Throwable) {
            // Unknown C proof: fail closed for measurement, not Retry into billing/ad receipt processing.
            return true;
        }
    }

    public function proofDenied(?object $proof): bool
    {
        return $proof && ($proof->site_analysis_denied_at ?? null) !== null
            && (($proof->site_analysis_denial_until ?? null) === null || CarbonImmutable::parse($proof->site_analysis_denial_until)->greaterThan(now()));
    }

    private function knownIds(): array
    {
        $r = request();
        $prefix = config('h5.workspace', 'h5');
        $raw = $r->cookie($prefix.'_acquisition_privacy_evidence', '[]');
        $ids = is_string($raw) ? json_decode($raw, true) : [];
        $ids = is_array($ids) ? array_slice($ids, -20) : [];
        $ids[] = $r->hasSession() ? $r->session()->get('tracking.privacy') : null;
        $ids[] = $r->cookie($prefix.'_acquisition_consent');

        return array_values(array_unique(array_filter($ids, fn ($v) => is_string($v) && Str::isUuid($v))));
    }

    /** Called inside the C preference transaction; failure must roll back both C preference and legacy analysis proof. */
    public function denyKnown(object $preference): void
    {
        $ids = $this->knownIds();
        $viewer = app(IdentityContext::class)->viewer()?->id;
        $q = DB::table('acquisition_privacy');
        if ($viewer) {
            // Existing trusted Acquisition proofs only, same scope as legacy withdrawal. No identity-table scan.
            $q->where(fn ($q) => $q->where('viewer_id', $viewer)->orWhere(fn ($q) => $q->whereNull('viewer_id')->whereIn('id', $ids)));
        } else {
            $q->whereIn('id', $ids);
        }
        $affected = $q->lockForUpdate()->pluck('id');
        if ($affected->isNotEmpty()) {
            DB::table('acquisition_privacy')->whereIn('id', $affected)->update($this->denialValues($preference) + [
                'analytics' => 'denied', 'analytics_granted_at' => null, 'current_subject' => null, 'updated_at' => Clock::stamp(),
            ]);
        }
        if (request()->hasSession()) {
            request()->session()->forget('tracking.subject');
            // Do not fabricate a new legacy choice when the browser has never used that pipeline.
            if (request()->session()->has('tracking.consent') || $affected->isNotEmpty()) {
                request()->session()->put('tracking.consent.analytics', 'denied');
            }
        }
    }

    public function denialValues(?object $preference = null): array
    {
        $preference ??= $this->browserDenial();
        if (! $preference || $preference->choice !== 'deny') {
            throw new \LogicException('A confirmed C denial is required.');
        }

        // Inheriting a refusal is not a new choice. Later advertising requests must never extend it.
        return ['site_analysis_denied_at' => Clock::stamp($preference->updated_at),
            'site_analysis_denial_until' => Clock::stamp($preference->expires_at)];
    }
}
