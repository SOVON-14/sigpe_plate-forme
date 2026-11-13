<?php

namespace App\Models;

use App\Enums\AssigneeType;
use App\Enums\StepMode;
use App\Enums\TaskStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTask extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'instance_id', 'step_id', 'step_group', 'step_name',
        'assignee_type', 'assignee_ref', 'mode', 'sla_hours', 'sla_due_at',
        'status', 'is_delegated', 'decided_by', 'decided_at', 'decision_comment',
    ];

    protected function casts(): array
    {
        return [
            'assignee_type' => AssigneeType::class,
            'mode' => StepMode::class,
            'status' => TaskStatus::class,
            'is_delegated' => 'boolean',
            'sla_due_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function isPending(): bool
    {
        return $this->status === TaskStatus::Pending;
    }

    /**
     * L'appelant peut-il decider cette tache ?
     * assignee_type=account : correspondance directe sur le sub Keycloak.
     * role/position : permission workflow.tasks.decide dans le perimetre
     * (le mapping role→comptes est resolu par Identity, pas duplique ici).
     */
    public function canBeDecidedBy(string $sub, array $permissions): bool
    {
        if ($this->assignee_type === AssigneeType::Account) {
            return $this->assignee_ref === $sub;
        }

        return in_array('workflow.tasks.decide', $permissions, true);
    }
}
