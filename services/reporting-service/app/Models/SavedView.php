<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class SavedView extends Model
{
    use HasUuidV7;

    protected $fillable = ['account_id', 'name', 'profile', 'filters'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
