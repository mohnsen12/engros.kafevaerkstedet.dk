<?php
/**
 * Business Central API — Engros Bestillingsportal
 * 
 * Håndterer OAuth2 token cache og kommunikation med Business Central API v2.0.
 * Denne fil er standalone og deler IKKE kode med andre projekter.
 */

require_once __DIR__ . '/config.php';

/**
 * Slår et værtsnavn op til en IPv4-adresse på en robust måde.
 *
 * På denne maskine afviser den lokale DNS-server (192.168.111.1) med mellemrum
 * opslag af Business Central-hosten, hvilket får gethostbyname() (og dermed cURL)
 * til at fejle med "Could not resolve host". Rækkefølgen her er derfor:
 *   1. Persistent fil-cache (dns_cache.json) — så en IP, der én gang er fundet,
 *      genbruges selv når DNS senere fejler.
 *   2. gethostbyname() (systemets resolver).
 *   3. DNS-over-HTTPS mod Google (8.8.8.8) ramt direkte på IP — kræver ikke DNS.
 * En succesfuld IP gemmes i cachen. Returnerer null hvis intet virker.
 */
function bc_resolve_host($host, $force = false) {
    static $mem = [];
    if (!$force && !empty($mem[$host])) {
        return $mem[$host];
    }

    // 1. Persistent fil-cache (gyldig i 24 timer)
    $cache = file_exists(DNS_CACHE) ? json_decode(file_get_contents(DNS_CACHE), true) : [];
    if (!is_array($cache)) $cache = [];

    // Ved force (efter en fejlet forbindelse) springer vi systemets resolver og den
    // gamle cache over og går direkte til DoH for at få en frisk, fungerende IP.
    if ($force) {
        $ip = bc_doh_lookup($host);
        if ($ip) {
            $cache[$host] = ['ip' => $ip, 'ts' => time()];
            @file_put_contents(DNS_CACHE, json_encode($cache));
            return $mem[$host] = $ip;
        }
        // DoH fejlede — fald tilbage på normal logik nedenfor
    }

    // 2. Systemets resolver
    $ip = @gethostbyname($host);
    if ($ip && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $cache[$host] = ['ip' => $ip, 'ts' => time()];
        @file_put_contents(DNS_CACHE, json_encode($cache));
        return $mem[$host] = $ip;
    }

    // 3. DNS-over-HTTPS via Google (rammes på IP, så det ikke selv kræver DNS)
    $ip = bc_doh_lookup($host);
    if ($ip) {
        $cache[$host] = ['ip' => $ip, 'ts' => time()];
        @file_put_contents(DNS_CACHE, json_encode($cache));
        return $mem[$host] = $ip;
    }

    // 4. Fald tilbage på en cachet IP, selv hvis den er gammel — bedre end at fejle
    if (isset($cache[$host]['ip'])) {
        return $mem[$host] = $cache[$host]['ip'];
    }

    return null;
}

/**
 * Slår en A-record op via Google DNS-over-HTTPS. Forbindelsen sker direkte til
 * 8.8.8.8 med Host/SNI = dns.google, så funktionen ikke selv afhænger af DNS.
 */
