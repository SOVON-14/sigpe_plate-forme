<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetCategory extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = ['code', 'label', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(AssetSubcategory::class, 'category_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'category_id');
    }
}
