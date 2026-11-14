<?php

namespace App\Enums;

enum CustodyRole: string
{
    case AdministrativeResponsible = 'administrative_responsible';
    case PhysicalCustodian = 'physical_custodian';
    case AuthorizedUser = 'authorized_user';
}