function bc_doh_lookup($host) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://dns.google/resolve?name=' . rawurlencode($host) . '&type=A');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_RESOLVE, ['dns.google:443:8.8.8.8']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/dns-json']);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return null;
    $data = json_decode($resp, true);
    if (empty($data['Answer'])) return null;

    foreach ($data['Answer'] as $answer) {
        // Type 1 = A-record (IPv4)
        if (isset($answer['type']) && $answer['type'] === 1
            && filter_var($answer['data'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $answer['data'];
        }
    }
    return null;
}

/**
 * Injicerer en værtsnavn-til-IP kortlægning i cURL, så vi omgår en upålidelig
 * lokal DNS-server på Windows.
 */
function bc_dns_resolve_curl($ch, $url, $force = false) {
    $parsed = parse_url($url);
    if ($parsed && isset($parsed['host'])) {
        $host = $parsed['host'];
        $ip = bc_resolve_host($host, $force);
        if ($ip) {
            $port = $parsed['port'] ?? (($parsed['scheme'] ?? 'https') === 'https' ? 443 : 80);
            curl_setopt($ch, CURLOPT_RESOLVE, ["$host:$port:$ip"]);
        }
    }
}


/**
 * Henter et gyldigt access token fra cache eller Microsoft OAuth2 endpoint.
 */
function bc_get_token() {
    // Tjek om vi har et gyldigt token i cache
    if (file_exists(TOKEN_CACHE)) {
        $cache = json_decode(file_get_contents(TOKEN_CACHE), true);
        if ($cache && isset($cache['access_token']) && isset($cache['expires_at'])) {
            // Hvis tokenet er gyldigt i mindst 60 sekunder endnu, brug det
            if ($cache['expires_at'] > time() + 60) {
                return $cache['access_token'];
            }
        }
    }

    // Hvis ikke, hent et nyt token
    $post_fields = [
        'grant_type'    => 'client_credentials',
        'client_id'     => BC_CLIENT_ID,
        'client_secret' => BC_SECRET,
        'scope'         => BC_SCOPE
    ];

    // Forsøg op til 3 gange ved forbigående netværks-/DNS-fejl
    $max_forsoeg = 3;
    $response = false;
    $http_code = 0;
    $curl_error = '';

    for ($forsoeg = 1; $forsoeg <= $max_forsoeg; $forsoeg++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, BC_TOKEN_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Tving IPv4 for at undgå DNS timeout på Windows
        bc_dns_resolve_curl($ch, BC_TOKEN_URL, $forsoeg > 1); // Tving frisk DoH-opslag fra 2. forsøg
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);
        curl_close($ch);

        if (!$curl_error) {
            break;
        }

        // Forbigående netværksfejl der bør prøves igen: 6=DNS, 7=connect, 28=timeout,
        // 35=SSL connect, 52=tomt svar, 55=send-fejl, 56=recv-fejl (connection reset).
        $forbigaaende = in_array($curl_errno, [6, 7, 28, 35, 52, 55, 56], true);
        error_log("BC Token cURL Fejl (forsøg $forsoeg/$max_forsoeg, errno $curl_errno): $curl_error");
        if ($forbigaaende && $forsoeg < $max_forsoeg) {
            usleep(300000);
            continue;
        }
        break;
    }

    if ($curl_error) {
        throw new Exception("cURL fejl ved hentning af token: " . $curl_error);
    }

    if ($http_code !== 200) {
        throw new Exception("Kunne ikke hente token fra Microsoft. HTTP-kode: $http_code. Svar: " . $response);
    }

    $data = json_decode($response, true);
    if (!isset($data['access_token'])) {
        throw new Exception("Ugyldigt token svar fra Microsoft: " . $response);
    }

    // Gem i cache
    $expires_in = isset($data['expires_in']) ? intval($data['expires_in']) : 3599;
    $cache_data = [
        'access_token' => $data['access_token'],
        'expires_at'   => time() + $expires_in
    ];
    
    file_put_contents(TOKEN_CACHE, json_encode($cache_data));

    return $data['access_token'];
}

/**
 * Udfører et API-kald mod Business Central.
 *
 * @param array $extra_headers Ekstra HTTP-headers (fx ['If-Match: *'] til PATCH/DELETE).
 */
