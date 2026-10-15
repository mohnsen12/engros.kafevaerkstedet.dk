<?php
/**
 * Ordre-API — Engros Bestillingsportal
 *
 * Lad en forhandlers eget system (fx hans webshop) aflevere ordrer direkte.
 * Autentifikation: X-Api-Key-header med en nøgle genereret i admin-panelet
 * (lagret hashet i brugere.api_key_hash — tom værdi = ingen API-adgang).
 *
 * Kald: POST api_ordre.php  med JSON-krop:
 * {
 *   "ordrenr_ekstern": "WS-1001",              // valgfri, partnerens eget ordrenr (idempotens)
 *   "levering": {                               // valgfri dropship-adresse til slutkunden
 *     "navn": "...", "adresse1": "...", "adresse2": "", "postnr": "...",
 *     "by": "...", "land": "DK", "kontakt": ""
 *   },
 *   "besked": "valgfri besked til ordren",     // → Comment-linje på fakturaen
 *   "linjer": [
 *     { "varenr": "1104-60336363", "antal": 2, "variant": "HB", "enhed": "KG" }
 *   ]
 * }
 *
 * Svar: 201 { success, faktura_nr, faktura_id, ordre_id, total_ekskl_moms, linjer[] }
 * Fejl: 400/401/405/409/429/502 — altid JSON med { success:false, fejl, detaljer? }
 *
 * Sikkerhed: kun POST, nøgle hashet, fejlslagne forsøg rate-limites via portalens
 * login-lockout (samme IP-tabel), vareadgang valideres server-side (kun varer
 * tildelt forhandleren under "Vareadgang" kan bestilles — ligesom i kataloget).
 */

require_once __DIR__ . '/auth.php';   // $db + lockout-hjælpere (require_login kaldes IKKE)
require_once __DIR__ . '/bc_api.php';

header('Content-Type: application/json; charset=utf-8');

function api_svar(int $kode, array $data): void {
    http_response_code($kode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── Kun POST tilladt ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_svar(405, ['success' => false, 'fejl' => 'Kun POST er tilladt på dette endpoint.']);
}

// ─── Rate-limit: genbrug portalens login-lockout (5 fejl → 15 min. pr. IP) ────
if (is_ip_locked_out('API')) {
    api_svar(429, ['success' => false, 'fejl' => 'For mange fejlslagne forsøg — prøv igen senere.']);
}

// ─── Autentifikation: X-Api-Key (eller Authorization: Bearer) ─────────────────
$rå_nøgle = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($rå_nøgle === '' && preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
    $rå_nøgle = $m[1];
}
if ($rå_nøgle === '') {
    log_login_forsog('API', false);
    api_svar(401, ['success' => false, 'fejl' => 'Mangler API-nøgle — send den i headeren X-Api-Key.']);
}

// Slå brugeren op via hashet nøgle. Få brugere → gennemløb alle aktive (nøglen
// kan ikke slås op direkte da den lagres hashet, som et kodeord).
$stmt = $db->query("SELECT * FROM brugere WHERE aktiv = 1 AND brugernavn != 'admin'");
$api_bruger = null;
foreach ($stmt->fetchAll() as $bruger) {
    if (($bruger['api_key_hash'] ?? '') !== '' && password_verify($rå_nøgle, $bruger['api_key_hash'])) {
        $api_bruger = $bruger;
        break;
    }
}
if (!$api_bruger) {
    log_login_forsog('API', false);
    error_log("API: afvist nøgle-forsøg fra IP " . get_client_ip());
    api_svar(401, ['success' => false, 'fejl' => 'Ugyldig API-nøgle.']);
}

$bruger_id  = (int) $api_bruger['id'];
$customer_nr = $api_bruger['bc_kunde_nr'];
$customer_id = $api_bruger['bc_kunde_id'] ?? '';

// Kundens GUID kan mangle hvis forhandleren blev oprettet før feltet fik værdi —
// slå den op live (samme mønster som ordre.php).
if ($customer_id === '') {
    try {
        $kunde = bc_get_customer_by_number($customer_nr);
    } catch (Exception $e) {
        api_svar(502, ['success' => false, 'fejl' => 'Kunne ikke kontakte Business Central: ' . $e->getMessage()]);
    }
    if (!$kunde) {
        api_svar(502, ['success' => false, 'fejl' => "Kundenummeret $customer_nr blev ikke fundet i Business Central."]);
    }
    $customer_id = $kunde['id'];
}

// ─── Parse JSON-krop ──────────────────────────────────────────────────────────
$krop = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($krop)) {
    api_svar(400, ['success' => false, 'fejl' => 'Ugyldig JSON i request-body.']);
}

