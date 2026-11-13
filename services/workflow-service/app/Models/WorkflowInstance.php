<?php

namespace App\Models;

use App\Enums\InstanceStatus;
use App\Enums\OperationDomain;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkflowInstance extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'workflow_id', 'workflow_version', 'institution_id',
        'operation_type', 'operation_ref', 'status', 'current_step_group',
        'context', 'started_by', 'decided_outcome', 'decided_by',
        'decided_at', 'decision_comment',
    ];

    protected function casts(): array
    {
        return [
            'operation_type' => OperationDomain::class,
            'status' => InstanceStatus::class,
            'context' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class, 'instance_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function scopeForInstitution(Builder $query, ?string $institutionId): Builder
    {
        return $query->when(
            $institutionId !== null,
            fn (Builder $q) => $q->where('institution_id', $institutionId),
        );
    }

    public function progress(): array
    {
        $tasks = $this->tasks;
        $groups = $tasks->pluck('step_group')->unique();
        $decidedGroups = $tasks->where('step_group', '<', $this->current_step_group ?? PHP_INT_MAX)
            ->pluck('step_group')->unique();

        return [
            'total_groups' => $groups->count(),
            'completed_groups' => $decidedGroups->count(),
            'total_tasks' => $tasks->count(),
            'decided_tasks' => $tasks->whereNotIn('status', ['pending'])->count(),
        ];
    }
}
