<?php

namespace App\Models;

use App\Enums\Confidentiality;
use App\Enums\LifecycleStage;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Document extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'reference', 'title', 'description', 'category_id', 'institution_id',
        'unit_id', 'asset_id', 'confidentiality', 'lifecycle_stage',
        'current_version', 'retention_until', 'validated_at', 'validated_by',
        'document_date', 'issuer', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'lifecycle_stage' => LifecycleStage::class,
            'current_version' => 'integer',
            'retention_until' => 'date',
            'validated_at' => 'datetime',
            'document_date' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('version_number');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class, 'document_id', 'id')
            ->where('version_number', $this->current_version);
    }

    public function accessRequests(): HasMany
    {
        return $this->hasMany(DocumentAccessRequest::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(DocumentAccessLog::class);
    }
}
