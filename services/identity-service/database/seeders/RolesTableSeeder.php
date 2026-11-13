<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesTableSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['code' => 'admin_principal', 'name' => 'Administrateur principal', 'description' => 'Gouvernance nationale et création/activation/désactivation logique des institutions.'],
            ['code' => 'admin_central', 'name' => 'Administrateur central', 'description' => 'Administration centrale et supervision dans son périmètre autorisé.'],
            ['code' => 'admin_ministeriel', 'name' => 'Administrateur ministériel', 'description' => 'Administration fonctionnelle et technique de son ministère.'],
            ['code' => 'responsable_direction', 'name' => 'Responsable de direction', 'description' => 'Supervision de son unité organisationnelle.'],
            ['code' => 'gestionnaire_patrimoine', 'name' => 'Gestionnaire du patrimoine', 'description' => 'Gestion opérationnelle des biens et mouvements autorisés.'],
            ['code' => 'agent_inventaire', 'name' => 'Agent d’inventaire', 'description' => 'Exécution des campagnes et relevés d’inventaire.'],
            ['code' => 'responsable_financier', 'name' => 'Responsable financier', 'description' => 'Valeurs, amortissements, dépenses et données financières autorisées.'],
            ['code' => 'responsable_maintenance', 'name' => 'Responsable maintenance', 'description' => 'Demandes, plans, interventions, coûts et stocks de maintenance.'],
            ['code' => 'autorite_approbatrice', 'name' => 'Autorité approbatrice', 'description' => 'Décision sur les opérations relevant de sa délégation.'],
            ['code' => 'auditeur_controlleur', 'name' => 'Auditeur / contrôleur', 'description' => 'Consultation et contrôle en lecture seule, sauf habilitation légale distincte.'],
            ['code' => 'agent_consultant', 'name' => 'Agent consultant', 'description' => 'Consultation limitée aux informations et biens relevant de son périmètre.'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['code' => $role['code']], $role + ['is_system' => true]);
        }
    }
}
