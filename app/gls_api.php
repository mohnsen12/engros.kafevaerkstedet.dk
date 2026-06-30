<?php
/**
 * GLS Track & Trace API — Engros Bestillingsportal
 *
 * Henter live leveringsstatus for en pakke ud fra dens tracking-/pakkenummer.
 *
 * Integrationen er HELT konfigurerbar via bc_config.json, fordi GLS' API-URL og
 * auth-metode afhænger af den konkrete aftale:
 *   GLS_API_URL        Endpoint. Må indeholde {NR} hvor pakkenummeret skal stå.
 *                      Står der ikke {NR}, tilføjes "/{NR}" til sidst.
 *   GLS_API_AUTH       'basic' | 'bearer' | 'apikey'
 *   GLS_API_USER/PASS  Til 'basic'
 *   GLS_API_KEY        Til 'bearer' (Authorization: Bearer ...) og 'apikey'
 *   GLS_API_KEY_HEADER Headernavn til 'apikey' (fx 'X-Api-Key')
 *
 * Er GLS_API_URL tom, er integrationen slået fra, og funktionerne returnerer null.
 * Når nøglen indsættes, virker det uden yderligere kodeændringer. Hvis GLS' svar-
 * format afviger en smule fra det forventede, parser vi defensivt og gemmer altid
 * råsvaret, så det er nemt at finjustere mapningen.
 *
 * Genbruger den robuste host-/DNS-resolver fra bc_api.php (samme ustabile lokale DNS).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bc_api.php'; // bc_resolve_host / bc_dns_resolve_curl

define('GLS_CACHE_FILE', __DIR__ . '/gls_status_cache.json');

/**
 * Oversætter en rå GLS-statuskode til en dansk, kundevenlig tekst.
 * Ukendte koder returneres pænt (Stort forbogstav) i stedet for at fejle.
 */
function gls_status_til_dansk($kode) {
    $n = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $kode));
    // Værdier fra GLS Track&Trace API's parcels[n].status (fx 'INDELIVERY', 'DELIVERED').
    $map = [
        'PREADVICE'      => 'Forsendelse anmeldt',
        'PREADVICED'     => 'Forsendelse anmeldt',
        'INWAREHOUSE'    => 'Modtaget på GLS-terminal',
        'INTRANSIT'      => 'Undervejs',
        'TRANSIT'        => 'Undervejs',
        'INDELIVERY'     => 'Ude til levering',
        'OUTFORDELIVERY' => 'Ude til levering',
        'DELIVERY'       => 'Ude til levering',
        'DELIVERED'      => 'Leveret',
        'DELIVEREDPS'    => 'Leveret til pakkeshop',
        'PARCELSHOP'     => 'Klar til afhentning i pakkeshop',
        'NOTDELIVERED'   => 'Kunne ikke leveres',
        'RETURNED'       => 'Returneret',
        'CANCELED'       => 'Annulleret',
        'CANCELLED'      => 'Annulleret',
        'FINAL'          => 'Afsluttet',
    ];
    if (isset($map[$n])) return $map[$n];
    $tekst = trim((string) $kode);
    return $tekst !== '' ? ucfirst(strtolower($tekst)) : 'Ukendt';
}

/**
 * Scanner et vilkårligt dybt GLS-svar for en statuskode og seneste hændelse.
 * GLS' formater varierer, så vi leder efter de mest almindelige feltnavne.
 *
 * @return array ['status_code'=>string, 'last_event'=>string, 'last_event_time'=>string]
 */
function gls_parse_svar($data) {
    $resultat = ['status_code' => '', 'last_event' => '', 'last_event_time' => ''];
    if (!is_array($data)) return $resultat;

    $status_noegler = ['status', 'statusInfo', 'statusText', 'state', 'deliveryStatus', 'parcelStatus', 'progressBarStatus'];
    $event_arrays   = ['events', 'history', 'progress', 'parcelEvents', 'tuStatus', 'statusHistory'];
    $event_tekst    = ['description', 'text', 'statusText', 'eventDescription', 'value', 'status'];
    $event_tid      = ['timestamp', 'date', 'dateTime', 'time', 'eventTime', 'datetime'];

    $scan = function ($node) use (&$scan, &$resultat, $status_noegler, $event_arrays, $event_tekst, $event_tid) {
        if (!is_array($node)) return;

        // Statuskode (kun den første fundne, øverste niveau prioriteres af rækkefølgen)
        foreach ($status_noegler as $sk) {
            if ($resultat['status_code'] === '' && isset($node[$sk]) && is_scalar($node[$sk]) && (string) $node[$sk] !== '') {
                $resultat['status_code'] = (string) $node[$sk];
                break;
            }
        }

        // Hændelsesliste → find den NYESTE hændelse (højeste timestamp).
        // GLS lister nyeste først, men vi sorterer robust på timestamp uanset rækkefølge.
        foreach ($event_arrays as $ek) {
            if (isset($node[$ek]) && is_array($node[$ek]) && !empty($node[$ek])) {
                $nyeste = null; $nyeste_tid = '';
                foreach ($node[$ek] as $ev) {
                    if (!is_array($ev)) continue;
                    $tid = '';
                    foreach ($event_tid as $tt) {
                        if (isset($ev[$tt]) && is_scalar($ev[$tt])) { $tid = (string) $ev[$tt]; break; }
                    }
                    // Vælg hvis det er den første, eller har et nyere (større) tidsstempel
                    if ($nyeste === null || ($tid !== '' && $tid > $nyeste_tid)) {
                        $nyeste = $ev; $nyeste_tid = $tid;
                    }
                }
                if (is_array($nyeste)) {
                    foreach ($event_tekst as $tk) {
                        if (isset($nyeste[$tk]) && is_scalar($nyeste[$tk])) { $resultat['last_event'] = (string) $nyeste[$tk]; break; }
                    }
                    if ($nyeste_tid !== '') $resultat['last_event_time'] = $nyeste_tid;
                }
            }
        }

        // Gå rekursivt videre
        foreach ($node as $v) {
            if (is_array($v)) $scan($v);
        }
    };
    $scan($data);

    // Hvis ingen overordnet status, men en seneste hændelse findes, så brug den som status
    if ($resultat['status_code'] === '' && $resultat['last_event'] !== '') {
        $resultat['status_code'] = $resultat['last_event'];
    }
    return $resultat;
}

