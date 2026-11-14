<?php

namespace App\Enums;

enum NotificationPriority: string
{
    case Ordinary = 'ordinary';
    case Important = 'important';
    case Critical = 'critical';
}
