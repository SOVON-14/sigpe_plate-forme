<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class ReportRun extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'reference', 'template_code', 'institution_id', 'period_start', 'period_end',
        'parameters', 'status', 'confidentiality', 'generated_by', 'generated_at',
        'result', 'error',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'result' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'generated_at' => 'datetime',
        ];
    }
}
