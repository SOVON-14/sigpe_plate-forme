<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    protected $table = 'document_sequences';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'year';

    protected $keyType = 'int';

    protected $fillable = ['year', 'last_number'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'last_number' => 'integer'];
    }
}