/**
 * Læser/skriver den lille fil-cache (pr. pakkenummer), så vi ikke kalder GLS ved
 * hver sidevisning. Leverede pakker caches længe; øvrige kort.
 */
function gls_cache_hent($tracking_no) {
    if (!is_file(GLS_CACHE_FILE)) return null;
    $alle = json_decode(@file_get_contents(GLS_CACHE_FILE), true);
    if (!is_array($alle) || !isset($alle[$tracking_no])) return null;
    $post = $alle[$tracking_no];
    $alder = time() - ($post['ts'] ?? 0);
    $leveret = strtoupper(preg_replace('/[^A-Za-z]/', '', $post['data']['status_code'] ?? '')) === 'DELIVERED';
    $ttl = $leveret ? 604800 : 1800; // 7 dage hvis leveret, ellers 30 min
    if ($alder > $ttl) return null;
    return $post['data'] ?? null;
}

function gls_cache_gem($tracking_no, $data) {
    $alle = [];
    if (is_file(GLS_CACHE_FILE)) {
        $alle = json_decode(@file_get_contents(GLS_CACHE_FILE), true);
        if (!is_array($alle)) $alle = [];
    }
    $alle[$tracking_no] = ['ts' => time(), 'data' => $data];
    @file_put_contents(GLS_CACHE_FILE, json_encode($alle));
}

/**
 * Henter leveringsstatus for ét pakkenummer fra GLS Track & Trace API.
 *
 * @param string $tracking_no Pakkenummeret (fx '043198100350')
 * @return array|null [
 *     'ok'              => bool,    // true hvis GLS svarede
 *     'status_code'     => string,  // rå GLS-status
 *     'status_label'    => string,  // dansk tekst
 *     'last_event'      => string,  // seneste hændelsestekst
 *     'last_event_time' => string,  // tidspunkt for seneste hændelse
 *   ]
 *   eller null hvis integrationen er slået fra (GLS_API_URL tom).
 */
function gls_get_parcel_status($tracking_no) {
    $tracking_no = trim((string) $tracking_no);
    if (!GLS_API_ENABLED || $tracking_no === '') {
        return null;
    }

    // 1) Cache
    $cached = gls_cache_hent($tracking_no);
    if ($cached !== null) {
        return $cached;
    }

    // 2) Byg URL
    $url = (strpos(GLS_API_URL, '{NR}') !== false)
        ? str_replace('{NR}', rawurlencode($tracking_no), GLS_API_URL)
        : rtrim(GLS_API_URL, '/') . '/' . rawurlencode($tracking_no);

    // 3) Auth-headers
    $headers = ['Accept: application/json'];
    $ch = curl_init();
    switch (GLS_API_AUTH) {
        case 'bearer':
            $headers[] = 'Authorization: Bearer ' . GLS_API_KEY;
            break;
        case 'apikey':
            $headers[] = GLS_API_KEY_HEADER . ': ' . GLS_API_KEY;
            break;
        case 'basic':
        default:
            curl_setopt($ch, CURLOPT_USERPWD, GLS_API_USER . ':' . GLS_API_PASSWORD);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            break;
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    bc_dns_resolve_curl($ch, $url); // robust host-opslag (samme som BC)
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $svar = curl_exec($ch);
    $kode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fejl = curl_error($ch);
    curl_close($ch);

    if ($fejl || $kode < 200 || $kode >= 300) {
        error_log("GLS API fejl for pakke $tracking_no: HTTP $kode $fejl");
        // Gem et "ikke ok"-resultat kortvarigt, så vi ikke spammer GLS ved fejl
        $resultat = ['ok' => false, 'status_code' => '', 'status_label' => '', 'last_event' => '', 'last_event_time' => ''];
        gls_cache_gem($tracking_no, $resultat);
        return $resultat;
    }

    $data   = json_decode($svar, true);
    $parsed = gls_parse_svar($data);
    $resultat = [
        'ok'              => true,
        'status_code'     => $parsed['status_code'],
        'status_label'    => gls_status_til_dansk($parsed['status_code']),
        'last_event'      => $parsed['last_event'],
        'last_event_time' => $parsed['last_event_time'],
    ];
    gls_cache_gem($tracking_no, $resultat);
    return $resultat;
}
