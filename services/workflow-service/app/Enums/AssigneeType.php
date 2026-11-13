<?php

namespace App\Enums;

enum AssigneeType: string
{
    case Role = 'role';
    case Position = 'position';
    case Account = 'account';
}
