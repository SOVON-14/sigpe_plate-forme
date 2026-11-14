<?php

namespace App\Enums;

enum IdentificationMethod: string
{
    case QrCode = 'qr_code';
    case Manual = 'manual';
}
