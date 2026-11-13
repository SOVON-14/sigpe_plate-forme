<?php

namespace App\Enums;

enum UnitStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';
}
