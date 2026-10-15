# Ordre-API — Engros Bestillingsportalen (Kaffeværkstedet)

Dette API lader en forhandlers eget system (fx en webshop) aflevere ordrer direkte
til Kaffeværkstedet. Ordren bliver til en salgsfaktura-kladde i Business Central —
præcis som en ordre lagt i portalen — og priserne beregnes automatisk ud fra
forhandlerens aftalepriser.

## Adgang

1. Kaffeværkstedet aktiverer API-adgang for forhandleren i admin-panelet (🤖-knappen)
   og udleverer en **API-nøgle**.
2. Nøglen sendes med alle kald i headeren `X-Api-Key`.
3. Mistes nøglen, genererer Kaffeværkstedet en ny — den gamle holder op med at virke.

**Endpoint:** `https://DOMAENE/api_ordre.php` *(domænet oplyses af Kaffeværkstedet)*

## Kald

`POST` med JSON-body og headeren `X-Api-Key`. Eksempel i PHP:

```php
$ordre = [
    'ordrenr_ekstern' => 'WS-2026-1001',          // jeres eget ordrenummer (anbefalet!)
    'besked'          => 'Leveres før fredag',     // valgfri besked på fakturaen
    // 'levering' => [                           // valgfri: lever direkte til jeres kunde
    //     'navn'     => 'Café Eksempel',
    //     'adresse1' => 'Nørrebrogade 42',
    //     'adresse2' => '',
    //     'postnr'   => '2200',
    //     'by'       => 'København N',
    //     'land'     => 'DK',
    //     'kontakt'  => 'Jens',
    // ],
    'linjer' => [
        ['varenr' => '1104-60336363', 'antal' => 2, 'variant' => 'HB', 'enhed' => 'KG'],
        ['varenr' => '1103-53790095', 'antal' => 18],
    ],
];

$ch = curl_init('https://DOMAENE/api_ordre.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Api-Key: DIN-NOEGLE'],
    CURLOPT_POSTFIELDS     => json_encode($ordre, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 60,
]);
$svar = json_decode(curl_exec($ch), true);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($svar['success'] ?? false) {
    // Ordren er modtaget — gem svaret (fx jeres ordre-id → faktura-nr)
    echo "Faktura-kladde: {$svar['faktura_nr']}, total {$svar['total_ekskl_moms']} kr.\n";
    foreach ($svar['linjer'] as $l) {
        echo "  {$l['varenr']} × {$l['antal']} = {$l['subtotal']} kr. ({$l['pris_stk']}/stk)\n";
    }
} elseif ($http === 409) {
    // Samme ordrenr_ekstern er allerede sendt — DOBBELTORDRER undgås, ignorer roligt
    echo "Allerede modtaget som faktura {$svar['faktura_nr']}\n";
} else {
    echo "FEJL: " . ($svar['fejl'] ?? 'ukendt') . "\n";
}
```

## Felter

| Felt | Type | Krav | Beskrivelse |
|---|---|---|---|
| `ordrenr_ekstern` | string | valgfri (max 50) | Jeres ordrenummer. **Idempotens**: samme numrum afvises (HTTP 409) så et netværks-tabt kald aldrig giver dobbeltordrer. |
| `besked` | string | valgfri (max 2000) | Fritekst på fakturaen (Comment-linje). |
| `levering` | objekt | valgfri | Dropshipping: lever direkte til jeres slutkunde. Udelades → levering til forhandlerens egen adresse. Kræver `navn`, `adresse1`, `postnr`, `by`; valgfri `adresse2`, `land` (default DK), `kontakt`. |
| `linjer` | liste | **påkrævet** (1-100) | Ordrelinjerne, se nedenfor. |

**Linje:** `varenr` (Kaffeværkstedets varenummer, påkrævet), `antal` (1-10000, påkrævet),
`variant` (valgfri kode, fx `HB` hele bønner / `EM` malet espresso / `FM` malet filter / `SM` malet stempel — kun hvis varen har varianter),
`enhed` (valgfri kode, fx `KG` / `250G` — kun hvis varen har flere enheder).

> Kun varer som Kaffeværkstedet har tildelt kontoen kan bestilles — andre varenumre
> afvises med HTTP 400 og en tydelig fejlbesked.

## Svar

**Succes — HTTP 201:**

