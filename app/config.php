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

// Firmanavnet bruges af BC's OData V4 web services (fx Claus_salgsfaktura), der
// adresserer firmaet på NAVN — ikke på GUID som standard-API'et. Default 'My Company'.
define('BC_COMPANY_NAME', $_bc['BC_COMPANY_NAME']   ?? 'My Company');

define('BC_TOKEN_URL', 'https://login.microsoftonline.com/' . BC_TENANT . '/oauth2/v2.0/token');
define('BC_API_BASE',  'https://api.businesscentral.dynamics.com/v2.0/' . BC_TENANT
    . '/' . BC_ENVIRONMENT . '/api/v2.0/companies(' . BC_COMPANY . ')');

// Base-URL til BC's OData V4 web services (publicerede sider), fx Claus_salgsfaktura.
define('BC_ODATA_BASE', 'https://api.businesscentral.dynamics.com/v2.0/' . BC_TENANT
    . '/' . BC_ENVIRONMENT . '/ODataV4/Company(' . "'" . str_replace(' ', '%20', BC_COMPANY_NAME) . "'" . ')');

// ─── GLS ─────────────────────────────────────────────────────────────────────
// Offentlig pakkesporings-URL hos GLS (tracking-nummeret sættes ind hvor {NR} står).
// Bruges som direkte link uanset om API-integrationen er slået til.
define('GLS_TRACK_URL', $_bc['GLS_TRACK_URL'] ?? 'https://gls-group.com/DK/da/find-pakke/?match={NR}');

// GLS Track & Trace REST API. Hentes fra bc_config.json. Når GLS_API_URL er tom,
// er API-integrationen slået fra, og portalen viser blot sporingslinket.
define('GLS_API_URL',        $_bc['GLS_API_URL']        ?? '');
define('GLS_API_AUTH',       $_bc['GLS_API_AUTH']       ?? 'basic'); // 'basic' | 'bearer' | 'apikey'
define('GLS_API_USER',       $_bc['GLS_API_USER']       ?? '');
define('GLS_API_PASSWORD',   $_bc['GLS_API_PASSWORD']   ?? '');
define('GLS_API_KEY',        $_bc['GLS_API_KEY']        ?? '');
define('GLS_API_KEY_HEADER', $_bc['GLS_API_KEY_HEADER'] ?? 'X-Api-Key');
// Integrationen er først aktiv når både URL og de relevante credentials er udfyldt.
// (URL'en kan være prefilled, men uden password/nøgle skal vi ikke kalde GLS.)
define('GLS_API_ENABLED', GLS_API_URL !== '' && (
    (GLS_API_AUTH === 'basic' && GLS_API_PASSWORD !== '')
    || (in_array(GLS_API_AUTH, ['bearer', 'apikey'], true) && GLS_API_KEY !== '')
));

// ─── Kundespecifik regel: emballagetillæg + fragt (kun D00138) ───────────────
// For D00138 lægges automatisk et emballagetillæg (vare DIV) på ordren — ét stk
// pr. bestilt kaffeenhed à 2 kr — samt en fragtlinje (FRAGT15-20) uden antal,
// som udfyldes manuelt i BC. Kaffe identificeres på vareposteringsgruppen "KAFFE".
define('EMBALLAGE_KUNDE',        'D00138');
define('EMBALLAGE_KAFFE_GRUPPE', 'KAFFE');          // generalProductPostingGroupCode for "ristet kaffe"
define('EMBALLAGE_VARE',         'DIV');            // Varenr. for emballagetillægget
define('EMBALLAGE_TEKST',        'emballagetillæg');
define('EMBALLAGE_PRIS',         2);                // kr pr. kaffeenhed (ekskl. moms)
define('EMBALLAGE_FRAGT_VARE',   'FRAGT15-20');     // Fragtvare (antal sættes i BC)

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
