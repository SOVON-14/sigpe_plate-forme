<?php

namespace App\Models;

use App\Enums\CostCategory;
use App\Enums\SourceType;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class AssetCost extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'asset_id', 'institution_id', 'category', 'source_type', 'source_id',
        'amount', 'currency', 'incurred_at', 'beneficiary_account_id',
        'description', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => CostCategory::class,
            'source_type' => SourceType::class,
            'amount' => 'decimal:2',
            'incurred_at' => 'date',
        ];
    }
}
