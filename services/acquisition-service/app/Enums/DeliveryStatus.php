<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Expected = 'expected';
    case Arrived = 'arrived';
    case PartiallyReceived = 'partially_received';
    case FullyReceived = 'fully_received';
    case Cancelled = 'cancelled';
}
