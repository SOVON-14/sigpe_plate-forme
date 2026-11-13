<?php

namespace App\Enums;

enum OperationDomain: string
{
    case Acquisition = 'acquisition';
    case Movement = 'movement';
    case Transfer = 'transfer';
    case Loan = 'loan';
    case Reforme = 'reforme';
    case Maintenance = 'maintenance';
    case Inventory = 'inventory';
    case Document = 'document';
    case Finance = 'finance';
    case Other = 'other';
}
