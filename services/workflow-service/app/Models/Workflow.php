<?php

namespace App\Models;

use App\Enums\OperationDomain;
use App\Enums\WorkflowStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workflow extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'institution_id', 'parent_workflow_id', 'domain', 'code', 'name',
        'description', 'version', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'domain' => OperationDomain::class,
            'status' => WorkflowStatus::class,
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('step_group')->orderBy('sequence');
    }

    public function isNational(): bool
    {
        return $this->institution_id === null;
    }

    public function scopeNational(Builder $query): Builder
    {
        return $query->whereNull('institution_id');
    }

    public function scopeVisibleTo(Builder $query, ?string $institutionId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('institution_id')
            ->when($institutionId !== null, fn (Builder $q2) => $q2->orWhere('institution_id', $institutionId)));
    }
}
