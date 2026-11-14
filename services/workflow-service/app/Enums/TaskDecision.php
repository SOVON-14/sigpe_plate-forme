<?php

namespace App\Enums;

enum TaskDecision: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case RequestCorrection = 'request_correction';
}
