<?php

namespace App\Enums;

enum ValuationType: string
{
    case Acquisition = 'acquisition';
    case Current = 'current';
    case NetBook = 'net_book';
    case Replacement = 'replacement';
}
