<?php

namespace App\Modules\Acquisition\Analytics;

use App\Contracts\IdentityContext;
use App\Modules\Acquisition\Measurement\SiteAnalysisBridge;
use App\Modules\Acquisition\Registration\LegacyAdvertisingBridge;
use App\Modules\Acquisition\Registration\PurposeProof;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrackingContext
{
    public function enabled(): bool
    {
        return config('h5.modules.acquisition.ready', false) && config('acquisition.analytics_enabled', false);
    }

    public function session(): ?Session
    {
        return request()->hasSession() ? request()->session() : null;
    }

    public function cookieName(): string
    {
        return config('h5.workspace', 'h5').'_acquisition_consent';
    }

    public function privacy(): ?object
    {
        $s = $this->session();
        $id = $s?->get('tracking.privacy');
        $restore = ! $id;
        // EncryptCookies authenticates this HttpOnly opaque value before it reaches the module.
        $id ??= request()->cookie($this->cookieName());
        $row = is_string($id) && Str::isUuid($id) ? DB::table('acquisition_privacy')->where('id', $id)->first() : null;
        if ($row && $restore && $s) {
            $s->put('tracking.privacy', $row->id);
            if ($row->current_subject) {
                $s->put('tracking.subject', $row->current_subject);
            }
            if ($row->active_source) {
                $s->put('tracking.source', $row->active_source);
            }
            if ($row->viewer_id) {
                $s->put('tracking.viewer', $row->viewer_id);
            }
        }

        return $row;
    }

    public function evidenceCookieName(): string
    {
        return config('h5.workspace', 'h5').'_acquisition_privacy_evidence';
    }

    private function evidenceIds(): array
    {
        $value = json_decode(request()->cookie($this->evidenceCookieName(), '[]'), true);

        return is_array($value) ? array_values(array_filter(array_slice($value, -20), fn ($id) => is_string($id) && Str::isUuid($id))) : [];
    }

    public function rememberConsent(string $id): void
    {
        $secure = parse_url(config('app.url'), PHP_URL_SCHEME) === 'https';
        Cookie::queue(Cookie::make($this->cookieName(), $id, 7 * 24 * 60, '/', null, $secure, true, false, 'lax'));
        $evidence = json_encode(array_slice(array_values(array_unique([...$this->evidenceIds(), $id])), -20), JSON_THROW_ON_ERROR);
        Cookie::queue(Cookie::make($this->evidenceCookieName(), $evidence, 90 * 24 * 60, '/', null, $secure, true, false, 'lax'));
        request()->cookies->set($this->evidenceCookieName(), $evidence);
    }

    private function withdrawKnown(bool $analytics, bool $advertising): void
    {
        if ($analytics && $advertising) {
            return;
        }
        $viewer = app(IdentityContext::class)->viewer()?->id;
        $ids = array_values(array_unique([...$this->evidenceIds(), ...array_filter([$this->privacy()?->id])]));
        $query = DB::table('acquisition_privacy');
        if ($viewer) {
            $query->where(fn ($q) => $q->where('viewer_id', $viewer)->orWhere(fn ($q) => $q->whereNull('viewer_id')->whereIn('id', $ids)));
        } else {
            $query->whereIn('id', $ids);
        }
        $affected = $query->pluck('id');
        $values = ['updated_at' => Clock::stamp()];
        if (! $analytics) {
            $values += ['analytics' => 'denied', 'analytics_granted_at' => null, 'current_subject' => null];
        }
        if (! $advertising) {
            $values += ['advertising' => 'denied', 'active_source' => null];
            DB::table('acquisition_sources')->whereIn('privacy_id', $affected)->whereNull('revoked_at')->update(['revoked_at' => Clock::stamp()]);
        }
        DB::table('acquisition_privacy')->whereIn('id', $affected)->update($values);
    }

    public function consent(bool $analytics, bool $advertising): void
    {
        $s = $this->session();
        if (! $s) {
            return;
        }
        $this->syncViewer();
        app(PurposeProof::class)->serialized(function () use ($s, $analytics, $advertising) {
            $bridge = app(SiteAnalysisBridge::class);
            $old = $this->privacy();
            $old = $old ? DB::table('acquisition_privacy')->where('id', $old->id)->lockForUpdate()->first() : null;
            $r1 = app(LegacyAdvertisingBridge::class);
            if (! $advertising) {
                $r1->legacyWithdrawal();
            }
            $r1Denied = $r1->denied($old);
            $advertising = $advertising && ! $r1Denied;
            $denied = $bridge->browserDenied() || $bridge->proofDenied($old);
            // C mode cannot grant legacy analysis. An existing grant remains historical until an explicit denial.
            $preserve = SiteAnalysisBridge::experience() && $analytics && ! $denied;
            $choice = $preserve ? ($old?->analytics ?? $s->get('tracking.consent.analytics', 'unknown')) : ($analytics && ! $denied ? 'granted' : 'denied');
            $this->withdrawKnown($preserve || $choice === 'granted', $advertising);
            $id = $old?->id ?? (string) Str::uuid();
            $s->put('tracking.consent', ['analytics' => $choice, 'advertising' => $advertising ? 'granted' : 'denied']);
            // A rejection alone creates no legacy tracking identity.
            if (! $old && $choice !== 'granted' && ! $advertising) {
                return;
            }
            $values = [
                'advertising' => $advertising ? 'granted' : 'denied', 'policy_version' => 'analytics-v1',
                'created_at' => $old?->created_at ?? Clock::stamp(), 'updated_at' => Clock::stamp(),
            ];
            if (! $preserve || ! $old) {
                $values += ['analytics' => $choice, 'analytics_granted_at' => $choice === 'granted'
                    ? ($old?->analytics === 'granted' ? $old->analytics_granted_at : Clock::stamp()) : null];
            }
            if ($denied) {
                // Preserve an existing deadline; repeated legacy requests cannot extend a C preference's life.
                $values += $bridge->proofDenied($old) ? [] : $bridge->denialValues();
            }
            if ($r1Denied && ! $r1->markerDenied($old) && ($r1Denial = $r1->denial())) {
                $values += $r1->markers($r1Denial);
            }
            DB::table('acquisition_privacy')->updateOrInsert(['id' => $id], $values);
            $s->put('tracking.privacy', $id);
            if ($choice === 'granted' || $advertising) {
                $this->rememberConsent($id);
            } else {
                Cookie::queue(Cookie::forget($this->cookieName()));
            }
            if (! $advertising) {
                DB::table('acquisition_sources')->where('privacy_id', $id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $s->forget('tracking.source');
                DB::table('acquisition_privacy')->where('id', $id)->update(['active_source' => null]);
            }
            if ($choice !== 'granted') {
                $s->forget('tracking.subject');
                DB::table('acquisition_privacy')->where('id', $id)->update(['current_subject' => null]);
            }
        });
    }

    public function syncViewer(?string $viewer = null): void
    {
        $s = $this->session();
        if (! $s) {
            return;
        }
        $this->privacy();
        $viewer ??= app(IdentityContext::class)->viewer()?->id;
        $old = $s->get('tracking.viewer');
        if ($old && $old !== $viewer) {
            // Do not stitch two accounts or an authenticated and a newly anonymous chain.
            $this->clear();
        }
        if (SiteAnalysisBridge::experience() || app(SiteAnalysisBridge::class)->browserDenied()) {
            return;
        }
        if ($viewer) {
            DB::transaction(function () use ($viewer, $s) {
                $p = $this->privacy();
                $p = $p ? DB::table('acquisition_privacy')->where('id', $p->id)->lockForUpdate()->first() : null;
                if (app(SiteAnalysisBridge::class)->proofDenied($p)) {
                    return;
                }
                $s->put('tracking.viewer', $viewer);
                if ($p) {
                    DB::table('acquisition_privacy')->where('id', $p->id)->update(['viewer_id' => $viewer]);
                }
            });
        }
    }

    public function subject(?string $viewer = null): ?object
    {
        $s = $this->session();
        if (! $s || ! $this->enabled()) {
            return null;
        }
        if (SiteAnalysisBridge::experience() || app(SiteAnalysisBridge::class)->browserDenied()) {
            return null;
        }
        $this->syncViewer($viewer);

        return DB::transaction(function () use ($viewer, $s) {
            $privacy = $this->privacy();
            $privacy = $privacy ? DB::table('acquisition_privacy')->where('id', $privacy->id)->lockForUpdate()->first() : null;
            if (! $privacy || $privacy->analytics !== 'granted' || app(SiteAnalysisBridge::class)->proofDenied($privacy)) {
                return null;
            }
            $viewer ??= app(IdentityContext::class)->viewer()?->id;
            $subjectId = $s->get('tracking.subject');
            $row = is_string($subjectId) && Str::isUuid($subjectId) ? DB::table('acquisition_subjects')->where('id', $subjectId)->first() : null;
            if (! $row || $row->privacy_id !== $privacy->id || ($row->viewer_id && $row->viewer_id !== $viewer)) {
                $id = (string) Str::uuid();
                DB::table('acquisition_subjects')->insert(['id' => $id, 'privacy_id' => $privacy->id, 'viewer_id' => $viewer,
                    'session_key' => (string) Str::uuid(), 'created_at' => Clock::stamp(), 'updated_at' => Clock::stamp()]);
                $s->put('tracking.subject', $id);
                DB::table('acquisition_privacy')->where('id', $privacy->id)->update(['current_subject' => $id]);
                $row = DB::table('acquisition_subjects')->where('id', $id)->first();
            } elseif ($viewer && ! $row->viewer_id) {
                DB::table('acquisition_subjects')->where('id', $row->id)->update(['viewer_id' => $viewer, 'updated_at' => Clock::stamp()]);
                $row->viewer_id = $viewer;
            }

            return $row;
        });
    }

    public function clear(): void
    {
        $this->session()?->forget('tracking');
        request()->cookies->remove($this->cookieName());
        Cookie::queue(Cookie::forget($this->cookieName()));
        // Keep old immutable consent evidence for delayed order facts. Only an explicit withdrawal revokes it.
    }

    public function publicContext(): array
    {
        $this->syncViewer();
        $p = $this->privacy();
        $pending = $this->session()?->get('tracking.pending');
        if ($pending && CarbonImmutable::parse($pending['created_at'])->addDay()->lessThanOrEqualTo(now())) {
            $this->session()->forget('tracking.pending');
        }

        return ['enabled' => $this->enabled(), 'consent' => $p ? ['analytics' => $p->analytics, 'advertising' => $p->advertising] : ($this->session()?->get('tracking.consent') ?? ['analytics' => 'unknown', 'advertising' => 'unknown']),
            'policy_version' => 'analytics-v1', 'pending_landing' => (bool) $this->session()?->has('tracking.pending')];
    }
}
