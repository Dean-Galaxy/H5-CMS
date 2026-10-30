<?php

namespace App\Modules\Acquisition\Measurement;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CoreContext
{
    public function __construct(private CorePolicy $policy) {}

    public function cookie(string $kind): string
    {
        return config('h5.workspace', 'h5').'_c_'.$kind;
    }

    private function pointer(string $kind): ?string
    {
        $id = request()->cookie($this->cookie($kind)) ?? (request()->hasSession() ? request()->session()->get('measurement_c.'.$kind) : null);

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }

    private function remember(string $kind, string $id, $until): void
    {
        $minutes = max(1, (int) ceil(now()->diffInMinutes(CarbonImmutable::parse($until), false)));
        Cookie::queue(Cookie::make($this->cookie($kind), $id, $minutes, '/', null,
            request()->isSecure() || parse_url(config('app.url'), PHP_URL_SCHEME) === 'https', true, false, 'lax'));
        request()->cookies->set($this->cookie($kind), $id);
        request()->session()->put('measurement_c.'.$kind, $id);
    }

    public function preference(): ?object
    {
        $id = $this->pointer('preference');

        return $id ? DB::table('acquisition_c_preferences')->where('id', $id)->where('expires_at', '>', now())->first() : null;
    }

    /** Read only the current legacy proof; never call syncViewer/consent or inspect identity tables. */
    public function legacy(): array
    {
        $s = request()->hasSession() ? request()->session() : null;
        $choice = $s?->get('tracking.consent', []) ?? [];
        $prefix = config('h5.workspace', 'h5');
        $id = $s?->get('tracking.privacy') ?? request()->cookie($prefix.'_acquisition_consent');
        $row = is_string($id) && Str::isUuid($id) ? DB::table('acquisition_privacy')->where('id', $id)->first() : null;
        $denied = ($choice['analytics'] ?? null) === 'denied' || ($row?->analytics === 'denied');
        $evidence = json_decode(request()->cookie($prefix.'_acquisition_privacy_evidence', '[]'), true);
        $ids = is_array($evidence) ? array_filter(array_slice($evidence, -20), fn ($v) => is_string($v) && Str::isUuid($v)) : [];
        if ($ids) {
            $denied = $denied || DB::table('acquisition_privacy')->whereIn('id', $ids)->where('analytics', 'denied')->exists();
        }
        $ads = $row?->advertising ?? ($choice['advertising'] ?? 'unknown');

        return ['denied' => $denied, 'advertising' => in_array($ads, ['granted', 'denied'], true) ? $ads : 'unknown'];
    }

    public function gate(): array
    {
        $p = $this->policy->resolve();
        $pref = $this->preference();
        $legacy = $this->legacy();
        $reason = $p['reason'];
        if ($reason === null && $legacy['denied']) {
            $reason = 'LEGACY_DENIED';
        }
        if ($reason === null && $pref?->choice === 'deny') {
            $reason = 'SITE_OPTOUT';
        }

        return [$p, $pref, $legacy, $reason];
    }

    /** Context reads do not create, extend, end or restore any measurement record/cookie. */
    public function active(?array $gate = null): ?array
    {
        [$policy, $pref, , $reason] = $gate ?? $this->gate();
        if ($reason !== null || ! $pref) {
            return null;
        }
        $id = $this->pointer('subject');
        $sid = $this->pointer('session');
        if (! $id || ! $sid) {
            return null;
        }
        $subject = DB::table('acquisition_c_subjects')->where('id', $id)->where('preference_id', $pref->id)
            ->where('policy_version', $policy['version'])->where('notice_version', CorePolicy::NOTICE)
            ->where('is_test', true)->whereNull('ended_at')->where('expires_at', '>', now())->first();
        $session = $subject ? DB::table('acquisition_c_sessions')->where('id', $sid)->where('subject_id', $subject->id)
            ->whereNull('ended_at')->where('expires_at', '>', now())->where('last_seen_at', '>', now()->subMinutes(30))->first() : null;

        return $subject && $session ? [$subject, $session] : null;
    }

    public function publicContext(): array
    {
        $gate = $this->gate();
        [$policy, $pref, $legacy, $reason] = $gate;
        $on = $reason === null && $this->active($gate) !== null;

        return ['schema_version' => 2, 'enabled' => $policy['enabled'], 'policy_version' => CorePolicy::VERSION,
            'eligibility' => $policy['eligibility'], 'basis_kind' => $policy['basis_kind'],
            'site_choice' => $pref?->choice ?? ($legacy['denied'] ? 'deny' : 'unset'), 'site_effective' => $on ? 'on' : 'off',
            'notice_version' => CorePolicy::NOTICE, 'reason' => $reason ?? ($on ? null : 'NOTICE_NOT_READY'),
            'account_link' => ['choice' => 'unknown', 'effective' => false, 'reason' => 'DEPENDENCY_NOT_READY'],
            'conversion_link' => ['mode' => 'off', 'effective' => false],
            // C has no advertising operation. A detected signal is not proof of legacy end-to-end enforcement.
            'advertising' => ['choice' => $legacy['advertising'], 'effective' => false, 'opt_out_signal' => request()->header('Sec-GPC') === '1'],
            'revision' => (int) ($pref?->revision ?? 0)];
    }