function bc_request($method, $endpoint, $data = null, $extra_headers = []) {
    try {
        $token = bc_get_token();
    } catch (Exception $e) {
        error_log("BC Token Fejl: " . $e->getMessage());
        return [
            'success' => false,
            'code'    => 0,
            'error'   => "Kunne ikke autorisere mod Business Central: " . $e->getMessage()
        ];
    }

    // Hvis endpoint er en fuld URL (fx fra nextLink), så brug den, ellers byg den
    $url = (strpos($endpoint, 'http') === 0) ? $endpoint : BC_API_BASE . $endpoint;

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ];
    foreach ($extra_headers as $h) {
        $headers[] = $h;
    }

    $json_data = null;
    if ($data !== null) {
        $json_data = json_encode($data);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($json_data);
    }

    // Forsøg op til 3 gange ved forbigående netværks-/DNS-fejl. Den lokale DNS-server
    // er ustabil, så vi tvinger en frisk IP-opslag (via DoH) inden hvert nyt forsøg.
    $max_forsoeg = 3;
    $response = false;
    $http_code = 0;
    $curl_error = '';

    for ($forsoeg = 1; $forsoeg <= $max_forsoeg; $forsoeg++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Tving IPv4 for at undgå DNS timeout på Windows
        bc_dns_resolve_curl($ch, $url, $forsoeg > 1); // Tving frisk DoH-opslag fra 2. forsøg
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($json_data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);
        curl_close($ch);

        if (!$curl_error) {
            break; // Succes (på HTTP-niveau) — håndteres nedenfor
        }

        // CURLE_COULDNT_RESOLVE_HOST=6, CURLE_COULDNT_CONNECT=7, CURLE_OPERATION_TIMEDOUT=28
        // Forbigående netværksfejl der bør prøves igen: 6=DNS, 7=connect, 28=timeout,
        // 35=SSL connect, 52=tomt svar, 55=send-fejl, 56=recv-fejl (connection reset).
        $forbigaaende = in_array($curl_errno, [6, 7, 28, 35, 52, 55, 56], true);
        error_log("BC API cURL Fejl (forsøg $forsoeg/$max_forsoeg, errno $curl_errno): $curl_error på URL: $url");

        if ($forbigaaende && $forsoeg < $max_forsoeg) {
            usleep(300000); // 0,3 sek pause inden nyt forsøg (med tvunget DoH-opslag)
            continue;
        }
        break;
    }

    if ($curl_error) {
        return [
            'success' => false,
            'code'    => 0,
            'error'   => "Netværksfejl under kommunikation med BC: " . $curl_error
        ];
    }

    $decoded = json_decode($response, true);
    
    $success = ($http_code >= 200 && $http_code < 300);
    
    return [
        'success' => $success,
        'code'    => $http_code,
        'data'    => $decoded,
        'error'   => !$success ? ($decoded['error']['message'] ?? "Ukendt fejl (HTTP $http_code)") : null
    ];
}

/**
 * Henter en specifik kunde ud fra kundenummer (fx '20000').
 * Bruges til at slå kundens GUID (id) op.
 */
function bc_get_customer_by_number($customer_number) {
    $filter = rawurlencode("number eq '" . str_replace("'", "''", $customer_number) . "'");
    $result = bc_request('GET', "/customers?\$filter=" . $filter);

    // Skeln mellem "kald fejlede" og "kald lykkedes, men ingen match".
    // Et fejlet kald (netværk/DNS/auth) må IKKE fortolkes som "kunden findes ikke".
    if (!$result['success']) {
        throw new Exception($result['error'] ?? "Ukendt fejl ved opslag mod Business Central.");
    }

    if (isset($result['data']['value'][0])) {
        return $result['data']['value'][0];
    }
    return null; // Kald lykkedes, men kundenummeret findes reelt ikke
}

/**
 * Henter varer (katalog) fra Business Central.
 * Henter alle varer ved at følge @odata.nextLink hvis der er mange.
 *
 * @param bool $include_blocked Hvis true hentes også spærrede varer (bruges til admin-oversigten).
 */
function bc_get_items($include_blocked = false) {
    $items = [];
    // VIGTIGT: filter-værdien skal URL-encodes — ellers afviser cURL URL'en pga. mellemrum.
    $endpoint = $include_blocked
        ? "/items"
        : "/items?\$filter=" . rawurlencode("blocked eq false");

    while ($endpoint) {
        $result = bc_request('GET', $endpoint);
        if (!$result['success']) {
            error_log("Fejl under hentning af varer: " . ($result['error'] ?? ''));
            break;
        }
        
        if (isset($result['data']['value'])) {
            $items = array_merge($items, $result['data']['value']);
        }
        
        // Tjek om der er flere sider
        $endpoint = $result['data']['@odata.nextLink'] ?? null;
    }

    return $items;
}

