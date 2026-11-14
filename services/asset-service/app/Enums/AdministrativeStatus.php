<?php

namespace App\Enums;

enum AdministrativeStatus: string
{
    case Actif = 'actif';
    case Provisoire = 'provisoire';
    case EnReforme = 'en_reforme';
    case Sorti = 'sorti';
}
