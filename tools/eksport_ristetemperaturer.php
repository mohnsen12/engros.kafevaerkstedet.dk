<?php
/**
 * Eksport af ristetemperaturer — produktion.local → bestillingsportalen
 *
 * Læser de aktive kaffe-opskrifter (sluttemperatur) fra produktion.local's
 * database (FVST_AI_PROJEKT) og skriver app/ristetemperaturer.json i portalen.
 * Filen deployes med git (navnet matcher IKKE deploy-eksklusionen *_cache.json).
 *
 * Køres når en opskrift ændres i produktion.local — nemmest via
 * eksport_ristetemp.bat i samme mappe (eksport + commit + push i ét klik).
 *
 * Matching: JSON-nøglen er opskriftens ristet_no (de første 4 cifre af
 * BC-varenummeret, fx "1104" som passer til portalens "1104" OG "1104-60336363").
 */

// Sti til produktion.local's SQLite-database (tilpas ved flytning)
$OEKO_DB = 'C:/Users/Claus/Documents/antigravity/FVST_AI_PROJEKT/app/oeko.db';

if (!is_file($OEKO_DB)) {
    fwrite(STDERR, "[FEJL] Database ikke fundet: $OEKO_DB\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $OEKO_DB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    fwrite(STDERR, "[FEJL] Kunne ikke åbne database: " . $e->getMessage() . "\n");
    exit(1);
}

// Alle opskriftsversioner sorteret ældste→nyeste pr. vare. Den NYESTE version
// med gyldig_fra <= i dag vinder (senere rækker overskriver tidligere).
$rows = $pdo->query(
    "SELECT ristet_no, slut_temp, gyldig_fra
     FROM opskrifter
     ORDER BY ristet_no ASC, gyldig_fra ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$dato = date('Y-m-d');
$map  = [];
foreach ($rows as $r) {
    if ($r['gyldig_fra'] <= $dato && $r['slut_temp'] !== null) {
        $map[$r['ristet_no']] = (float) $r['slut_temp'];
    }
}

ksort($map);
$out = __DIR__ . '/../app/ristetemperaturer.json';
file_put_contents($out, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo count($map) . " ristetemperaturer eksporteret til app/ristetemperaturer.json\n";
echo "(i alt " . count($rows) . " opskriftsversioner i produktion.local — kun aktive med temperatur medtages)\n";
