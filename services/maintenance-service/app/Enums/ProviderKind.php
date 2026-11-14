<?php

namespace App\Enums;

enum ProviderKind: string
{
    case Internal = 'internal';
    case External = 'external';
}
