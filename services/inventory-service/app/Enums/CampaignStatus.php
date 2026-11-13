<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case UnderReview = 'under_review';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::InProgress, self::Cancelled],
            self::InProgress => [self::UnderReview, self::Cancelled],
            self::UnderReview => [self::Closed, self::InProgress, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
