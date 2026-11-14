<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Entry = 'entry';
    case Exit = 'exit';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
}
