<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'organizational_unit_id', 'code', 'title',
        'functional_account_id', 'status', 'assigned_at',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id');
    }

    public function isFilled(): bool
    {
        return $this->functional_account_id !== null && $this->status === 'active';
    }
}
