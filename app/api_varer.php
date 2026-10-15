<?php
/**
 * Sortiments-API — Engros Bestillingsportalen
 *
 * Læs-endpoint (kompanion til api_ordre.php): forhandlerens eget system kan
 * hente sit aktuelle sortiment — præcis de varer der er tildelt kontoen under
 * "Vareadgang" i admin-panelet, med de valgbare enheder (og vejledende priser)
 * og varianter som portalens varekatalog viser.
 *
 * Kald: GET api_varer.php med headeren  X-Api-Key: <nøgle>
 * Svar: { success, genereret, antal_varer, varer: [ { varenr, navn, gtin?,
 *          basis_enhed, enheder: [{kode, vejl_pris}], varianter: [{kode, betegnelse}] } ] }
 *
 * Anvendelse hos partneren: synkronisér jævnligt (fx natligt). Nye varer I
 * tildeler under "Vareadgang" dukker op her; fjernes tildelingen forsvinder
 * varen — og ordre-API'et afviser den fra da af. Match på 'varenr' (stabilt).
 * 'vejl_pris' er vejledende (excl. moms) — den endelige pris beregnes af BC
 * pr. ordre og returneres i api_ordre.php's svar.
 */

require_once __DIR__ . '/api_auth.php';
require_once __DIR__ . '/bc_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_svar(405, ['success' => false, 'fejl' => 'Kun GET er tilladt på dette endpoint.']);
}

$api_bruger = api_autentificer();
$bruger_id  = (int) $api_bruger['id'];

// ─── Varegrundlag (samme kilder som varekataloget) ────────────────────────────
try {
    $katalog = bc_get_all_items_cached();
} catch (Exception $e) {
    api_svar(502, ['success' => false, 'fejl' => 'Kunne ikke hente varekataloget fra BC: ' . $e->getMessage()]);
}

$variant_map = [];
try { $variant_map = bc_get_variants_cached(); } catch (Exception $e) { /* valgfri */ }
$units_map = [];
try { $units_map = bc_get_units_cached(); } catch (Exception $e) { /* valgfri */ }
$item_units      = []; // itemNo => [koder] fra Item Unit of Measure (hvis kvwoo udstiller den)
try { $item_units = bc_get_item_units_cached(); } catch (Exception $e) {}
$item_unit_codes = []; // itemNo => [koder] fra prislisterne (fallback)
try { $item_unit_codes = bc_get_item_unit_codes_cached(); } catch (Exception $e) {}
$unit_prices = [];     // itemNo => [enhedskode => vejledende pris]
try { $unit_prices = bc_get_item_unit_prices_cached(); } catch (Exception $e) {}

// Forhandlerens tilladte varer (samme kilde som katalog.php)
$tilladte_numre = [];
$stmt = $GLOBALS['db']->prepare("SELECT item_number FROM bruger_vareadgang WHERE bruger_id = :id");
$stmt->execute([':id' => $bruger_id]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nr) {
    $tilladte_numre[strtoupper($nr)] = true;
}

// ─── Byg sortimentet ──────────────────────────────────────────────────────────
$varer = [];
foreach ($katalog as $item) {
    $nr = $item['number'] ?? '';
    if ($nr === '' || !isset($tilladte_numre[strtoupper($nr)]) || !empty($item['blocked'])) {
        continue;
    }
    $basis = $item['baseUnitOfMeasureCode'] ?? '';
    $basis_pris = $unit_prices[$nr]['BASE'] ?? floatval($item['unitPrice'] ?? 0);

    // Enheder: basisenheden altid + Item-UoM + prisliste-enheder (som kataloget),
    // hver med vejledende pris pr. enhed (fald tilbage til BASE/basispris).
    $set = [];
    $enheder = [];
    foreach (array_merge([$basis], $item_units[$nr] ?? [], $item_unit_codes[$nr] ?? []) as $kode) {
        if ($kode === '' || isset($set[$kode]) || !isset($units_map[$kode])) continue;
        $set[$kode] = true;
        $enheder[] = [
            'kode'      => $kode,
            'vejl_pris' => $unit_prices[$nr][$kode] ?? ($kode === $basis ? $basis_pris : null),
        ];
    }

    $varianter = [];
    foreach ($variant_map[$nr] ?? [] as $v) {
        $varianter[] = ['kode' => $v['code'] ?? '', 'betegnelse' => $v['label'] ?? ($v['code'] ?? '')];
    }

    $vare = [
        'varenr'       => $nr,
        'navn'         => $item['displayName'] ?? $item['name'] ?? '',
        'basis_enhed'  => $basis,
        'enheder'      => $enheder,
        'varianter'    => $varianter,
    ];
    if (!empty($item['displayName2']))    $vare['navn2'] = $item['displayName2'];
    if (!empty($item['gtin']))            $vare['gtin']  = $item['gtin'];
    $varer[] = $vare;
}

api_svar(200, [
    'success'    => true,
    'genereret'  => date('c'),
    'antal_varer' => count($varer),
    'varer'      => $varer,
]);
