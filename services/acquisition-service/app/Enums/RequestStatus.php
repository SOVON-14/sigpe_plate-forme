<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Draft = 'draft';
    case PendingValidation = 'pending_validation';
    case Validated = 'validated';
    case Selected = 'selected';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Closed, self::Rejected, self::Cancelled], true);
    }

    public function canBeSubmitted(): bool
    {
        return $this === self::Draft;
    }

    public function canBeSelected(): bool
    {
        return $this === self::Validated;
    }

    public function canBeOrdered(): bool
    {
        return $this === self::Selected;
    }

    public function canReceive(): bool
    {
        return in_array($this, [self::Ordered, self::PartiallyReceived], true);
    }

    public function canBeCancelled(): bool
    {
        return $this->isOpen() && $this !== self::Received;
    }
}
