<?php

namespace App\Enums;

enum EntryMode: string
{
    case Achat = 'achat';
    case Construction = 'construction';
    case Don = 'don';
    case Legs = 'legs';
    case Transfert = 'transfert';
    case MiseADisposition = 'mise_a_disposition';
    case Autre = 'autre';
}
