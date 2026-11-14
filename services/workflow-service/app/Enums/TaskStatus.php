<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case CorrectionRequested = 'correction_requested';
    case Cancelled = 'cancelled';
}
