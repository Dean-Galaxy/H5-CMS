<?php

namespace App\Modules\Acquisition\Http;

use App\Enums\BehaviorRecordingResult;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Modules\Acquisition\Analytics\ClickContext;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Services\AttributionRecorder;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class TrackingController
{
    public function context(TrackingContext $context)
    {
        if (! $context->enabled()) {
            return response()->json(['enabled' => false, 'consent' => ['analytics' => 'unknown', 'advertising' => 'unknown'], 'policy_version' => 'analytics-v1', 'pending_landing' => false])->header('Cache-Control', 'no-store');
        }
        try {
            return response()->json($context->publicContext())->header('Cache-Control', 'no-store');
        } catch (Throwable) {
            return response()->json(['error' => 'TRACKING_UNAVAILABLE'], 503)->header('Cache-Control', 'no-store');
        }
    }

    public function consent(Request $r, TrackingContext $context)
    {
        if (! $context->enabled()) {
            return $this->context($context);
        }
        if (strlen($r->getContent()) > 1024) {
            return $this->tooLarge()->header('Cache-Control', 'no-store');
        }
        abort_if(array_diff(array_keys($r->all()), ['analytics', 'advertising', 'policy_version']), 422);
        abort_unless(is_bool($r->input('analytics')) && is_bool($r->input('advertising')), 422);
        $input = $r->validate(['analytics' => ['required', 'boolean'], 'advertising' => ['required', 'boolean'], 'policy_version' => ['required', 'in:analytics-v1']]);
        try {
            $context->consent((bool) $input['analytics'], (bool) $input['advertising']);
        } catch (Throwable) {
            app(BehaviorRecorder::class)->diagnostic('consent_failed');

            return response()->json(['error' => 'TRACKING_UNAVAILABLE'], 503)->header('Cache-Control', 'no-store');
        }

        return $this->context($context);
    }

    public function events(Request $r, TrackingContext $context, BehaviorRecorder $recorder)
    {
        if (strlen($r->getContent()) > 32768) {
            return $this->tooLarge()->header('Cache-Control', 'no-store');
        }
        abort_if(array_diff(array_keys($r->all()), ['events']), 422);
        abort_unless(is_array($r->input('events')) && array_is_list($r->input('events')), 422);
        $input = Validator::make($r->all(), ['events' => ['required', 'array', 'min:1', 'max:20'], 'events.*' => ['array'],
            'events.*.event_id' => ['required', 'uuid'], 'events.*.event_name' => ['required', 'string', 'max:40'], 'events.*.properties' => ['present', 'array']])->validate();
        $accepted = 0;
        $rejected = 0;
        foreach ($input['events'] as $event) {
            if (array_diff(array_keys($event), ['event_id', 'event_name', 'properties'])) {
                $recorder->diagnostic('browser_envelope_rejected');
                $rejected++;

                continue;
            }
            $result = $context->enabled() ? $recorder->browser($event['event_id'], $event['event_name'], $event['properties']) : BehaviorRecordingResult::Skipped;
            if ($result === BehaviorRecordingResult::Retry) {
                return response()->json(['error' => 'TRACKING_UNAVAILABLE'], 503)->header('Cache-Control', 'no-store');
            }
            $result === BehaviorRecordingResult::Accepted ? $accepted++ : $rejected++;
        }

        return response()->json(compact('accepted', 'rejected'))->header('Cache-Control', 'no-store');
    }

    public function confirmLanding(Request $r, TrackingContext $context, AttributionRecorder $attribution)
    {
        if (strlen($r->getContent()) > 4096) {
            return $this->tooLarge()->header('Cache-Control', 'no-store');
        }
        abort_if(array_diff(array_keys($r->all()), ['page_instance_id', 'drama_id', 'path', 'click_context']), 422);
        $p = $r->validate(['page_instance_id' => ['required', 'uuid'], 'drama_id' => ['required', 'uuid'], 'path' => ['required', 'string', 'max:120'], 'click_context' => ['sometimes', 'array']]);
        abort_unless($p['path'] === '/dramas/'.$p['drama_id'] || preg_match('~^/dramas/'.preg_quote($p['drama_id'], '~').'/episodes/[a-f0-9-]{36}$~D', $p['path']), 422);
        if (! $context->enabled()) {
            return response()->json(['confirmed' => false])->header('Cache-Control', 'no-store');
        }
        try {
            $click = app(ClickContext::class)->validate($p['click_context'] ?? []);

            return response()->json(['confirmed' => $attribution->confirm($p['drama_id'], $p['page_instance_id'], $click)])->header('Cache-Control', 'no-store');
        } catch (ApiProblem $e) {
            if ($e->status === 422) {
                app(BehaviorRecorder::class)->diagnostic('click_context_rejected');

                return response()->json(['error' => 'VALIDATION_FAILED'], 422)->header('Cache-Control', 'no-store');
            }
            if ($e->status >= 500) {
                app(BehaviorRecorder::class)->diagnostic('catalog_unavailable');

                return response()->json(['error' => 'TRACKING_UNAVAILABLE'], 503)->header('Cache-Control', 'no-store');
            }

            return response()->json(['confirmed' => false])->header('Cache-Control', 'no-store');
        } catch (Throwable) {
            return response()->json(['error' => 'TRACKING_UNAVAILABLE'], 503)->header('Cache-Control', 'no-store');
        }
    }

    private function tooLarge()
    {
        return response()->json(['error' => 'PAYLOAD_TOO_LARGE'], 413)->header('Cache-Control', 'no-store');
    }

    public function landing(string $code, TrackingContext $context, AttributionRecorder $attribution)
    {
        if (! $context->enabled()) {
            throw new DependencyNotReady('Acquisition：站内采集未显式启用');
        }

        return redirect($attribution->prepare($code))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'same-origin');
    }
}
