<?php

namespace App\Enums;

enum ReceptionStatus: string
{
    case Partial = 'partial';
    case Complete = 'complete';
}
