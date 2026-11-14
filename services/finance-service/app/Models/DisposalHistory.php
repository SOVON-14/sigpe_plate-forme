<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class DisposalHistory extends Model
{
    use HasUuidV7;

    protected $fillable = ['disposal_id', 'from_status', 'to_status', 'actor_id', 'comment'];
}
