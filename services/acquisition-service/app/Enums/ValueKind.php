<?php

namespace App\Enums;

enum ValueKind: string
{
    case Estimate = 'estimate';
    case Contracted = 'contracted';
}
