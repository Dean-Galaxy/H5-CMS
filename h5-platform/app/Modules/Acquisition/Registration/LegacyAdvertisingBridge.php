<?php

namespace App\Modules\Acquisition\Registration;

use App\Contracts\IdentityContext;
use App\Modules\Acquisition\Analytics\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LegacyAdvertisingBridge
{
    public function __construct(private PurposeProof $proof) {}

    public function markerDenied(?object $privacy): bool
    {
        return $privacy && ($privacy->r1_advertising_denied_at ?? null) !== null
            && (($privacy->r1_advertising_denial_until ?? null) === null
                || CarbonImmutable::parse($privacy->r1_advertising_denial_until)->greaterThan(now()));
    }

    public function denial(): ?object
    {
        $row = $this->proof->choice('advertising');

        return $row && $row->legacy_denied_at && CarbonImmutable::parse($row->legacy_denial_until)->greaterThan(now()) ? $row : null;
    }

    public function denied(?object $privacy): bool
    {
        return $this->markerDenied($privacy) || $this->denial() !== null;
    }

    public function markers(object $choice): array
    {
        return ['r1_advertising_denied_at' => $choice->legacy_denied_at,
            'r1_advertising_denial_until' => $choice->legacy_denial_until];
    }

    /** Caller holds the R1 scope lock. Only existing, recognisable Acquisition privacy proofs are changed. */
    public function withdraw(object $choice): void
    {
        $r = request();
        $prefix = config('h5.workspace', 'h5');
        $raw = $r->cookie($prefix.'_acquisition_privacy_evidence', '[]');
        $ids = is_string($raw) ? json_decode($raw, true) : [];
        $ids = is_array($ids) ? array_slice($ids, -20) : [];
        $ids[] = $r->hasSession() ? $r->session()->get('tracking.privacy') : null;
        $ids[] = $r->cookie($prefix.'_acquisition_consent');
        $ids = array_values(array_filter($ids, fn ($id) => is_string($id) && Str::isUuid($id)));
        $viewer = app(IdentityContext::class)->viewer()?->id;
        $q = DB::table('acquisition_privacy');
        $viewer ? $q->where(fn ($q) => $q->where('viewer_id', $viewer)->orWhere(fn ($q) => $q->whereNull('viewer_id')->whereIn('id', $ids))) : $q->whereIn('id', $ids);
        $affected = $q->lockForUpdate()->pluck('id');
        if ($affected->isNotEmpty()) {
            DB::table('acquisition_privacy')->whereIn('id', $affected)->update($this->markers($choice) + [
                'advertising' => 'denied', 'active_source' => null, 'updated_at' => Clock::stamp(),
            ]);
            DB::table('acquisition_sources')->whereIn('privacy_id', $affected)->whereNull('revoked_at')->update(['revoked_at' => Clock::stamp()]);
        }
        if ($r->hasSession()) {
            $r->session()->forget('tracking.source');
            if ($r->session()->has('tracking.consent') || $affected->isNotEmpty()) {
                $r->session()->put('tracking.consent.advertising', 'denied');
            }
        }
    }

    /** Legacy explicit withdrawal advances an existing R1 scope; it never creates a new R1 proof. */
    public function legacyWithdrawal(): void
    {
        if (! $proof = $this->proof->current()) {
            return;
        }
        $old = $this->proof->choice('advertising');
        if ($old?->choice !== 'deny') {
            DB::table('acquisition_r1_choices')->updateOrInsert(['proof_id' => $proof->id, 'purpose' => 'advertising'], [
                'choice' => 'deny', 'owner_id' => null, 'revision' => ($old?->revision ?? 0) + 1,
                'notice_version' => 'analytics-v1', 'policy_version' => 'legacy-withdrawal', 'is_test' => true, 'policy_valid_from' => null, 'policy_valid_until' => null, 'changed_at' => Clock::stamp(), 'expires_at' => $proof->expires_at,
                'legacy_denied_at' => Clock::stamp(), 'legacy_denial_until' => $proof->expires_at,
            ]);
        }
        $this->withdraw($this->proof->choice('advertising'));
    }
}
