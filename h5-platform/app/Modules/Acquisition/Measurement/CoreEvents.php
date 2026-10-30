<?php

namespace App\Modules\Acquisition\Measurement;

use App\Contracts\CatalogReader;
use App\Modules\Acquisition\Analytics\EventProperties;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CoreEvents
{
    public const NAMES = ['page_view', 'drama_view', 'play_requested', 'play_started', 'watch_progress', 'episode_completed',
        'player_startup', 'player_stall', 'player_error', 'paywall_view', 'subscribe_click', 'registration_view', 'registration_submit',
        'verification_view', 'checkout_click', 'payment_return_view'];

    private const PAGES = ['home', 'catalog', 'drama', 'player', 'membership', 'registration', 'login', 'verification', 'checkout', 'payment_return', 'account'];

    public function validate(string $name, array $p, bool $catalog): array
    {
        $per = match ($name) {
            'watch_progress', 'episode_completed' => ['position_seconds', 'duration_seconds', 'watched_seconds'],
            'play_requested', 'play_started' => ['position_seconds', 'duration_seconds'],
            'player_startup' => ['startup_ms'], 'player_stall' => ['stall_ms'], 'player_error' => ['error_code'], default => [],
        };
        $this->require(in_array($name, self::NAMES, true) && ! array_diff(array_keys($p), ['page_instance_id', 'page_type', 'locale', 'drama_id', 'episode_id', 'play_instance_id', ...$per]));
        $this->require(! in_array(null, $p, true));
        $this->require(isset($p['page_instance_id']) && is_string($p['page_instance_id']) && Str::isUuid($p['page_instance_id']));
        foreach (['drama_id', 'episode_id', 'play_instance_id'] as $key) {
            $this->require(! isset($p[$key]) || (is_string($p[$key]) && Str::isUuid($p[$key])));
        }
        $this->require(! isset($p['page_type']) || in_array($p['page_type'], self::PAGES, true));
        $this->require(! isset($p['locale']) || in_array($p['locale'], ['en', 'zh_CN'], true));
        $this->require(! isset($p['episode_id']) || isset($p['drama_id']));
        $play = in_array($name, ['play_requested', 'play_started', 'watch_progress', 'episode_completed', 'player_startup', 'player_stall', 'player_error'], true);
        $this->require(! $play || isset($p['drama_id'], $p['episode_id'], $p['play_instance_id']));
        foreach (['position_seconds' => 86400, 'duration_seconds' => 86400, 'watched_seconds' => 604800, 'startup_ms' => 600000, 'stall_ms' => 600000] as $key => $max) {
            $this->require(! isset($p[$key]) || ((is_float($p[$key]) || is_int($p[$key])) && is_finite((float) $p[$key]) && $p[$key] >= 0 && $p[$key] <= $max));
        }
        $metric = match ($name) {
            'watch_progress', 'episode_completed' => 'watched_seconds', 'player_startup' => 'startup_ms', 'player_stall' => 'stall_ms',
            'player_error' => 'error_code', 'play_started' => 'position_seconds', default => null,
        };
        $this->require($metric === null || isset($p[$metric]));
        $this->require($name !== 'play_started' || $p['position_seconds'] > 0);
        $this->require(! isset($p['error_code']) || in_array($p['error_code'], EventProperties::ERROR_CODES, true));
        if ($catalog && isset($p['drama_id'])) {
            try {
                $drama = app(CatalogReader::class)->drama($p['drama_id'], $p['locale'] ?? 'en');
                $this->require(! isset($p['episode_id']) || collect($drama->episodes)->contains(fn ($e) => $e->id === $p['episode_id']));
            } catch (ApiProblem $e) {
                if ($e->status >= 500) {
                    throw $e;
                }
                $this->require(false);
            }
        }
        ksort($p);

        return $p;
    }

    private function require(bool $valid): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['events' => 'Invalid measurement event.']);
        }
    }

    public function record(array $event, CoreContext $context): bool
    {
        return $context->serialized(function () use ($event, $context) {
            $active = $context->active();
            if (! $active) {
                $context->end();

                return false;
            }
            [$subject, $session] = $active;
            $p = $event['properties'];
            $name = $event['event_name'];
            $fingerprint = hash('sha256', json_encode([$name, $p], JSON_THROW_ON_ERROR));
            $old = DB::table('acquisition_c_event_keys')->where('id', $event['event_id'])->first();
            if ($old) {
                return $old->subject_id === $subject->id && hash_equals($old->fingerprint, $fingerprint);
            }
            // Stable semantics prevent repeated initialization with fresh envelope IDs from increasing counts.
            $semantic = match ($name) {
                'page_view', 'drama_view' => $name.':'.$session->id.':'.$p['page_instance_id'],
                'play_started', 'episode_completed' => $name.':'.$subject->id.':'.$p['play_instance_id'],
                default => 'event:'.$event['event_id'],
            };
            $dedup = hash('sha256', $semantic);
            $existing = DB::table('acquisition_c_events')->where('dedup_key', $dedup)->first();
            if ($existing && ($existing->subject_id !== $subject->id || ! hash_equals($existing->fingerprint, $fingerprint))) {
                return false;
            }
            if (! $existing && isset($p['play_instance_id'])) {
                if (! $this->play($name, $p, $subject, $session)) {
                    return false;
                }
            }
            DB::table('acquisition_c_event_keys')->insert(['id' => $event['event_id'], 'subject_id' => $subject->id,
                'fingerprint' => $fingerprint, 'created_at' => now()]);
            if (! $existing) {
                DB::table('acquisition_c_events')->insert(['id' => $event['event_id'], 'dedup_key' => $dedup, 'fingerprint' => $fingerprint,
                    'subject_id' => $subject->id, 'measurement_session_id' => $session->id, 'event_name' => $name,
                    'schema_version' => 2, 'basis_kind' => 'policy_permitted', 'policy_version' => $subject->policy_version,
                    'notice_version' => $subject->notice_version, 'is_test' => true, 'properties' => json_encode($p, JSON_THROW_ON_ERROR), 'received_at' => now()]);
                DB::table('acquisition_c_sessions')->where('id', $session->id)->update(['last_seen_at' => now()]);
            }

            return true;
        });
    }

    private function play(string $name, array &$p, object $subject, object $session): bool
    {
        $q = DB::table('acquisition_c_plays')->where('subject_id', $subject->id)->where('play_instance_id', $p['play_instance_id']);
        $play = $q->first();
        if ($play && ($play->episode_id !== $p['episode_id'] || $play->measurement_session_id !== $session->id)) {
            return false;
        }
        if (! $play) {
            if (in_array($name, ['watch_progress', 'episode_completed'], true)) {
                return false;
            }
            $q->insert(['subject_id' => $subject->id, 'play_instance_id' => $p['play_instance_id'], 'measurement_session_id' => $session->id,
                'episode_id' => $p['episode_id'], 'started' => false, 'watched_seconds' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $play = $q->first();
        }
        $watched = $play->watched_seconds;
        if (isset($p['watched_seconds'])) {
            if (! $play->started || $p['watched_seconds'] < $watched) {
                return false;
            }
            $elapsed = max(0, CarbonImmutable::parse($play->created_at)->diffInSeconds(now(), false));
            $watched = min((float) $p['watched_seconds'], $elapsed + 1);
            if ($name === 'episode_completed') {
                $drama = app(CatalogReader::class)->drama($p['drama_id'], $p['locale'] ?? 'en');
                $episode = collect($drama->episodes)->first(fn ($e) => $e->id === $p['episode_id']);
                if (! $episode || $episode->duration_seconds <= 0 || $watched < $episode->duration_seconds * .9) {
                    return false;
                }
            }
            // Only actual cumulative watch time contributes; position/seek never does.
            $p['watched_seconds'] = $watched;
            $p['watched_delta'] = max(0, $watched - $play->watched_seconds);
        }
        $q->update(['started' => $play->started || $name === 'play_started', 'watched_seconds' => $watched, 'updated_at' => now()]);

        return true;
    }
}
