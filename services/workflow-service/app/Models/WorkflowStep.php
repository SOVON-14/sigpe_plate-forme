<?php

namespace App\Models;

use App\Enums\AssigneeType;
use App\Enums\StepMode;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStep extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'workflow_id', 'step_group', 'sequence', 'name',
        'assignee_type', 'assignee_ref', 'mode', 'sla_hours',
        'is_national_obligatory',
    ];

    protected function casts(): array
    {
        return [
            'assignee_type' => AssigneeType::class,
            'mode' => StepMode::class,
            'is_national_obligatory' => 'boolean',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