/**
 * Henter en vares billede (binært) fra Business Central.
 * Returnerer ['code' => HTTP-kode, 'body' => billed-bytes (tom hvis intet billede)].
 */
function bc_get_item_picture($item_id) {
    try {
        $token = bc_get_token();
    } catch (Exception $e) {
        return ['code' => 0, 'body' => ''];
    }
    $url = BC_API_BASE . "/items(" . $item_id . ")/picture/pictureContent";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    bc_dns_resolve_curl($ch, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => ($body === false ? '' : $body)];
}

/**
 * Henter ALLE varer (inkl. spærrede) med fil-caching, så vi ikke rammer BC ved hvert sidevisning.
 * Bruges af både admin-vareoversigten og kataloget. Cachen deles via items_cache.json.
 *
 * @param int $lifetime Cachens levetid i sekunder (default 600 = 10 min).
 * @return array Liste af vare-objekter, eller tom liste ved fejl.
 */
function bc_get_all_items_cached($lifetime = 600) {
    $cache_file = __DIR__ . '/items_cache.json';

    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $lifetime)) {
        $data = json_decode(file_get_contents($cache_file), true);
        if (is_array($data) && !empty($data)) {
            return $data;
        }
    }

    $items = bc_get_items(true); // Inkl. spærrede varer
    if (!empty($items)) {
        file_put_contents($cache_file, json_encode($items));
    }
    return $items;
}

/**
 * Bygger base-URL'en til det custom kvwoo-API (hvor bl.a. ship-to adresser ligger).
 */
function bc_kvwoo_base() {
    return "https://api.businesscentral.dynamics.com/v2.0/" . BC_TENANT . "/" . BC_ENVIRONMENT
         . "/api/kvwoo/woocommerce/v1.0/companies(" . BC_COMPANY . ")";
}

/**
 * Henter en kundes leveringsadresser (ship-to) fra Business Central.
 *
 * BEMÆRK: Standard-API'et (api/v2.0) eksponerer IKKE ship-to adresser — de findes
 * kun via det custom kvwoo-API, hvor de filtreres på kundenummer (customerNo).
 *
 * @param string $customer_no Kundenummeret (fx 'D00138')
 * @return array Liste af ship-to adresser. Felter: id, customerNo, code, displayName,
 *               contact, addressLine1, addressLine2, city, postalCode, country, ...
 */
function bc_get_customer_addresses($customer_no) {
    $base   = bc_kvwoo_base();
    $filter = rawurlencode("customerNo eq '" . str_replace("'", "''", $customer_no) . "'");
    $result = bc_request('GET', "$base/shipToAddresses?\$filter=" . $filter);
    if ($result['success'] && isset($result['data']['value'])) {
        return $result['data']['value'];
    }
    return [];
}

/**
 * Opretter en ny ship-to leveringsadresse på kunden i Business Central (via kvwoo).
 *
 * @param string $customer_no Kundenummeret (fx 'D00138')
 * @param array  $address_data Forventede nøgler: code, name, addressLine1, addressLine2,
 *               city, postCode, country, contact, phone, email
 */
function bc_create_customer_address($customer_no, $address_data) {
    $base = bc_kvwoo_base();
    $payload = [
        'customerNo'   => $customer_no,
        'code'         => $address_data['code'] ?? '',
        'displayName'  => $address_data['name'] ?? '',
        'addressLine1' => $address_data['addressLine1'] ?? '',
        'addressLine2' => $address_data['addressLine2'] ?? '',
        'city'         => $address_data['city'] ?? '',
        'postalCode'   => $address_data['postCode'] ?? '',
        'country'      => $address_data['country'] ?? 'DK',
        'contact'      => $address_data['contact'] ?? '',
        'phoneNumber'  => $address_data['phone'] ?? '',
        'email'        => $address_data['email'] ?? ''
    ];
    $result = bc_request('POST', "$base/shipToAddresses", $payload);

    // kvwoo ignorerer 'displayName' ved oprettelse (sætter kundens navn). Vi retter
    // derfor navnet med en efterfølgende PATCH, så ship-to-adressen får det rigtige navn.
    if ($result['success'] && !empty($address_data['name']) && !empty($result['data']['id'])) {
        $patch = bc_request('PATCH', "$base/shipToAddresses(" . $result['data']['id'] . ")",
            ['displayName' => $address_data['name']], ['If-Match: *']);
        if ($patch['success']) {
            return $patch;
        }
    }
    return $result;
}

