<?php

namespace App\Enums;

enum DelegationStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
