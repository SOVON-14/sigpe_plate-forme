<?php

namespace App\Enums;

enum IncidentType: string
{
    case Loss = 'loss';
    case Theft = 'theft';
    case Damage = 'damage';
    case Other = 'other';
}