$ordrenr_ekstern = trim((string) ($krop['ordrenr_ekstern'] ?? ''));
$ordre_besked    = trim((string) ($krop['besked'] ?? ''));
$levering        = is_array($krop['levering'] ?? null) ? $krop['levering'] : [];
$linjer_ind      = $krop['linjer'] ?? [];

if (mb_strlen($ordrenr_ekstern) > 50) {
    api_svar(400, ['success' => false, 'fejl' => 'ordrenr_ekstern må maks være 50 tegn.']);
}
if (mb_strlen($ordre_besked) > 2000) {
    api_svar(400, ['success' => false, 'fejl' => 'besked må maks være 2000 tegn.']);
}
if (!is_array($linjer_ind) || count($linjer_ind) === 0) {
    api_svar(400, ['success' => false, 'fejl' => 'linjer skal være en liste med mindst én linje.']);
}
if (count($linjer_ind) > 100) {
    api_svar(400, ['success' => false, 'fejl' => 'Maks 100 linjer pr. ordre.']);
}

// ─── Valider leveringsadresse (valgfri — dropship til slutkunden) ─────────────
$shipping_data    = [];
$adresse_log_data = ['note' => 'Leveres til forhandleren (sendt via API)'];
$adresse_navn     = $api_bruger['firma_navn'] ?: $customer_nr;
$lever_til_type   = 'virksomhed';

if (!empty($levering)) {
    $name        = trim((string) ($levering['navn'] ?? ''));
    $addressLine1 = trim((string) ($levering['adresse1'] ?? ''));
    $addressLine2 = trim((string) ($levering['adresse2'] ?? ''));
    $postCode    = trim((string) ($levering['postnr'] ?? ''));
    $city        = trim((string) ($levering['by'] ?? ''));
    $country     = strtoupper(trim((string) ($levering['land'] ?? 'DK'))) ?: 'DK';
    $contact     = trim((string) ($levering['kontakt'] ?? ''));

    if ($name === '' || $addressLine1 === '' || $postCode === '' || $city === '') {
        api_svar(400, ['success' => false, 'fejl' => 'levering skal udfyldes komplet: navn, adresse1, postnr og by.']);
    }
    $shipping_data = [
        'shipToName'         => $name,
        'shipToContact'      => $contact,
        'shipToAddressLine1' => $addressLine1,
        'shipToAddressLine2' => $addressLine2,
        'shipToCity'         => $city,
        'shipToPostCode'     => $postCode,
        'shipToCountry'      => $country
    ];
    $adresse_log_data = ['name' => $name, 'contact' => $contact, 'addressLine1' => $addressLine1,
                         'addressLine2' => $addressLine2, 'city' => $city, 'postCode' => $postCode,
                         'country' => $country, 'note' => 'Dropshipping via API'];
    $adresse_navn   = $name;
    $lever_til_type = 'engang';
}

// ─── Idempotens: samme eksterne ordrenummer må ikke bruges to gange ───────────
if ($ordrenr_ekstern !== '') {
    $dup = $db->prepare("SELECT bc_faktura_nr FROM ordre_log WHERE bruger_id = :bid AND ordrenr_ekstern = :nr LIMIT 1");
    $dup->execute([':bid' => $bruger_id, ':nr' => $ordrenr_ekstern]);
    $eksisterende = $dup->fetch();
    if ($eksisterende) {
        api_svar(409, ['success' => false, 'fejl' => "Ordren '$ordrenr_ekstern' er allerede modtaget.", 'faktura_nr' => $eksisterende['bc_faktura_nr']]);
    }
}

