<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Executed = 'executed';
    case Cancelled = 'cancelled';
}
