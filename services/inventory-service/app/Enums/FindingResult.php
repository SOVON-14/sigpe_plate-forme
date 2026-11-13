<?php

namespace App\Enums;

enum FindingResult: string
{
    case Found = 'trouve';
    case NotFound = 'non_trouve';
}
