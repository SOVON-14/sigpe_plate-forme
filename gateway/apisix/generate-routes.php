<?php

/**
 * Génère gateway/apisix/apisix.yaml à partir des contrats OpenAPI.
 * Usage : php gateway/apisix/generate-routes.php
 *
 * Règle de conflit : GET /api/v1/assets/{assetId} existe côté asset-service
 * (donnée maître) et reporting-service (projection). La projection reporting
 * est exposée via /api/v1/reporting/assets/{assetId} avec réécriture proxy.
 */

$root = dirname(__DIR__, 2);
$openapiDir = $root.'/contracts/openapi';
$outFile = $root.'/gateway/apisix/apisix.yaml';

$methods = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];
$routes = [];

foreach (glob($openapiDir.'/*-service.openapi.yaml') as $file) {
    $service = preg_replace('/(-service)?\.openapi\.yaml$/', '', basename($file));
    if ($service === '_template') {
        continue;
    }

    $currentPath = null;
    $currentMethods = [];

    $flush = function () use (&$routes, &$currentPath, &$currentMethods, $service) {
        if ($currentPath === null) {
            return;
        }
        if ($currentPath === '/health') {
            $currentPath = null;
            $currentMethods = [];

            return;
        }
        $uri = '/api/v1'.$currentPath;
        $rewrite = null;
        if ($service === 'reporting' && $currentPath === '/assets/{assetId}') {
            $uri = '/api/v1/reporting/assets/:assetId';
            $rewrite = '^/api/v1/reporting/assets/(.*)$';
        }
        $uri = preg_replace('/\{(\w+)\}/', ':$1', $uri);
        $routes[] = [
            'id' => $service.'-'.preg_replace('/[^a-z0-9]+/', '-', strtolower($currentPath)).'-'.md5($uri.implode(',', $currentMethods)),
            'uri' => $uri,
            'methods' => array_map('strtoupper', $currentMethods),
            'upstream' => $service.'-service:8080',
            'rewrite' => $rewrite,
        ];
        $currentPath = null;
        $currentMethods = [];
    };

    foreach (file($file) as $line) {
        if (preg_match('/^  (\/[^\s:]+):\s*$/', $line, $m)) {
            $flush();
            $currentPath = $m[1];
        } elseif ($currentPath !== null && preg_match('/^    (get|post|put|patch|delete|head|options|trace):\s*$/', $line, $m)) {
            $currentMethods[] = $m[1];
        }
    }
    $flush();
}

$yaml = "# Fichier généré par gateway/apisix/generate-routes.php — ne pas éditer à la main.\n";
$yaml .= "# Source : contracts/openapi/*.openapi.yaml\n\n";
$yaml .= "routes:\n";
foreach ($routes as $r) {
    $yaml .= "  - id: {$r['id']}\n";
    $yaml .= "    uri: {$r['uri']}\n";
    $yaml .= '    methods: ['.implode(', ', $r['methods'])."]\n";
    if ($r['rewrite'] !== null) {
        $yaml .= "    plugins:\n";
        $yaml .= "      - name: proxy-rewrite\n";
        $yaml .= "        config:\n";
        $yaml .= "          regex_uri: [\"{$r['rewrite']}\", \"/api/v1/assets/$1\"]\n";
    }
    $yaml .= "    upstream:\n";
    $yaml .= "      type: roundrobin\n";
    $yaml .= "      nodes:\n";
    $yaml .= "        \"{$r['upstream']}\": 1\n";
}
$yaml .= <<<'YAML'

# Règle globale : CORS pour le frontend (origines locales de dev).
global_rules:
  - id: cors-global
    plugins:
      cors:
        allow_origins: "http://localhost:5173,http://localhost:3000,http://localhost:8088"
        allow_methods: "GET,POST,PUT,PATCH,DELETE,OPTIONS"
        allow_headers: "Authorization,Content-Type,Idempotency-Key,X-Correlation-Id"
        expose_headers: "X-Correlation-Id,Idempotent-Replay"
        max_age: 3600
YAML;

file_put_contents($outFile, $yaml);
echo 'Routes générées : '.count($routes)." -> {$outFile}\n";