/**
 * Opdaterer en eksisterende ship-to leveringsadresse i Business Central (via kvwoo).
 * Ændringen bliver dermed permanent i BC.
 *
 * @param string $address_id Adressens id (GUID)
 * @param array  $address_data Felter der må opdateres: name, addressLine1, addressLine2,
 *               city, postCode, country, contact, phone, email
 */
function bc_update_customer_address($address_id, $address_data) {
    $base = bc_kvwoo_base();
    $payload = [];
    if (isset($address_data['name']))         $payload['displayName']  = $address_data['name'];
    if (isset($address_data['addressLine1'])) $payload['addressLine1'] = $address_data['addressLine1'];
    if (isset($address_data['addressLine2'])) $payload['addressLine2'] = $address_data['addressLine2'];
    if (isset($address_data['city']))         $payload['city']         = $address_data['city'];
    if (isset($address_data['postCode']))     $payload['postalCode']   = $address_data['postCode'];
    if (isset($address_data['country']))      $payload['country']      = $address_data['country'];
    if (isset($address_data['contact']))      $payload['contact']      = $address_data['contact'];
    if (isset($address_data['phone']))        $payload['phoneNumber']  = $address_data['phone'];
    if (isset($address_data['email']))        $payload['email']        = $address_data['email'];

    // If-Match: * springer ETag-tjekket over, så vi altid kan opdatere seneste version.
    return bc_request('PATCH', "$base/shipToAddresses(" . $address_id . ")", $payload, ['If-Match: *']);
}

/**
 * Sletter en ship-to leveringsadresse i Business Central (via kvwoo) ud fra dens id.
 */
function bc_delete_customer_address($address_id) {
    $base = bc_kvwoo_base();
    return bc_request('DELETE', "$base/shipToAddresses(" . $address_id . ")", null, ['If-Match: *']);
}

/**
 * Opretter en kladde-salgsfaktura i Business Central.
 */
function bc_create_draft_invoice($customer_id, $shipping_data = []) {
    // Forbered data til at oprette fakturaen
    $post_data = [
        'customerId' => $customer_id
    ];

    // Leveringsadresse udfyldes som shipTo*-felter direkte på fakturaen.
    // (salesInvoice har ikke shipToCode — adressen kopieres som felter.)
    if (!empty($shipping_data)) {
        $felter = ['shipToName', 'shipToContact', 'shipToAddressLine1', 'shipToAddressLine2',
                   'shipToCity', 'shipToPostCode', 'shipToCountry'];
        foreach ($felter as $f) {
            if (isset($shipping_data[$f]) && $shipping_data[$f] !== '') {
                $post_data[$f] = $shipping_data[$f];
            }
        }
    }

    return bc_request('POST', "/salesInvoices", $post_data);
}

/**
 * Tilføjer en varelinje til en salgsfaktura.
 * BC vil automatisk udregne pris og rabat ud fra kundens prisliste.
 *
 * @param string      $invoice_id Fakturaens GUID
 * @param string      $item_id    Varens GUID (itemId — IKKE lineObjectId)
 * @param float       $quantity   Antal
 * @param int|null    $line_number Sekvensnummer (valgfrit)
 * @param string|null $variant_id Variantens GUID (itemVariantId), hvis varianten er valgt
 * @param string|null $unit_id    Enhedens GUID (unitOfMeasureId), hvis en bestemt enhed er valgt
 */