// ─── Hent varegrundlag (samme kilder som portalen) ────────────────────────────
try {
    $katalog = bc_get_all_items_cached();
} catch (Exception $e) {
    api_svar(502, ['success' => false, 'fejl' => 'Kunne ikke hente varekataloget fra BC: ' . $e->getMessage()]);
}
$item_by_nr = [];
foreach ($katalog as $item) {
    if (($item['number'] ?? '') !== '') $item_by_nr[$item['number']] = $item;
}

$variant_map = [];
try { $variant_map = bc_get_variants_cached(); } catch (Exception $e) { /* valgfri */ }
$units_map = [];
try { $units_map = bc_get_units_cached(); } catch (Exception $e) { /* valgfri */ }

// Forhandlerens tilladte varer (samme tjek som katalog.php — server-side her)
$tilladte_numre = [];
$stmt = $db->prepare("SELECT item_number FROM bruger_vareadgang WHERE bruger_id = :id");
$stmt->execute([':id' => $bruger_id]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nr) {
    $tilladte_numre[strtoupper($nr)] = true;
}

// ─── Valider ALLE linjer før noget sendes til BC (alt-eller-intet) ────────────
$validerede = [];
foreach ($linjer_ind as $i => $l) {
    if (!is_array($l)) {
        api_svar(400, ['success' => false, 'fejl' => "linjer[$i] skal være et objekt."]);
    }
    $varenr = trim((string) ($l['varenr'] ?? ''));
    $antal  = $l['antal'] ?? 0;
    $variant_kode = trim((string) ($l['variant'] ?? ''));
    $enhed_kode   = trim((string) ($l['enhed'] ?? ''));

    if ($varenr === '') {
        api_svar(400, ['success' => false, 'fejl' => "linjer[$i].varenr mangler."]);
    }
    if (!is_numeric($antal) || (float) $antal <= 0 || (float) $antal > 10000) {
        api_svar(400, ['success' => false, 'fejl' => "linjer[$i].antal skal være et tal mellem 1 og 10000."]);
    }
    $antal = (int) round((float) $antal);

    $item = $item_by_nr[$varenr] ?? null;
    if (!$item) {
        api_svar(400, ['success' => false, 'fejl' => "Varen '$varenr' findes ikke i vores katalog.", 'detaljer' => "linjer[$i]"]);
    }
    if (!empty($item['blocked'])) {
        api_svar(400, ['success' => false, 'fejl' => "Varen '$varenr' er spærret og kan ikke bestilles.", 'detaljer' => "linjer[$i]"]);
    }
    if (!isset($tilladte_numre[strtoupper($varenr)])) {
        api_svar(400, ['success' => false, 'fejl' => "Varen '$varenr' er ikke tildelt din konto — kontakt Kaffeværkstedet for at få adgang.", 'detaljer' => "linjer[$i]"]);
    }

    // Variant-kode → GUID (fx 'HB', 'EM') — skal findes på netop denne vare
    $variant_id = '';
    if ($variant_kode !== '') {
        $fundet = false;
        foreach ($variant_map[$varenr] ?? [] as $v) {
            if (($v['code'] ?? '') === $variant_kode) { $variant_id = $v['id']; $fundet = true; break; }
        }
        if (!$fundet) {
            api_svar(400, ['success' => false, 'fejl' => "Varianten '$variant_kode' findes ikke på varen '$varenr'.", 'detaljer' => "linjer[$i]"]);
        }
    }

    // Enhedskode → GUID (fx 'KG', '250G')
    $unit_id = '';
    if ($enhed_kode !== '') {
        if (!isset($units_map[$enhed_kode])) {
            api_svar(400, ['success' => false, 'fejl' => "Enheden '$enhed_kode' findes ikke.", 'detaljer' => "linjer[$i]"]);
        }
        $unit_id = $units_map[$enhed_kode]['id'];
    }

    $validerede[] = [
        'item'    => $item,
        'varenr'  => $varenr,
        'antal'   => $antal,
        'variant' => $variant_id,
        'enhed'   => $unit_id,
    ];
}

