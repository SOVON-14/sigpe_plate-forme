<?php

namespace App\Enums;

enum CostCategory: string
{
    case AcquisitionCost = 'acquisition_cost';
    case MaintenanceCost = 'maintenance_cost';
    case Repair = 'repair';
    case Transport = 'transport';
    case Insurance = 'insurance';
    case SharedCostShare = 'shared_cost_share';
    case Other = 'other';
}
