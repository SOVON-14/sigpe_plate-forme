<?php

namespace App\Enums;

enum DisposalType: string
{
    case Reform = 'reform';
    case Sale = 'sale';
    case Destruction = 'destruction';
    case Loss = 'loss';
    case Theft = 'theft';
    case Donation = 'donation';
    case Other = 'other';
}
