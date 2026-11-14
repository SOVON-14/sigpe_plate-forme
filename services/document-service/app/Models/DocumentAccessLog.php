<?php

namespace App\Models;

use App\Enums\AccessAction;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAccessLog extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'document_id', 'version_id', 'actor_id', 'action',
        'ip_address', 'access_request_id',
    ];

    protected function casts(): array
    {
        return ['action' => AccessAction::class];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
