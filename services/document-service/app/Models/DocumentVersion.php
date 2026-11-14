<?php

namespace App\Models;

use App\Enums\VersionStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'document_id', 'version_number', 'original_filename', 'mime_type',
        'size_bytes', 'sha256', 'storage_path', 'status', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'size_bytes' => 'integer',
            'status' => VersionStatus::class,
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
