<?php

namespace App\Enums;

enum SharedRightStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
}
