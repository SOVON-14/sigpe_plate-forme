<?php

namespace App\Models;

use App\Enums\CompetenceLevel;
use App\Enums\DisposalStatus;
use App\Enums\DisposalType;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Disposal extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'asset_id', 'institution_id', 'disposal_type', 'reason', 'status',
        'estimated_value', 'currency', 'net_book_value', 'is_value_determinate',
        'required_competence_level', 'workflow_instance_id', 'decided_by',
        'decided_at', 'executed_by', 'executed_at', 'closed_by', 'closed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'disposal_type' => DisposalType::class,
            'status' => DisposalStatus::class,
            'required_competence_level' => CompetenceLevel::class,
            'estimated_value' => 'decimal:2',
            'net_book_value' => 'decimal:2',
            'is_value_determinate' => 'boolean',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function histories(): HasMany
    {
        return $this->hasMany(DisposalHistory::class, 'disposal_id');
    }
}
