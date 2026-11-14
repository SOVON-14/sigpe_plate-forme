<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Resolved = 'resolved';
}
