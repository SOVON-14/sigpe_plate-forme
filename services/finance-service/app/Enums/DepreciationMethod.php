<?php

namespace App\Enums;

enum DepreciationMethod: string
{
    case Linear = 'linear';
    case DecliningBalance = 'declining_balance';
    case None = 'none';
}
