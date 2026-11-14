<?php

namespace App\Enums;

enum OperationStatus: string
{
    case Draft = 'draft';
    case PendingValidation = 'pending_validation';
    case PendingDeparture = 'pending_departure';
    case PendingReception = 'pending_reception';
    case PendingAssetConfirmation = 'pending_asset_confirmation';
    case Active = 'active';
    case ReturnPending = 'return_pending';
    case Completed = 'completed';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case IncidentReported = 'incident_reported';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Returned, self::Cancelled, self::Rejected], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }
}
