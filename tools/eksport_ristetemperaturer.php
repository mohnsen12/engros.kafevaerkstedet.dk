<?php
/**
 * Eksport af ristetemperaturer — produktion.local → bestillingsportalen
 *
 * Henter de aktive kaffe-opskrifters sluttemperaturer fra det RIGTIGE
 * produktionssystem (produktion.local på netværket, fx 192.168.111.18)
 * og skriver app/ristetemperaturer.json i portalen. Filen deployes med git
 * (navnet matcher IKKE deploy-eksklusionen *_cache.json).
 *
 * Køres når en opskrift ændres i produktion.local — nemmest via
 * eksport_ristetemp.bat i samme mappe (eksport + commit + push i ét klik).
 *
 * Teknik: opskriftssiden indlejrer den aktive opskrift som JSON i Rediger-
 * knappen (rediger({...})) pr. hovedrække. Vi parser disse blobs og tager
 * ristet_no + slut_temp (varer uden temperatur springes over).
 */

// Produktionssystemet (skal kunne resolves — hosts/netværk)
$KILDE_URL = 'http://produktion.local/opskrifter.php';

$html = @file_get_contents($KILDE_URL);
if ($html === false) {
    // Fallback via cURL (hvis allow_url_fopen er slået fra)
    $ch = curl_init($KILDE_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $html = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($html === false || $html === null) {
        fwrite(STDERR, "[FEJL] Kunne ikke hente $KILDE_URL ($err)\n");
        exit(1);
    }
}

// Hent JSON-blobben fra Rediger-knappen i hver hovedrække (= aktiv opskrift)
preg_match_all('/<tr class="hoved-raekke">(.*?)<\/tr>/s', $html, $rows);
$map = [];
foreach ($rows[1] as $row) {
    if (!preg_match('/rediger\((\{.*?\})\)/', $row, $m)) continue;
    $ops = json_decode($m[1], true);
    if (!is_array($ops) || empty($ops['ristet_no'])) continue;
    if (!isset($ops['slut_temp']) || $ops['slut_temp'] === null) continue; // '—' på siden
    $map[$ops['ristet_no']] = (float) $ops['slut_temp'];
}

ksort($map);
$out = __DIR__ . '/../app/ristetemperaturer.json';
file_put_contents($out, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo count($map) . " ristetemperaturer hentet fra produktion.local og gemt i app/ristetemperaturer.json\n";