function bc_add_invoice_line($invoice_id, $item_id, $quantity, $line_number = null, $variant_id = null, $unit_id = null) {
    $post_data = [
        'lineType' => 'Item',
        'itemId'   => $item_id,
        'quantity' => floatval($quantity)
    ];

    if (!empty($variant_id)) {
        $post_data['itemVariantId'] = $variant_id;
    }

    if (!empty($unit_id)) {
        $post_data['unitOfMeasureId'] = $unit_id;
    }

    if ($line_number !== null) {
        $post_data['sequence'] = intval($line_number);
    }

    return bc_request('POST', "/salesInvoices(" . $invoice_id . ")/salesInvoiceLines", $post_data);
}

/**
 * Henter alle måleenheder fra BC (cachet) som en map: kode => ['id'=>GUID, 'label'=>tekst].
 * Bruges til at oversætte en enhedskode (fx '250G') til det unitOfMeasureId en salgslinje kræver.
 */
function bc_get_units_cached($lifetime = 3600) {
    $cache_file = __DIR__ . '/units_cache.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $lifetime)) {
        $d = json_decode(file_get_contents($cache_file), true);
        if (is_array($d) && !empty($d)) return $d;
    }
    $map = [];
    $r = bc_request('GET', "/unitsOfMeasure");
    if ($r['success']) {
        foreach ($r['data']['value'] ?? [] as $u) {
            if (!empty($u['code'])) {
                $map[$u['code']] = ['id' => $u['id'], 'label' => ($u['displayName'] ?: $u['code'])];
            }
        }
    }
    if (!empty($map)) file_put_contents($cache_file, json_encode($map));
    return $map;
}

/**
 * Henter — pr. varenummer — de enhedskoder varen er prissat i (fra prislisterne, via kvwoo).
 * Det er BC's "Item Units of Measure" set, som afgør hvilke enheder (fx 225G, 500G) en vare
 * kan sælges i. Returnerer map: itemNo => ['225G','500G', ...].
 */
function bc_get_item_unit_codes_cached($lifetime = 600) {
    $cache_file = __DIR__ . '/item_units_cache.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $lifetime)) {
        $d = json_decode(file_get_contents($cache_file), true);
        if (is_array($d)) return $d;
    }
    $kv = bc_kvwoo_base();
    $samlet = [];
    $endpoint = "$kv/wooPriceListLines";
    while ($endpoint) {
        $r = bc_request('GET', $endpoint);
        if (!$r['success']) {
            error_log("Fejl under hentning af prislinjer (enheder): " . ($r['error'] ?? ''));
            break;
        }
        foreach ($r['data']['value'] ?? [] as $l) {
            $no = $l['assetNo'] ?? '';
            $u  = $l['unitOfMeasureCode'] ?? '';
            if ($no !== '' && $u !== '') {
                $samlet[$no][$u] = true;
            }
        }
        $endpoint = $r['data']['@odata.nextLink'] ?? null;
    }
    $out = [];
    foreach ($samlet as $no => $units) {
        $out[$no] = array_keys($units);
    }
    if (!empty($out)) file_put_contents($cache_file, json_encode($out));
    return $out;
}

/**
 * Henter vejledende pris pr. vare + enhed fra prislisterne (via kvwoo), cachet.
 * Returnerer map: itemNo => [ enhedskode => pris ].
 *
 * Når der findes flere prislinjer for samme vare+enhed, foretrækkes FORHANDLERE-prislisten
 * (det er forhandlernes liste). Prisen er vejledende — den endelige, kundespecifikke pris
 * beregnes af BC ved bestilling.
 */
