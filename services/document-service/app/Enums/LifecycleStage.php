<?php

namespace App\Enums;

enum LifecycleStage: string
{
    case Active = 'active';
    case ArchivesIntermediaires = 'archives_intermediaires';
    case ArchivesDefinitives = 'archives_definitives';
    case Eliminated = 'eliminated';

    /** @return list<string> */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::Active => [self::ArchivesIntermediaires->value],
            self::ArchivesIntermediaires => [self::ArchivesDefinitives->value],
            self::ArchivesDefinitives => [self::Eliminated->value],
            self::Eliminated => [],
        };
    }
}
