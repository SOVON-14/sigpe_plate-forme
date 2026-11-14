<?php

namespace App\Enums;

enum CampaignType: string
{
    case GeneralAnnual = 'general_annual';
    case Periodic = 'periodic';
    case Exceptional = 'exceptional';
}
