<?php

namespace App\Enums;

enum DisposalStatus: string
{
    case Draft = 'draft';
    case PendingValidation = 'pending_validation';
    case UnderInvestigation = 'under_investigation';
    case Approved = 'approved';
    case Executed = 'executed';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
