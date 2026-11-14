<?php

namespace App\Enums;

enum VersionStatus: string
{
    case Current = 'current';
    case Superseded = 'superseded';
    case Validated = 'validated';
}
