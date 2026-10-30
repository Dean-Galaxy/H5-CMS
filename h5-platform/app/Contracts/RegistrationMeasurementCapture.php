<?php

namespace App\Contracts;

/** Opaque provider capability, bound to the identical server Request; never sent to a browser. */
interface RegistrationMeasurementCapture
{
    /** Implementations must throw: never persist an identity-to-measurement retry map. */
    public function __serialize(): array;
}
