<?php

namespace App\Models;

use App\Enums\AccessRequestStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAccessRequest extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'document_id', 'requester_id', 'motif', 'scope',
        'requested_until', 'status', 'decided_by', 'decided_at',
        'decision_motif',
    ];

    protected function casts(): array
    {
        return [
            'status' => AccessRequestStatus::class,
            'requested_until' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isActive(): bool
    {
        return $this->status === AccessRequestStatus::Approved
            && $this->requested_until->isFuture();
    }
}
