<?php
/**
 * Konfiguration — Engros Bestillingsportal
 * 
 * Loader BC-credentials fra bc_config.json (ligger uden for app-mappen).
 * Denne fil er standalone og deler IKKE kode med andre projekter.
 */

// Find bc_config.json ved at søge i mapper OVER webmappen. På den måde kan
// hemmeligheden (BC client secret) ligge et sted, der IKKE kan hentes i en browser —
// uanset om vi kører lokalt (filen i projektroden) eller på et webhotel.
$_config_path = null;
for ($_i = 1; $_i <= 5; $_i++) {
    $_candidate = dirname(__DIR__, $_i) . '/bc_config.json';
    if (is_file($_candidate)) {
        $_config_path = $_candidate;
        break;
    }
}
if ($_config_path === null) {
    die('Fejl: bc_config.json ikke fundet i en overliggende mappe.');
}
$_bc = json_decode(file_get_contents($_config_path), true);
if (!$_bc) {
    die('Fejl: bc_config.json kunne ikke læses eller indeholder ugyldig JSON.');
}

// ─── Business Central API ────────────────────────────────────────────────────
define('BC_TENANT',      $_bc['BC_TENANT_ID']      ?? '');
define('BC_CLIENT_ID',   $_bc['BC_CLIENT_ID']       ?? '');
define('BC_SECRET',      $_bc['BC_CLIENT_SECRET']   ?? '');
define('BC_COMPANY',     $_bc['BC_COMPANY_ID']      ?? '');
define('BC_ENVIRONMENT', $_bc['BC_ENVIRONMENT']     ?? 'Production');
define('BC_SCOPE',       $_bc['BC_SCOPE']           ?? 'https://api.businesscentral.dynamics.com/.default');

define('BC_TOKEN_URL', 'https://login.microsoftonline.com/' . BC_TENANT . '/oauth2/v2.0/token');
define('BC_API_BASE',  'https://api.businesscentral.dynamics.com/v2.0/' . BC_TENANT
    . '/' . BC_ENVIRONMENT . '/api/v2.0/companies(' . BC_COMPANY . ')');

// ─── Lokale stier ────────────────────────────────────────────────────────────
define('DB_PATH',     __DIR__ . '/engros.db');
define('TOKEN_CACHE', __DIR__ . '/token_cache.json');
define('DNS_CACHE',   __DIR__ . '/dns_cache.json');

// ─── Sikkerhed ───────────────────────────────────────────────────────────────
define('SESSION_TIMEOUT',        1800);   // 30 minutters inaktivitet → logout
define('MAX_LOGIN_FORSOG',       5);      // Maks forsøg pr. lockout-periode
define('LOGIN_LOCKOUT_SEKUNDER', 900);    // 15 minutters lockout ved for mange forsøg

// Ryd midlertidige variabler fra globalt scope
unset($_config_path, $_bc);