```json
{
    "success": true,
    "faktura_nr": "104358",
    "faktura_id": "…GUID…",
    "ordre_id": 12,
    "ordrenr_ekstern": "WS-2026-1001",
    "total_ekskl_moms": 516.0,
    "linjer": [
        {"varenr": "1104-60336363", "navn": "Colombia …", "antal": 2, "pris_stk": 129.0, "subtotal": 258.0}
    ]
}
```

Priserne beregnes af Business Central ud forhandlerens aftalepris — brug dem gerne
til jeres egen bogføring. Beløbene er ekskl. moms.

**Fejl — altid JSON med `success: false` og `fejl`:**

| HTTP | Betydning |
|---|---|
| 400 | Ugyldig ordre (manglende felter, ukendt/spærret/utildelt vare, ugyldig variant/enhed). Intet er oprettet — ret og prøv igen. |
| 401 | Manglende/ugyldig API-nøgle. |
| 405 | Andet end POST. |
| 409 | `ordrenr_ekstern` allerede brugt — svaret indeholder den eksisterende `faktura_nr`. |
| 429 | For mange fejlslagne forsøg (låst ~15 min). |
| 502 | Business Central kunne ikke kontaktes/komplettere ordren — **prøv igen**. Hvis `faktura_nr_mulig` er sat, blev en kladde evt. delvist oprettet; tag kontakt til Kaffeværkstedet i stedet for at sende ordren igen med samme `ordrenr_ekstern`. |

## Godt at vide

- Send **kun varer fra Kaffeværkstedet** i kaldet — jeres egne/andre leverandørers varer skal holdes udenfor.
- Send ét kald pr. ordre (ikke pr. linje).
- Status på ordren (pakket/afsendt, GLS-sporing) ses af forhandleren i portalen under *Tidligere bestillinger* — API-ordrer lander dér automatisk.

---

# Hent sortiment — `GET api_varer.php`

Samme API-nøgle, men med GET (ingen body). Returnerer forhandlerens **aktuelle
sortiment** — præcis de varer Kaffeværkstedet har tildelt kontoen — med de
valgbare enheder og varianter:

```json
{
    "success": true,
    "genereret": "2026-10-15T12:00:00+02:00",
    "antal_varer": 2,
    "varer": [
        {
            "varenr": "1103",
            "navn": "Brasilien",
            "gtin": "5701234567890",
            "basis_enhed": "KG",
            "enheder": [
                {"kode": "KG", "vejl_pris": 129.0},
                {"kode": "225G", "vejl_pris": 38.7},
                {"kode": "500G", "vejl_pris": 64.6}
            ],
            "varianter": [
                {"kode": "EM", "betegnelse": "Malet til Espresso"},
                {"kode": "FM", "betegnelse": "Malet til Filter"},
                {"kode": "HB", "betegnelse": "Hele Bønner"},
                {"kode": "SM", "betegnelse": "Malet til Stempel"}
            ]
        }
    ]
}
```

- `varenr` er **stabil og entydig** — brug den som nøglen i jeres produktkartotek.
- `gtin` (EAN) medtages kun hvor Kaffeværkstedet har udfyldt den i BC.
- `vejl_pris` er vejledende excl. moms — den endelige pris beregnes pr. ordre og
  returneres i svaret fra `api_ordre.php`.
- En vare uden `enheder` ud over basisenheden bestilles uden `enhed`-felt; uden
  `varianter` bestilles uden `variant`-felt.

## Anbefalet synkronisering (så systemerne altid taler samme sprog)

1. **Hent sortimentet jævnligt** (fx natligt eller hver 6. time) og gem det lokalt.
2. **Match på `varenr`**: nye numre i listen = nye produkter I kan sælge → opret
   dem i jeres shop (evt. med manuel godkendelse først). Numre der forsvinder =
   udgået hos Kaffeværkstedet → deaktivér produktet.
3. **Bestil altid med `varenr` + evt. `variant`/`enhed`-koder præcis som de står
   i sortimentet.** Ordre-API'et validerer altid mod det aktuelle sortiment:
   et forkert/udgået/utildelt nummer afvises med HTTP 400 og en tydelig fejl —
   der kan aldrig opstå en ordre på et nummer Kaffeværkstedet ikke forstår.
4. **Undgå dobbeltordrer**: send altid jeres ordrenummer i `ordrenr_ekstern`.
   Gentagne kald (fx ved netværksfejl) med samme nummer afvises med HTTP 409 og
   den eksisterende faktura — kast ikke en fejl til jeres kunde i det tilfælde.
5. Reager på **401** ved at stoppe ordreafsendelse og kontakte Kaffeværkstedet
   (nøglen er fornylig udskiftet eller fjernet).
