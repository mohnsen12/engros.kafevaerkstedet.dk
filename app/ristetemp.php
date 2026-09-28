<?php
/**
 * Ristetemperaturer — Engros Bestillingsportal
 *
 * Kaffernes ristetemperaturer kommer fra produktion.local's opskrifter (FVST)
 * og ligger i app/ristetemperaturer.json, som eksporteres via
 * tools/eksport_ristetemp.bat og deployes med git.
 *
 * Matching: opskriften er koblet på de første 4 cifre af varenummeret
 * (fx "1104" passer til både "1104" og "1104-60336363" — resten er emballage).
 * Vises kun for kunder i RISTETEMP_KUNDER (config.php).
 */

/**
 * Læser temperatur-mappen: ['1104' => 208.5, ...] (cachet pr. request).
 */
function ristetemperaturer_map(): array {
    static $map = null;
    if ($map === null) {
        $fil = __DIR__ . '/ristetemperaturer.json';
        $map = is_file($fil) ? (json_decode((string) file_get_contents($fil), true) ?: []) : [];
    }
    return $map;
}

/**
 * Formaterer en temperatur som "Ristet til 208 °C" (208,5 vises med decimal).
 */
function ristetemp_format($temp): string {
    $s = number_format((float) $temp, 1, ',', '');
    $s = rtrim(rtrim($s, '0'), ','); // 208,0 → 208
    return 'Ristet til ' . $s . ' °C';
}

/**
 * Visningstekst for et varenummer — tom streng hvis kunden ikke skal se
 * temperaturer, eller kaffen ikke har en aktiv opskrift med temperatur.
 */
function ristetemp_tekst($vare_nr): string {
    if (!in_array($_SESSION['bc_kunde_nr'] ?? '', RISTETEMP_KUNDER, true)) {
        return '';
    }
    $vare_nr = (string) $vare_nr;
    if (strlen($vare_nr) < 4) {
        return '';
    }
    $temp = ristetemperaturer_map()[substr($vare_nr, 0, 4)] ?? null;
    return $temp === null ? '' : ristetemp_format($temp);
}
