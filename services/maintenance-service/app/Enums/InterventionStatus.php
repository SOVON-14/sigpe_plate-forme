<?php

namespace App\Enums;

enum InterventionStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Interrupted = 'interrupted';
    case Cancelled = 'cancelled';
    case Refused = 'refused';
}
