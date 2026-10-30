<?php

namespace App\Modules\Acquisition\Measurement;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CoreController
{
    private function safely(callable $operation)
    {
        try {
            // Savepoint protects an enclosing business transaction from a failed analysis read/write.
            return DB::transaction($operation);
        } catch (ValidationException) {
            return response()->json(['error' => 'VALIDATION_FAILED'], 422);
        } catch (HttpExceptionInterface $e) {
            return response()->json(['error' => 'MEASUREMENT_REQUEST_REJECTED'], $e->getStatusCode(), $e->getHeaders());
        } catch (\Throwable) {
            return CoreRequest::failure(request());
        }
    }

    public function context(CoreContext $context)
    {
        return $this->safely(fn () => $this->contextAction($context));
    }

    public function start(Request $r, CoreContext $context)
    {
        return $this->safely(fn () => $this->startAction($r, $context));
    }

    public function preferences(Request $r, CoreContext $context)
    {
        return $this->safely(fn () => $this->preferencesAction($r, $context));
    }

    public function events(Request $r, CoreContext $context, CoreEvents $events)
    {
        return $this->safely(fn () => $this->eventsAction($r, $context, $events));
    }

    private function contextAction(CoreContext $context)
    {
        return response()->json($context->publicContext());
    }

    private function input(Request $r, array $keys, int $max = 1024): array
    {
        abort_if(strlen($r->getContent()) > $max, 413);
        $p = json_decode($r->getContent(), true);
        abort_unless($r->isJson() && is_array($p) && ! array_is_list($p) && ! array_diff(array_keys($p), $keys), 422);
        abort_if($r->query->count() > 0, 422);

        return $p;
    }

    private function notice(array $p): void
    {
        abort_unless(($p['notice_version'] ?? null) === CorePolicy::NOTICE, 422);
    }

    private function startAction(Request $r, CoreContext $context)
    {
        $p = $this->input($r, ['notice_version', 'page_instance_id']);
        $this->notice($p);
        abort_unless(is_string($p['page_instance_id'] ?? null) && Str::isUuid($p['page_instance_id']), 422);
        $context->start();

        return $this->contextAction($context);
    }

    private function preferencesAction(Request $r, CoreContext $context)
    {
        $p = $this->input($r, ['site_session', 'notice_version', 'expected_revision']);
        $this->notice($p);
        abort_unless(in_array($p['site_session'] ?? null, ['allow', 'deny'], true)
            && is_int($p['expected_revision'] ?? null) && $p['expected_revision'] >= 0 && $p['expected_revision'] < 2147483647, 422);
        $saved = $context->choose($p['site_session'], $p['expected_revision']);

        return response()->json($context->publicContext(), $saved ? 200 : 409);
    }

    private function eventsAction(Request $r, CoreContext $context, CoreEvents $events)
    {
        $p = $this->input($r, ['events'], 32768);
        abort_unless(is_array($p['events'] ?? null) && array_is_list($p['events']) && count($p['events']) > 0 && count($p['events']) <= 20, 422);
        $active = $context->active() !== null;
        foreach ($p['events'] as &$event) {
            abort_unless(is_array($event) && ! array_diff(array_keys($event), ['event_id', 'event_name', 'properties'])
                && is_string($event['event_id'] ?? null) && Str::isUuid($event['event_id'])
                && is_string($event['event_name'] ?? null) && is_array($event['properties'] ?? null), 422);
            $event['properties'] = $events->validate($event['event_name'], $event['properties'], $active);
        }
        unset($event);
        if (! $active) {
            $context->end();

            return response()->json(['accepted' => 0, 'rejected' => count($p['events'])]);
        }

        // A later storage/catalog failure rolls back the entire batch, so retrying its IDs is safe.
        return DB::transaction(function () use ($p, $context, $events) {
            $accepted = 0;
            foreach ($p['events'] as $event) {
                $accepted += (int) $events->record($event, $context);
            }

            return response()->json(['accepted' => $accepted, 'rejected' => count($p['events']) - $accepted]);
        });
    }
}
