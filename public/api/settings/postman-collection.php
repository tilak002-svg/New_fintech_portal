<?php
/**
 * Serves the Postman collection with `base_url` pre-filled to this
 * platform's actual configured APP_URL, instead of the static
 * https://your-domain.example.com placeholder — so a downloaded collection
 * works against this environment (local Docker, Laragon, real hosting,
 * whatever APP_URL is set to) with zero manual editing. client_key/
 * client_secret are deliberately left blank in the template and untouched
 * here — never pre-fill a secret into a downloadable file.
 */
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

api_guard(['customer']);

$path = __DIR__ . '/../../assets/postman/verapay-api.postman_collection.json';
$collection = json_decode(file_get_contents($path), true);

if (is_array($collection['variable'] ?? null)) {
    foreach ($collection['variable'] as &$variable) {
        if (($variable['key'] ?? '') === 'base_url') {
            $variable['value'] = rtrim(APP_URL, '/');
        }
    }
    unset($variable);
}

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="verapay-api.postman_collection.json"');
echo json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
