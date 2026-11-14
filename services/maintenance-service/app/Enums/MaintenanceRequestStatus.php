<?php

namespace App\Enums;

enum MaintenanceRequestStatus: string
{
    case Draft = 'draft';
    case PendingValidation = 'pending_validation';
    case Validated = 'validated';
    case Rejected = 'rejected';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
