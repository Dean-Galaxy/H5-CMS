<?php

namespace App\Modules\Identity\Services;

use App\Contracts\RegistrationMeasurement;
use App\Contracts\RegistrationMeasurementCapture;
use App\Data\RegistrationResult;
use App\Enums\RegistrationFailure;
use App\Enums\RegistrationMeasurementAvailability;
use App\Enums\RegistrationResultKind;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/** One request only; never persist this object/capture or build an identity retry map. */
final class RegistrationObservation
{
    public const NOTICE = 'c-purpose-choice-only-r1-v1';

    private ?RegistrationMeasurement $port = null;

    private ?RegistrationMeasurementCapture $capture = null;

    private bool $finished = false;

    public static function delivered(Request $request): void
    {
        if (RegistrationTerms::enabled()) {
            $request->session()->put('identity.registration_notice_delivered', self::NOTICE);
        }
    }

    public function begin(Request $request): void
    {
        if (! RegistrationTerms::enabled() || ! $request->hasSession()
            || $request->session()->get('identity.registration_notice_delivered') !== self::NOTICE) {
            return;
        }
        try {
            $this->port = app(RegistrationMeasurement::class);
            if ($this->port->availability() === RegistrationMeasurementAvailability::Available) {
                $this->capture = $this->port->capture(self::NOTICE);
            }
        } catch (Throwable) {
            $this->port = null;
            $this->capture = null;
        }
    }

    public function succeeded(): void
    {
        $this->finish(new RegistrationResult(RegistrationResultKind::Succeeded));
    }

    public function failed(Throwable $exception): void
    {
        $failure = match (true) {
            $exception instanceof ValidationException => RegistrationFailure::Validation,
            $exception instanceof QueryException && (string) $exception->getCode() === '23505' => RegistrationFailure::Conflict,
            $exception instanceof QueryException => RegistrationFailure::Temporary,
            default => RegistrationFailure::Unknown,
        };
        $this->finish(new RegistrationResult(RegistrationResultKind::Failed, $failure));
    }

    private function finish(RegistrationResult $result): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        try {
            if ($this->port !== null && $this->capture !== null) {
                $this->port->record($this->capture, $result);
            }
        } catch (Throwable) {
            // All collector outcomes are independent of necessary account/receipt/authentication.
        } finally {
            $this->capture = null;
            $this->port = null;
        }
    }

    public function __serialize(): array
    {
        throw new \LogicException('Registration observations cannot be persisted.');
    }
}
