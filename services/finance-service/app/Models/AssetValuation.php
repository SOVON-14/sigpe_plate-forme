<?php

namespace App\Models;

use App\Enums\ValuationType;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class AssetValuation extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'asset_id', 'institution_id', 'valuation_type', 'valuation_date',
        'amount', 'currency', 'is_determinate', 'depreciation_method',
        'depreciation_rate_annual', 'useful_life_months', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'valuation_type' => ValuationType::class,
            'valuation_date' => 'date',
            'amount' => 'decimal:2',
            'is_determinate' => 'boolean',
            'depreciation_rate_annual' => 'decimal:3',
        ];
    }
}
