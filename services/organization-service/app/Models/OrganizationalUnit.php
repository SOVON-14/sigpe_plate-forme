<?php

namespace App\Models;

use App\Enums\UnitStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizationalUnit extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'institution_id', 'parent_id', 'unit_type_id',
        'code', 'name', 'status', 'head_position_id',
    ];

    protected function casts(): array
    {
        return ['status' => UnitStatus::class];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnitType::class, 'unit_type_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function headPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'head_position_id');
    }
}
