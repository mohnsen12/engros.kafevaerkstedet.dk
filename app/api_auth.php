<?php
/**
 * Delt autentifikation for portalens API-endpoints (api_ordre.php, api_varer.php).
 *
 * Sender selv et JSON-fejlsvar og afslutter ved manglende/ugyldig nøgle eller
 * rate-limit — ellers returneres bruger-rækken (fra brugere-tabellen).
 */

require_once __DIR__ . '/auth.php';   // $db + lockout-hjælpere (require_login kaldes IKKE)

/** Udskriv et JSON-svar og afslut. */
function api_svar(int $kode, array $data): void {
    http_response_code($kode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Autentificér et API-kald via X-Api-Key-headeren (eller Authorization: Bearer).
 * Fejlede forsøg registreres i portalens login-lockout (5 fejl → ~15 min. pr. IP).
 *
 * @return array Bruger-rækken for forhandleren bag nøglen
 */
function api_autentificer(): array {
    if (is_ip_locked_out('API')) {
        api_svar(429, ['success' => false, 'fejl' => 'For mange fejlslagne forsøg — prøv igen senere.']);
    }

    $rå_nøgle = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($rå_nøgle === '' && preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
        $rå_nøgle = $m[1];
    }
    if ($rå_nøgle === '') {
        log_login_forsog('API', false);
        api_svar(401, ['success' => false, 'fejl' => 'Mangler API-nøgle — send den i headeren X-Api-Key.']);
    }

    // Nøglen lagres hashet (som et kodeord) → gennemløb aktive forhandlere.
    $stmt = $GLOBALS['db']->query("SELECT * FROM brugere WHERE aktiv = 1 AND brugernavn != 'admin'");
    foreach ($stmt->fetchAll() as $bruger) {
        if (($bruger['api_key_hash'] ?? '') !== '' && password_verify($rå_nøgle, $bruger['api_key_hash'])) {
            return $bruger;
        }
    }

    log_login_forsog('API', false);
    error_log("API: afvist nøgle-forsøg fra IP " . get_client_ip());
    api_svar(401, ['success' => false, 'fejl' => 'Ugyldig API-nøgle.']);
}
