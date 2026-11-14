<?php

return [
    /*
    | Seuils de competence pour les sorties definitives (CDC §4.11 :
    | niveaux de competence et seuils a confirmer officiellement).
    | Valeur nette comptable >= seuil => niveau superieur requis.
    */
    'competence_thresholds' => [
        'ministerial' => env('FINANCE_THRESHOLD_MINISTERIAL', 50_000_000),
        'national' => env('FINANCE_THRESHOLD_NATIONAL', 500_000_000),
    ],
];
