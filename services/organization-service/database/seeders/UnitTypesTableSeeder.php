<?php

namespace Database\Seeders;

use App\Models\OrganizationalUnitType;
use Illuminate\Database\Seeder;

class UnitTypesTableSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'ministere', 'label' => 'Ministère'],
            ['code' => 'direction', 'label' => 'Direction'],
            ['code' => 'sous_direction', 'label' => 'Sous-direction'],
            ['code' => 'service', 'label' => 'Service'],
            ['code' => 'unite_territoriale', 'label' => 'Unité territoriale'],
            ['code' => 'cellule', 'label' => 'Cellule'],
            ['code' => 'autre', 'label' => 'Autre structure'],
        ];

        foreach ($types as $type) {
            OrganizationalUnitType::firstOrCreate(
                ['institution_id' => null, 'code' => $type['code']],
                ['label' => $type['label'], 'is_system' => true],
            );
        }
    }
}
