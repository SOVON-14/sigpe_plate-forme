<?php

namespace Database\Seeders;

use App\Models\AssetCategory;
use Illuminate\Database\Seeder;

class AssetCategoriesTableSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'biens_immobiliers', 'label' => 'Biens immobiliers'],
            ['code' => 'materiels_roulants', 'label' => 'Matériels roulants'],
            ['code' => 'materiels_informatiques', 'label' => 'Matériels informatiques et numériques'],
            ['code' => 'mobilier_bureau', 'label' => 'Mobilier et équipements de bureau'],
            ['code' => 'materiels_techniques', 'label' => 'Matériels techniques et industriels'],
            ['code' => 'materiels_medicaux', 'label' => 'Matériels médicaux et scientifiques'],
            ['code' => 'autres_biens', 'label' => 'Autres biens patrimoniaux'],
        ];

        foreach ($categories as $category) {
            AssetCategory::firstOrCreate(
                ['code' => $category['code']],
                ['label' => $category['label'], 'is_system' => true],
            );
        }
    }
}
