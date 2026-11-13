<?php

namespace App\Enums;

enum AnomalyType: string
{
    case Endommage = 'endommage';
    case MalLocalise = 'mal_localise';
    case NonEnregistre = 'non_enregistre';
    case MalIdentifie = 'mal_identifie';
    case UtilisationNonDeclaree = 'utilisation_non_declaree';
    case Autre = 'autre';
}
