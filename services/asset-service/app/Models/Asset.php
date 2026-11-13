<?php

namespace App\Models;

use App\Enums\AdministrativeStatus;
use App\Enums\EntryMode;
use App\Enums\PhysicalState;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'institution_id', 'organizational_unit_id', 'category_id', 'subcategory_id',
        'patrimonial_code', 'designation', 'description',
        'entry_date', 'entry_mode', 'entry_reference',
        'physical_state', 'administrative_status',
        'location', 'qr_token', 'technical_attributes',
        'value_unknown', 'value_unknown_justification', 'registered_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'entry_mode' => EntryMode::class,
            'physical_state' => PhysicalState::class,
            'administrative_status' => AdministrativeStatus::class,
            'technical_attributes' => 'array',
            'value_unknown' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(AssetSubcategory::class, 'subcategory_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AssetHistory::class);
    }
}
