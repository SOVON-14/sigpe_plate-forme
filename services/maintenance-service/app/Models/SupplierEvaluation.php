<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierEvaluation extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'supplier_id', 'institution_id', 'intervention_id',
        'quality', 'timeliness', 'budget', 'parts_quality',
        'contract_compliance', 'satisfaction', 'comment', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quality' => 'integer',
            'timeliness' => 'integer',
            'budget' => 'integer',
            'parts_quality' => 'integer',
            'contract_compliance' => 'integer',
            'satisfaction' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class);
    }

    public function averageScore(): ?float
    {
        $scores = array_filter([
            $this->quality, $this->timeliness, $this->budget,
            $this->parts_quality, $this->contract_compliance, $this->satisfaction,
        ], fn ($v) => $v !== null);

        if ($scores === []) {
            return null;
        }

        return round(array_sum($scores) / count($scores), 2);
    }
}
