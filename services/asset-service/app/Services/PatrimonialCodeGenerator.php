<?php

namespace App\Services;

use App\Models\PatrimonialCodeSequence;
use Illuminate\Support\Facades\DB;

class PatrimonialCodeGenerator
{
    /**
     * Génère un code patrimonial unique : PAT-AAAA-NNNNNN.
     * Séquence globale atomique ; l'unicité par institution est
     * garantie par la contrainte UNIQUE(institution_id, code_patrimonial).
     */
    public function next(): string
    {
        $value = DB::transaction(function () {
            $row = PatrimonialCodeSequence::lockForUpdate()->first()
                ?? PatrimonialCodeSequence::create(['last_value' => 0]);

            $row->increment('last_value');

            return $row->fresh()->last_value;
        });

        return sprintf('PAT-%s-%06d', now()->format('Y'), $value);
    }
}
