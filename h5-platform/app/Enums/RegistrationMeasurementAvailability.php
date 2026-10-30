<?php

namespace App\Enums;

enum RegistrationMeasurementAvailability: string
{
    case Available = 'AVAILABLE';
    case DependencyNotReady = 'DEPENDENCY_NOT_READY';
}
