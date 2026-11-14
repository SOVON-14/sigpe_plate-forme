<?php

namespace App\Enums;

enum AccessAction: string
{
    case View = 'view';
    case Download = 'download';
    case Validate = 'validate';
    case AccessRequest = 'access_request';
    case Decision = 'decision';
}
