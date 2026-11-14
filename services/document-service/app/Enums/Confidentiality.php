<?php

namespace App\Enums;

enum Confidentiality: string
{
    case PublicAdministratif = 'public_administratif';
    case Interne = 'interne';
    case Confidentiel = 'confidentiel';
    case HautementSensible = 'hautement_sensible';

    public function requiresGrant(): bool
    {
        return $this === self::Confidentiel || $this === self::HautementSensible;
    }
}
