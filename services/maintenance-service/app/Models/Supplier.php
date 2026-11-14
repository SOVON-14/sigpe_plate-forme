<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'institution_id', 'scope', 'name', 'specialty', 'contact_name',
        'contact_phone', 'contact_email', 'address', 'contract_ref',
        'contract_end_at', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'contract_end_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(SupplierEvaluation::class);
    }

    public function scopeVisibleTo(Builder $query, ?string $institutionId): Builder
    {
        // Repertoire national partage : nationaux (institution_id null)
        // visibles par tous ; relations ministerielles limitees a
        // l'institution proprietaire.
        if ($institutionId === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->whereNull('institution_id')
            ->orWhere('institution_id', $institutionId));
    }
}
