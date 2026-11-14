<?php

namespace App\Enums;

enum OperationType: string
{
    case Assignment = 'assignment';
    case InternalTransfer = 'internal_transfer';
    case InterministerialTransfer = 'interministerial_transfer';
    case Loan = 'loan';
}