function bc_get_item_unit_prices_cached($lifetime = 600) {
    $cache_file = __DIR__ . '/item_unit_prices_cache.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $lifetime)) {
        $d = json_decode(file_get_contents($cache_file), true);
        if (is_array($d)) return $d;
    }
    $kv = bc_kvwoo_base();
    $map    = []; // itemNo => kode => pris
    $erpref = []; // itemNo => kode => true (nuværende værdi er fra FORHANDLERE)
    $endpoint = "$kv/wooPriceListLines?\$top=5000";
    while ($endpoint) {
        $r = bc_request('GET', $endpoint);
        if (!$r['success']) break;
        foreach ($r['data']['value'] ?? [] as $l) {
            if (($l['status'] ?? '') !== 'Active') continue;
            $no   = $l['assetNo'] ?? '';
            $code = $l['unitOfMeasureCode'] ?? '';
            if ($no === '' || $code === '') continue; // tom = basisenhed (pris fra item.unitPrice)
            $pris = floatval($l['unitPrice'] ?? 0);
            $pref = (($l['priceListCode'] ?? '') === 'FORHANDLERE');
            if (!isset($map[$no][$code]) || ($pref && empty($erpref[$no][$code]))) {
                $map[$no][$code]    = $pris;
                $erpref[$no][$code] = $pref;
            }
        }
        $endpoint = $r['data']['@odata.nextLink'] ?? null;
    }
    file_put_contents($cache_file, json_encode($map));
    return $map;
}

/**
 * Henter varens KOMPLETTE liste af salgsenheder fra BC's "Item Unit of Measure"-tabel,
 * udstillet via kvwoo-API'et som entiteten 'wooItemUnitsOfMeasure'.
 *
 * Dette er den autoritative kilde (modsat prislisterne, der mangler enheder uden egen pris,
 * fx 250G). Returnerer map: itemNo => ['KG','250G', ...].
 *
 * Hvis endpointet endnu ikke findes i BC-extensionen, returneres en tom map (og kataloget
 * falder tilbage på prisliste-enhederne), så app'en virker uanset.
 *
 * Forventet entitet (read-only) i api/kvwoo/woocommerce/v1.0:
 *   wooItemUnitsOfMeasure: { itemNo, code, qtyPerUnitOfMeasure }
 */
function bc_get_item_units_cached($lifetime = 600) {
    $cache_file = __DIR__ . '/item_uom_cache.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $lifetime)) {
        $d = json_decode(file_get_contents($cache_file), true);
        if (is_array($d)) return $d;
    }
    $kv = bc_kvwoo_base();
    $samlet = [];
    $tilgaengeligt = false;
    $endpoint = "$kv/wooItemUnitsOfMeasure?\$top=5000";
    while ($endpoint) {
        $r = bc_request('GET', $endpoint);
        if (!$r['success']) {
            // Endpointet findes (endnu) ikke — det er ok, vi falder tilbage på prislisterne.
            break;
        }
        $tilgaengeligt = true;
        foreach ($r['data']['value'] ?? [] as $u) {
            $no   = $u['itemNo'] ?? '';
            $code = $u['code'] ?? ($u['unitOfMeasureCode'] ?? '');
            if ($no !== '' && $code !== '') {
                $samlet[$no][$code] = true;
            }
        }
        $endpoint = $r['data']['@odata.nextLink'] ?? null;
    }
    $out = [];
    foreach ($samlet as $no => $codes) {
        $out[$no] = array_keys($codes);
    }
    // Cache altid (også tom), så vi ikke spørger BC ved hver visning. Når endpointet
    // tilføjes, dukker enhederne op inden for cache-vinduet (10 min).
    file_put_contents($cache_file, json_encode($out));
    return $out;
}

/**
 * Henter alle varevarianter fra den custom kvwoo-API (cachet) og grupperer dem pr. varenummer.
 * kvwoo's 'systemId' er identisk med BC's standard variant-GUID (itemVariantId på en salgslinje),
 * og 'description2' er den brugervenlige variant-tekst (fx "Hele Bønner", "Malet til Espresso").
 *
 * @return array Map: itemNo => [ ['id'=>systemId, 'code'=>code, 'label'=>tekst], ... ]
 */
