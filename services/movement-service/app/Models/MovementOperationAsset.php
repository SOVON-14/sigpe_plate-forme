<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovementOperationAsset extends Model
{
    use HasUuidV7;

    protected $fillable = ['operation_id', 'asset_id', 'patrimonial_code'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MovementOperation::class, 'operation_id');
    }
}
