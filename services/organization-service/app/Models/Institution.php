<?php

namespace App\Models;

use App\Enums\InstitutionStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Institution extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'type', 'status', 'description',
        'activated_at', 'suspended_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstitutionStatus::class,
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(OrganizationalUnit::class);
    }

    public function unitTypes(): HasMany
    {
        return $this->hasMany(OrganizationalUnitType::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrganizationHistory::class);
    }
}
