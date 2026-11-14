<?php

namespace App\Enums;

enum InstitutionStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';
}