function bc_get_variants_cached($lifetime = 600) {
    $cache_file = __DIR__ . '/variants_cache.json';

    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $lifetime)) {
        $data = json_decode(file_get_contents($cache_file), true);
        if (is_array($data)) {
            return $data;
        }
    }

    $kvbase = "https://api.businesscentral.dynamics.com/v2.0/" . BC_TENANT . "/" . BC_ENVIRONMENT
            . "/api/kvwoo/woocommerce/v1.0/companies(" . BC_COMPANY . ")";

    $map = [];
    $endpoint = $kvbase . "/wooItemVariants";
    while ($endpoint) {
        $result = bc_request('GET', $endpoint);
        if (!$result['success']) {
            error_log("Fejl under hentning af varianter: " . ($result['error'] ?? ''));
            break;
        }
        foreach ($result['data']['value'] ?? [] as $v) {
            $itemNo = $v['itemNo'] ?? '';
            if ($itemNo === '' || empty($v['systemId'])) continue;
            $label = trim($v['description2'] ?? '');
            $map[$itemNo][] = [
                'id'    => $v['systemId'],
                'code'  => $v['code'] ?? '',
                'label' => $label !== '' ? $label : ($v['code'] ?? '')
            ];
        }
        $endpoint = $result['data']['@odata.nextLink'] ?? null;
    }

    if (!empty($map)) {
        file_put_contents($cache_file, json_encode($map));
    }
    return $map;
}

/**
 * Henter en specifik salgsfaktura inklusiv linjer.
 */
function bc_get_invoice($invoice_id) {
    return bc_request('GET', "/salesInvoices(" . $invoice_id . ")?\$expand=salesInvoiceLines");
}

/**
 * Henter status og GLS-forsendelsesoplysninger for kundens salgsfakturaer fra BC's
 * publicerede OData V4 web service "Claus_salgsfaktura" (Sales Header, uposterede fakturaer).
 *
 * Standard-API'et (api/v2.0) udstiller IKKE de custom-felter vi har brug for
 * (frigivet-status og GLS-tracking), så vi læser dem her i stedet.
 *
 * Vi henter alle kundens fakturaer i ÉT kald og indekserer på fakturanummer ('No'),
 * så ordrehistorikken kan slå op uden ét kald pr. ordre.
 *
 * @param string $customer_no Kundenummeret (fx 'D00138')
 * @return array Map: fakturanummer => [
 *                   'status'              => 'Open'|'Released'|...,
 *                   'gls_tracking'        => 'nr, nr, ...' (rå streng fra BC),
 *                   'gls_numre'           => ['nr', 'nr', ...] (opdelt liste),
 *                   'gls_shipment_status' => 'Sent'|'Not Sent'|...
 *               ]
 */
function bc_get_customer_invoice_status($customer_no) {
    $select = '$select=' . rawurlencode('No,Status,GLS_Tracking_Number,GLS_Shipment_Status');
    $filter = '$filter=' . rawurlencode("Sell_to_Customer_No eq '" . str_replace("'", "''", $customer_no) . "'");
    $url    = BC_ODATA_BASE . "/Claus_salgsfaktura?$select&$filter&\$top=1000";

    $result = bc_request('GET', $url);
    $map = [];
    if (!$result['success'] || !isset($result['data']['value'])) {
        // Endpointet er ikke tilgængeligt (eller fejlede) — returnér tom map, så
        // historikken falder pænt tilbage på den lokalt gemte status.
        return $map;
    }

    foreach ($result['data']['value'] as $row) {
        $no = (string) ($row['No'] ?? '');
        if ($no === '') continue;
        $tracking = trim((string) ($row['GLS_Tracking_Number'] ?? ''));
        $numre = [];
        if ($tracking !== '') {
            foreach (preg_split('/[,;\s]+/', $tracking) as $stk) {
                $stk = trim($stk);
                if ($stk !== '') $numre[] = $stk;
            }
        }
        $map[$no] = [
            'status'              => (string) ($row['Status'] ?? ''),
            'gls_tracking'        => $tracking,
            'gls_numre'           => $numre,
            'gls_shipment_status' => (string) ($row['GLS_Shipment_Status'] ?? '')
        ];
    }
    return $map;
}
