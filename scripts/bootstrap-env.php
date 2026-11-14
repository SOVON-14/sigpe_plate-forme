<?php

/**
 * SIGPÉ — Génère les fichiers .env de chaque service (services/<nom>/.env)
 * nécessaires à `docker compose up`.
 *
 * Usage : php scripts/bootstrap-env.php [--force]
 *   --force : régénère même si .env existe déjà (écrase les valeurs locales)
 *
 * Lit le .env racine (copier .env.example puis le renseigner) et produit pour
 * chaque service un .env calqué sur son .env.example avec les valeurs Docker :
 *   APP_KEY, DB_*, RABBITMQ_*, KEYCLOAK_*, stockage documentaire (MinIO).
 */

$root = dirname(__DIR__);
$force = in_array('--force', $argv ?? [], true);

/* ---------- lecture .env racine ---------- */
$envFile = $root.'/.env';
if (! is_file($envFile)) {
    fwrite(STDERR, "ERREUR : $envFile introuvable. Copiez .env.example vers .env et renseignez les secrets.\n");
    exit(1);
}
$rootEnv = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $rootEnv[trim($k)] = trim($v, " \t\"'");
}

$need = ['POSTGRES_PASSWORD', 'RABBITMQ_PASSWORD'];
foreach ($need as $k) {
    if (empty($rootEnv[$k])) {
        fwrite(STDERR, "ERREUR : $k vide dans .env racine.\n");
        exit(1);
    }
}

/* ---------- parcours des services ---------- */
$services = glob($root.'/services/*-service', GLOB_ONLYDIR);
if (! $services) {
    fwrite(STDERR, "ERREUR : aucun services/*-service trouvé.\n");
    exit(1);
}

$generated = 0;
$skipped = [];

foreach ($services as $dir) {
    $example = $dir.'/.env.example';
    $target = $dir.'/.env';
    if (! is_file($example)) {
        fwrite(STDERR, '  ! pas de .env.example : '.basename($dir)."\n");
        continue;
    }
    if (is_file($target) && ! $force) {
        $skipped[] = basename($dir);
        continue;
    }

    $short = basename($dir, '-service');           // ex. asset
    $UPPER = strtoupper($short);                    // ex. ASSET
    $db = 'sigpe_'.$short;
    $kcSecretKey = 'SIGPE_KC_SECRET_'.$UPPER;
    $kcSecret = $rootEnv[$kcSecretKey] ?? '';

    /* map key => valeur à injecter (les clés absentes du .env.example sont ajoutées) */
    $overrides = [
        'APP_NAME' => '"SIGPE '.ucfirst($short).' Service"',
        'APP_ENV' => 'local',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_DEBUG' => 'false',
        'DB_HOST' => 'postgres',
        'DB_PORT' => '5432',
        'DB_DATABASE' => $db,
        'DB_USERNAME' => $db,
        'DB_PASSWORD' => $rootEnv['POSTGRES_PASSWORD'],
        'DB_CONNECTION' => 'pgsql',
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'database',
        'SESSION_DRIVER' => 'database',
        'RABBITMQ_HOST' => 'rabbitmq',
        'RABBITMQ_PORT' => '5672',
        'RABBITMQ_USER' => 'sigpe',
        'RABBITMQ_PASSWORD' => $rootEnv['RABBITMQ_PASSWORD'],
        'RABBITMQ_VHOST' => 'sigpe',
        'RABBITMQ_EXCHANGE' => 'sigpe.events.v1',
        'KEYCLOAK_ISSUER' => 'http://localhost:8081/realms/sigpe',
        'KEYCLOAK_AUDIENCE' => 'sigpe-api',
        'KEYCLOAK_JWKS_URI' => 'http://keycloak:8080/realms/sigpe/protocol/openid-connect/certs',
        'KEYCLOAK_JWKS_CACHE_TTL' => '3600',
        'KEYCLOAK_TOKEN_URL' => 'http://keycloak:8080/realms/sigpe/protocol/openid-connect/token',
        'KEYCLOAK_CLIENT_ID' => $short.'-service',
        'KEYCLOAK_CLIENT_SECRET' => $kcSecret,
        'ASSET_SERVICE_URL' => 'http://asset-service:8080/api/v1',
        'ASSET_SERVICE_TIMEOUT' => '10',
    ];

    /* parse .env.example en lignes, remplace les valeurs connues */
    $lines = file($example, FILE_IGNORE_NEW_LINES);
    $seen = [];
    $out = [];
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '#') || ! str_contains($trim, '=')) {
            $out[] = $line;
            continue;
        }
        [$k] = explode('=', $trim, 2);
        $k = trim($k);
        // clé obsolète : KEYCLOAK_JWKS_URL -> JWKS_URI (déjà couvert par l'override)
        if ($k === 'KEYCLOAK_JWKS_URL') {
            continue;
        }
        if (array_key_exists($k, $overrides)) {
            $seen[$k] = true;
            $out[] = $k.'='.$overrides[$k];
        } else {
            $out[] = $line;
        }
    }
    // ajoute les clés manquantes à la fin
    foreach ($overrides as $k => $v) {
        if (! isset($seen[$k]) && ! in_array($k, array_map(fn ($l) => explode('=', trim($l), 2)[0] ?? '', $lines), true)) {
            // ne pas dupliquer une clé déjà présente sans override
            $present = false;
            foreach ($lines as $l) {
                $t = trim($l);
                if ($t !== '' && ! str_starts_with($t, '#') && str_starts_with($t, $k.'=')) { $present = true; break; }
            }
            if (! $present) {
                $out[] = '';
                $out[] = '# --- injecte par bootstrap-env.php ---';
                $out[] = $k.'='.$v;
            }
        }
    }

    // variables AWS/MinIO pour document-service
    if ($short === 'document') {
        $aws = [
            'DOCUMENTS_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => $rootEnv['MINIO_ROOT_USER'] ?? 'sigpe',
            'AWS_SECRET_ACCESS_KEY' => $rootEnv['MINIO_ROOT_PASSWORD'] ?? '',
            'AWS_DEFAULT_REGION' => 'us-east-1',
            'AWS_BUCKET' => 'sigpe-documents',
            'AWS_ENDPOINT' => 'http://minio:9000',
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ];
        foreach ($aws as $k => $v) {
            $present = false;
            foreach ($out as $l) {
                $t = trim($l);
                if ($t !== '' && ! str_starts_with($t, '#') && str_starts_with($t, $k.'=')) { $present = true; break; }
            }
            if (! $present) {
                $out[] = $k.'='.$v;
            } else {
                // remplace la valeur vide
                foreach ($out as $i => $l) {
                    $t = trim($l);
                    if ($t !== '' && ! str_starts_with($t, '#') && str_starts_with($t, $k.'=')) {
                        $out[$i] = $k.'='.$v;
                    }
                }
            }
        }
    }

    file_put_contents($target, implode(PHP_EOL, $out).PHP_EOL);
    $generated++;
    echo '  ✓ '.$short."-service/.env généré\n";
}

echo "\nTerminé : $generated généré(s)".($skipped ? ', '.count($skipped).' déjà existant(s) ignoré(s) : '.implode(', ', $skipped) : '')."\n";
echo "Prochaine étape : docker compose up -d --build\n";
