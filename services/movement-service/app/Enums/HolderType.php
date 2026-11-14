<?php

namespace App\Enums;

enum HolderType: string
{
    case Institution = 'institution';
    case OrganizationalUnit = 'organizational_unit';
    case Account = 'account';
}