    private function lockPreference(): ?object
    {
        // Used solely for serialization, never as a measurement identifier or stored fingerprint.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.c:'.hash('sha256', $this->pointer('preference') ?? request()->session()->getId())]);
        $p = $this->preference();

        return $p ? DB::table('acquisition_c_preferences')->where('id', $p->id)->lockForUpdate()->first() : null;
    }

    private function createPreference(string $choice = 'unset', int $revision = 0): object
    {
        $id = (string) Str::uuid();
        DB::table('acquisition_c_preferences')->insert(['id' => $id, 'choice' => $choice, 'revision' => $revision,
            'created_at' => now(), 'updated_at' => now(), 'expires_at' => $choice === 'unset' ? now()->addDays(7) : now()->addMonthsNoOverflow(24)]);

        return DB::table('acquisition_c_preferences')->where('id', $id)->first();
    }

    public function serialized(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            $this->lockPreference();

            return $operation();
        });
    }

    public function start(): void
    {
        $result = DB::transaction(function () {
            $this->lockPreference();
            [$policy, $pref, , $reason] = $this->gate();
            if ($reason !== null) {
                return null;
            }
            $pref ??= $this->createPreference();
            if ($active = $this->active()) {
                return [$pref, ...$active];
            }
            $subjectId = $this->pointer('subject');
            $subject = $subjectId ? DB::table('acquisition_c_subjects')->where('id', $subjectId)->where('preference_id', $pref->id)
                ->where('policy_version', $policy['version'])->where('notice_version', CorePolicy::NOTICE)->whereNull('ended_at')->where('expires_at', '>', now())->first() : null;
            if (! $subject) {
                $subjectId = (string) Str::uuid();
                DB::table('acquisition_c_subjects')->insert(['id' => $subjectId, 'preference_id' => $pref->id,
                    'policy_version' => $policy['version'], 'notice_version' => CorePolicy::NOTICE, 'is_test' => true,
                    'created_at' => now(), 'expires_at' => now()->addDays(7)]);
                $subject = DB::table('acquisition_c_subjects')->where('id', $subjectId)->first();
            }
            DB::table('acquisition_c_sessions')->where('subject_id', $subject->id)->whereNull('ended_at')->update(['ended_at' => now()]);
            $sid = (string) Str::uuid();
            DB::table('acquisition_c_sessions')->insert(['id' => $sid, 'subject_id' => $subject->id, 'created_at' => now(),
                'last_seen_at' => now(), 'expires_at' => min(CarbonImmutable::parse($subject->expires_at), CarbonImmutable::now()->addDay())]);

            return [$pref, $subject, DB::table('acquisition_c_sessions')->where('id', $sid)->first()];
        });
        if ($result) {
            [$pref, $subject, $session] = $result;
            $this->remember('preference', $pref->id, $pref->expires_at);
            $this->remember('subject', $subject->id, $subject->expires_at);
            $this->remember('session', $session->id, $session->expires_at);
        }
    }

    /** False means revision conflict. Allow never starts a measurement subject/session. */
    public function choose(string $choice, int $revision): bool
    {
        if (! app()->environment('local', 'testing') || ! config('h5.modules.acquisition.ready', false)) {
            throw new \LogicException('C preferences require the isolated module.');
        }
        $pref = DB::transaction(function () use ($choice, $revision) {
            $p = $this->lockPreference();
            if ((int) ($p?->revision ?? 0) !== $revision) {
                return null;
            }
            if (! $p) {
                return $this->createPreference($choice, 1);
            }
            DB::table('acquisition_c_subjects')->where('preference_id', $p->id)->whereNull('ended_at')->update(['ended_at' => now()]);
            DB::table('acquisition_c_sessions')->whereIn('subject_id', DB::table('acquisition_c_subjects')->where('preference_id', $p->id)->select('id'))
                ->whereNull('ended_at')->update(['ended_at' => now()]);
            DB::table('acquisition_c_preferences')->where('id', $p->id)->update(['choice' => $choice, 'revision' => $revision + 1,
                'updated_at' => now(), 'expires_at' => now()->addMonthsNoOverflow(24)]);

            return DB::table('acquisition_c_preferences')->where('id', $p->id)->first();
        });
        if (! $pref) {
            return false;
        }
        $this->forgetActive();
        $this->remember('preference', $pref->id, $pref->expires_at);

        return true;
    }

    private function forgetActive(): void
    {
        foreach (['subject', 'session'] as $kind) {
            if (! $this->pointer($kind)) {
                continue;
            }
            request()->cookies->remove($this->cookie($kind));
            if (request()->hasSession()) {
                request()->session()->forget('measurement_c.'.$kind);
            }
            Cookie::queue(Cookie::forget($this->cookie($kind)));
        }
    }

    /** Existing module logout hook only; does not withdraw legacy order/source permissions. */
    public function end(): void
    {
        try {
            $id = $this->pointer('subject');
            if ($id) {
                DB::transaction(function () use ($id) {
                    $pref = $this->lockPreference();
                    if ($pref && DB::table('acquisition_c_subjects')->where('id', $id)->where('preference_id', $pref->id)->exists()) {
                        DB::table('acquisition_c_subjects')->where('id', $id)->whereNull('ended_at')->update(['ended_at' => now()]);
                        DB::table('acquisition_c_sessions')->where('subject_id', $id)->whereNull('ended_at')->update(['ended_at' => now()]);
                    }
                });
            }
        } finally {
            // Always remove browser capabilities, even when C storage is temporarily unavailable.
            $this->forgetActive();
        }
    }
}
