<?php

namespace App\Enums;

enum SourceType: string
{
    case Acquisition = 'acquisition';
    case Maintenance = 'maintenance';
    case Movement = 'movement';
    case Manual = 'manual';
    case Other = 'other';
}
