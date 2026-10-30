<?php

namespace App\Modules\Acquisition\Registration;

use App\Contracts\IdentityContext;
use App\Modules\Acquisition\Analytics\Clock;
use App\Modules\Acquisition\Measurement\CoreContext;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PurposeStore
{
    public function __construct(private PurposeProof $proof, private PurposePolicy $policy, private LegacyAdvertisingBridge $legacy) {}

    public function state(string $purpose, ?object $row = null): array
    {
        $row ??= $this->proof->choice($purpose);
        $viewer = app(IdentityContext::class)->viewer()?->id;
        $choice = $row?->choice ?? 'unknown';
        if ($choice === 'allow' && ($row->owner_id === null || $row->owner_id !== $viewer)) {
            $choice = 'unknown';
        }
        $policy = $this->policy->resolve();
        $reason = $policy['reason'] ?? ($purpose === 'account_link' ? 'ASSOCIATION_NOT_READY' : 'EXTERNAL_DISABLED');
        if ($choice === 'deny') {
            $reason = 'PURPOSE_OPTOUT';
        } elseif ($purpose === 'account_link' && app(CoreContext::class)->publicContext()['site_choice'] === 'deny') {
            $reason = 'SITE_OPTOUT';
        } elseif ($purpose === 'advertising' && app(CoreContext::class)->legacy()['advertising'] === 'denied') {
            $reason = 'LEGACY_DENIED';
        }

        return ['choice' => $choice, 'effective' => false, 'reason' => $reason,
            'capability' => $policy['eligibility'] === 'eligible' ? 'choice_only' : 'unavailable',
            'revision' => (int) ($row?->revision ?? 0), 'choice_scope' => 'current_browser'];
    }

    public function context(): array
    {
        $core = app(CoreContext::class)->publicContext();
        $legacy = ['granted' => 'allow', 'denied' => 'deny', 'unknown' => 'unknown'][$core['advertising']['choice']];

        return ['schema_version' => 3, 'policy_version' => PurposePolicy::VERSION, 'notice_version' => PurposePolicy::NOTICE,
            'is_test' => true, 'eligibility' => $this->policy->resolve()['eligibility'],
            'site_session' => ['choice' => $core['site_choice'], 'effective' => $core['site_effective'], 'reason' => $core['reason']],
            'purposes' => ['account_link' => $this->state('account_link'), 'advertising' => $this->state('advertising')],
            'legacy_advertising' => ['choice' => $legacy]];
    }

    public function change(string $purpose, string $choice, string $notice, int $revision, string $operation): array
    {
        if (! in_array($purpose, PurposePolicy::PURPOSES, true) || ! in_array($choice, ['allow', 'deny'], true)
            || $revision < 0 || $revision >= 2147483647 || ! Str::isUuid($operation)) {
            throw new ApiProblem('VALIDATION_FAILED', 422, '选择请求无效');
        }
        if (! app()->environment('local', 'testing') || ! config('h5.modules.acquisition.ready', false) || ! request()->hasSession()) {
            throw new ApiProblem('DEPENDENCY_NOT_READY', 503, '用途提供方未就绪');
        }
        $actor = app(IdentityContext::class)->viewer()?->id;
        $fingerprint = hash('sha256', json_encode([$purpose, $choice, $notice, $revision], JSON_THROW_ON_ERROR));
        // Operation lock precedes scope lock everywhere; legacy has no operation lock.
        [$receipt, $savedProof, $remember] = DB::transaction(function () use ($purpose, $choice, $notice, $revision, $operation, $actor, $fingerprint) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.r1.operation:'.$operation]);

            return $this->proof->serialized(function () use ($purpose, $choice, $notice, $revision, $operation, $actor, $fingerprint) {
                $proof = $this->proof->current();
                $row = $this->proof->choice($purpose);
                if ($notice !== PurposePolicy::NOTICE) {
                    throw new PurposeConflict('NOTICE_CONFLICT', $this->state($purpose, $row));
                }
                DB::table('acquisition_r1_operations')->where('id', $operation)->where('expires_at', '<=', Clock::stamp())->delete();
                $old = DB::table('acquisition_r1_operations')->where('id', $operation)->first();
                if ($old) {
                    if (! $proof || $old->proof_id !== $proof->id || $old->actor_id !== $actor || ! hash_equals($old->fingerprint, $fingerprint)) {
                        throw new PurposeConflict('OPERATION_CONFLICT', $this->state($purpose, $row));
                    }

                    return [['schema_version' => 3, 'purpose' => $purpose, 'status' => $old->status,
                        'state' => $this->state($purpose, $row)], $proof, false];
                }
                if ($choice === 'allow' && $actor === null) {
                    throw new ApiProblem('AUTHENTICATION_REQUIRED', 401, '请先登录');
                }
                if ($choice === 'allow' && $this->policy->resolve()['eligibility'] !== 'eligible') {
                    throw new ApiProblem('DEPENDENCY_NOT_READY', 503, '用途选择策略未就绪');
                }
                if ($revision !== (int) ($row?->revision ?? 0)) {
                    throw new PurposeConflict('REVISION_CONFLICT', $this->state($purpose, $row));
                }
                $fresh = ! $proof;
                if (! $proof) {
                    $proof = (object) ['id' => (string) Str::uuid(), 'created_at' => Clock::stamp(), 'expires_at' => Clock::stamp(now()->addMonthsNoOverflow(24)), 'is_test' => true];
                    DB::table('acquisition_r1_proofs')->insert((array) $proof);
                }
                $unchanged = $row && $row->choice === $choice && ($choice === 'deny' || $row->owner_id === $actor);
                if (! $unchanged) {
                    $values = ['choice' => $choice, 'owner_id' => $choice === 'allow' ? $actor : null,
                        'revision' => ($row?->revision ?? 0) + 1, 'notice_version' => $notice,
                        'changed_at' => Clock::stamp(), 'expires_at' => $proof->expires_at,
                        'policy_version' => PurposePolicy::VERSION, 'is_test' => true,
                        'policy_valid_from' => $choice === 'allow' ? Clock::stamp(config('acquisition.registration.synthetic_from')) : null,
                        'policy_valid_until' => $choice === 'allow' ? Clock::stamp(config('acquisition.registration.synthetic_until')) : null];
                    if ($purpose === 'advertising' && $choice === 'deny') {
                        $values += ['legacy_denied_at' => Clock::stamp(), 'legacy_denial_until' => $proof->expires_at];
                    }
                    DB::table('acquisition_r1_choices')->updateOrInsert(['proof_id' => $proof->id, 'purpose' => $purpose], $values);
                    $row = DB::table('acquisition_r1_choices')->where('proof_id', $proof->id)->where('purpose', $purpose)->first();
                }
                if ($purpose === 'advertising' && $choice === 'deny') {
                    $this->legacy->withdraw($row);
                }
                $status = $unchanged ? 'unchanged' : 'saved';
                DB::table('acquisition_r1_operations')->insert(['id' => $operation, 'proof_id' => $proof->id, 'actor_id' => $actor,
                    'purpose' => $purpose, 'fingerprint' => $fingerprint, 'status' => $status, 'created_at' => Clock::stamp(),
                    'expires_at' => Clock::stamp(min(now()->addDay(), CarbonImmutable::parse($proof->expires_at)))]);

                return [['schema_version' => 3, 'purpose' => $purpose, 'status' => $status, 'state' => $this->state($purpose, $row)], $proof, $fresh || ! $unchanged];
            });
        });
        if ($remember) {
            $this->proof->remember($savedProof);
        }

        return $receipt;
    }
}
