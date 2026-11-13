<?php

namespace App\Enums;

enum PhysicalState: string
{
    case Neuf = 'neuf';
    case TresBon = 'tres_bon';
    case Bon = 'bon';
    case Moyen = 'moyen';
    case Mauvais = 'mauvais';
    case HorsService = 'hors_service';
    case Irrecuperable = 'irrecuperable';
}
