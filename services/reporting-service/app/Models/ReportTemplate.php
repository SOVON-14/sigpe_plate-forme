<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class ReportTemplate extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'code', 'name', 'description', 'category', 'confidentiality', 'parameters', 'is_active',
    ];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'is_active' => 'boolean'];
    }
}