// ─── Opret ordren i BC (samme flow som portalens ordre.php) ───────────────────
$bc_faktura_nr = null;
try {
    $invoice_res = bc_create_draft_invoice($customer_id, $shipping_data);
    if (!$invoice_res['success']) {
        throw new Exception("Kunne ikke oprette salgsfaktura: " . ($invoice_res['error'] ?? 'ukendt fejl'));
    }
    $bc_invoice_id = $invoice_res['data']['id'];
    $bc_faktura_nr = $invoice_res['data']['number'] ?? 'Draft';

    $lokale_linjer   = [];
    $svar_linjer     = [];
    $total_ekskl_moms = 0;
    $seq             = 10000;
    $kaffe_antal     = 0; // til emballagetillæg for D00138

    foreach ($validerede as $v) {
        $line_res = bc_add_invoice_line($bc_invoice_id, $v['item']['id'], $v['antal'], $seq, $v['variant'], $v['enhed']);
        if (!$line_res['success']) {
            throw new Exception("Kunne ikke tilføje varen '{$v['varenr']}' til fakturaen: " . ($line_res['error'] ?? ''));
        }

        $calculated_unit_price = floatval($line_res['data']['unitPrice'] ?? 0);
        $calculated_subtotal   = floatval($line_res['data']['amountExcludingTax'] ?? $line_res['data']['netAmount'] ?? ($calculated_unit_price * $v['antal']));
        $total_ekskl_moms += $calculated_subtotal;

        if (strcasecmp($v['item']['generalProductPostingGroupCode'] ?? '', EMBALLAGE_KAFFE_GRUPPE) === 0) {
            $kaffe_antal += $v['antal'];
        }

        $lokale_linjer[] = [
            'id'            => $v['item']['id'],
            'variant_id'    => $v['variant'],
            'variant_label' => '',
            'unit_label'    => '',
            'number'        => $v['varenr'],
            'name'          => $v['item']['displayName'] ?? $v['item']['name'] ?? '',
            'qty'           => $v['antal'],
            'unit_price'    => $calculated_unit_price,
            'subtotal'      => $calculated_subtotal
        ];
        $svar_linjer[] = [
            'varenr'   => $v['varenr'],
            'navn'     => $v['item']['displayName'] ?? $v['item']['name'] ?? '',
            'antal'    => $v['antal'],
            'pris_stk' => $calculated_unit_price,
            'subtotal' => $calculated_subtotal
        ];
        $seq += 10000;
    }

    // Kundespecifik regel for D00138: emballagetillæg + fragt (samme som portalen)
    if (strcasecmp($customer_nr, EMBALLAGE_KUNDE) === 0) {
        $find_item_id = function ($number) use ($item_by_nr) {
            return $item_by_nr[$number]['id'] ?? null;
        };

        if ($kaffe_antal > 0) {
            $div_id = $find_item_id(EMBALLAGE_VARE);
            if (!$div_id) throw new Exception("Varen '" . EMBALLAGE_VARE . "' (emballagetillæg) blev ikke fundet.");
            $div_res = bc_request('POST', "/salesInvoices($bc_invoice_id)/salesInvoiceLines", [
                'lineType'    => 'Item',
                'itemId'      => $div_id,
                'quantity'    => $kaffe_antal,
                'unitPrice'   => EMBALLAGE_PRIS,
                'description' => EMBALLAGE_TEKST,
                'sequence'    => $seq
            ]);
            if (!$div_res['success']) throw new Exception("Kunne ikke tilføje emballagetillæg: " . ($div_res['error'] ?? ''));
            $emb_subtotal = floatval($div_res['data']['amountExcludingTax'] ?? (EMBALLAGE_PRIS * $kaffe_antal));
            $total_ekskl_moms += $emb_subtotal;
            $lokale_linjer[] = ['id' => '', 'variant_id' => '', 'variant_label' => '', 'unit_label' => '',
                                'number' => EMBALLAGE_VARE, 'name' => EMBALLAGE_TEKST,
                                'qty' => $kaffe_antal, 'unit_price' => (float) EMBALLAGE_PRIS, 'subtotal' => $emb_subtotal];
            $seq += 10000;
        }

        $fragt_id = $find_item_id(EMBALLAGE_FRAGT_VARE);
        if (!$fragt_id) throw new Exception("Fragtvaren '" . EMBALLAGE_FRAGT_VARE . "' blev ikke fundet.");
        $fragt_res = bc_request('POST', "/salesInvoices($bc_invoice_id)/salesInvoiceLines", [
            'lineType' => 'Item', 'itemId' => $fragt_id, 'quantity' => 0, 'sequence' => $seq
        ]);
        if (!$fragt_res['success']) throw new Exception("Kunne ikke tilføje fragtlinje: " . ($fragt_res['error'] ?? ''));
        $lokale_linjer[] = ['id' => '', 'variant_id' => '', 'variant_label' => '', 'unit_label' => '',
                            'number' => EMBALLAGE_FRAGT_VARE, 'name' => $fragt_res['data']['description'] ?? 'Fragt',
                            'qty' => 0, 'unit_price' => 0, 'subtotal' => 0, 'fragt' => true];
        $seq += 10000;
    }

    // Besked → Comment-linje (fejl afbryder ikke ordren — samme som portalen)
    if ($ordre_besked !== '') {
        $cm_res = bc_add_invoice_comment($bc_invoice_id, $ordre_besked, $seq + 10000);
        if (!$cm_res['success']) {
            error_log("API: comment-linje fejlede for faktura $bc_faktura_nr: " . ($cm_res['error'] ?? 'ukendt fejl'));
        }
    }

    // Gem lokalt (samme log som portalens ordrer → ses i "Tidligere bestillinger")
    $insert = $db->prepare("
        INSERT INTO ordre_log (bruger_id, bc_faktura_nr, bc_faktura_id, lever_til_type, lever_til_navn, lever_til_json, linjer_json, total_beloeb, ordre_besked, ordrenr_ekstern, status)
        VALUES (:bruger_id, :faktura_nr, :faktura_id, :type, :navn, :adresse_json, :linjer_json, :total, :besked, :ekstern, 'oprettet')
    ");
    $insert->execute([
        ':bruger_id'    => $bruger_id,
        ':faktura_nr'   => $bc_faktura_nr,
        ':faktura_id'   => $bc_invoice_id,
        ':type'         => $lever_til_type,
        ':navn'         => $adresse_navn,
        ':adresse_json' => json_encode($adresse_log_data),
        ':linjer_json'  => json_encode($lokale_linjer),
        ':total'        => $total_ekskl_moms,
        ':besked'       => $ordre_besked,
        ':ekstern'      => $ordrenr_ekstern
    ]);
    $ordre_id = (int) $db->lastInsertId();

    error_log("API: ordre modtaget fra {$api_bruger['brugernavn']} ($customer_nr) — faktura $bc_faktura_nr");

    api_svar(201, [
        'success'        => true,
        'faktura_nr'     => $bc_faktura_nr,
        'faktura_id'     => $bc_invoice_id,
        'ordre_id'       => $ordre_id,
        'ordrenr_ekstern'=> $ordrenr_ekstern,
        'total_ekskl_moms' => $total_ekskl_moms,
        'linjer'         => $svar_linjer
    ]);

} catch (Exception $e) {
    error_log("API: fejl for {$api_bruger['brugernavn']} — " . $e->getMessage());
    api_svar(502, [
        'success' => false,
        'fejl'    => 'Der opstod en fejl under oprettelse af ordren i Business Central: ' . $e->getMessage(),
        // Fakturaen kan være delvist oprettet i BC — oplyses så partneren ikke skyder i blinde
        'faktura_nr_mulig' => $bc_faktura_nr
    ]);
}
