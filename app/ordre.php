<?php
/**
 * Checkout & Ordreoprettelse — Engros Bestillingsportal
 * 
 * Behandler og opretter ordren direkte i Business Central som en Salgsfaktura (kladde).
 * Udregner priser i realtid via BC og logger transaktionen lokalt.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bc_api.php';

// Kræv login
require_login();
if ($_SESSION['brugernavn'] === 'admin') {
    header("Location: admin.php");
    exit;
}

$fejl_besked = '';
$customer_id = $_SESSION['bc_kunde_id'] ?? '';
$customer_nr = $_SESSION['bc_kunde_nr'] ?? '';

// ─── Hent eller slå kunde GUID op ────────────────────────────────────────────
if (empty($customer_id)) {
    try {
        $c_data = bc_get_customer_by_number($customer_nr);
        if ($c_data) {
            $_SESSION['bc_kunde_id'] = $c_data['id'];
            $customer_id = $c_data['id'];
        } else {
            die("Kunde kunne ikke findes i BC.");
        }
    } catch (Exception $e) {
        die("Fejl ved opslag af kunde: " . $e->getMessage());
    }
}

// ─── TRIN: KVITTERING (VISNING) ──────────────────────────────────────────────
$step = $_GET['step'] ?? 'checkout';
if ($step === 'kvittering') {
    $ordre_id = intval($_GET['id'] ?? 0);
    
    // Hent ordren fra lokal log
    $stmt = $db->prepare("SELECT * FROM ordre_log WHERE id = :id AND bruger_id = :bruger_id");
    $stmt->execute([':id' => $ordre_id, ':bruger_id' => $_SESSION['bruger_id']]);
    $ordre = $stmt->fetch();
    
    if (!$ordre) {
        die("Bestillingen blev ikke fundet.");
    }
    
    $adresse_info = json_decode($ordre['lever_til_json'], true);
    $ordrelinjer  = json_decode($ordre['linjer_json'], true);
    
    // Vis kvittering
    ?>
    <!DOCTYPE html>
    <html lang="da">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ordrebekræftelse — Kaffeværkstedet</title>
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
        <header>
            <div class="nav-container">
                <div class="logo">
                    ☕ <span>Kaffeværkstedet</span>
                </div>
                <div class="nav-links">
                    <a href="katalog.php">Varekatalog</a>
                    <a href="adresser.php">Leveringsadresser</a>
                    <a href="historik.php">Ordrehistorik</a>
                    <form action="logout.php" method="POST" style="display: inline;">
                        <button type="submit" class="btn-logout">Log ud</button>
                    </form>
                </div>
            </div>
        </header>

        <div class="main-content">
            <div class="card animate-fade-in" style="max-width: 800px; margin: 0 auto; padding: 40px;">
                <div style="text-align: center; margin-bottom: 30px;">
                    <span style="font-size: 50px;">🎉</span>
                    <h1 style="color: var(--success); margin-top: 10px;">Tak for din bestilling!</h1>
                    <p style="color: var(--text-muted); margin-top: 5px;">
                        Faktura-kladde nr. <strong style="color: var(--primary); font-family: monospace; font-size: 16px;"><?php echo htmlspecialchars($ordre['bc_faktura_nr']); ?></strong> er oprettet i Business Central.
                    </p>
                </div>

                <div class="grid-2" style="margin-bottom: 30px; border-top: 1px solid var(--border-light); padding-top: 20px;">
                    <div>
                        <h4 style="color: var(--primary); margin-bottom: 10px;">Leveringsadresse</h4>
                        <div style="font-size: 14px; line-height: 1.6;">
                            <strong><?php echo htmlspecialchars($adresse_info['name'] ?? ''); ?></strong><br>
                            <?php echo htmlspecialchars($adresse_info['addressLine1'] ?? ''); ?><br>
                            <?php if (!empty($adresse_info['addressLine2'])): ?>
                                <?php echo htmlspecialchars($adresse_info['addressLine2']); ?><br>
                            <?php endif; ?>
                            <?php echo htmlspecialchars(($adresse_info['postCode'] ?? '') . ' ' . ($adresse_info['city'] ?? '')); ?><br>
                            <?php echo htmlspecialchars($adresse_info['country'] ?? 'DK'); ?>
                        </div>
                        <div style="margin-top: 10px; font-size: 13px; color: var(--text-muted);">
                            Type: <?php echo $ordre['lever_til_type'] === 'gemt' ? 'Fast aftaleadresse' : 'Drop shipping (engangsadresse)'; ?>
                        </div>
                    </div>
                    <div>
                        <h4 style="color: var(--primary); margin-bottom: 10px;">Bestillingsinfo</h4>
                        <table style="width: auto; font-size: 13px;">
                            <tr>
                                <td style="padding: 4px 10px 4px 0; color: var(--text-muted);">Dato:</td>
                                <td style="padding: 4px 0; font-weight: 500;"><?php echo date('d-m-Y H:i', strtotime($ordre['oprettet'])); ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 4px 10px 4px 0; color: var(--text-muted);">Kunde:</td>
                                <td style="padding: 4px 0; font-weight: 500;"><?php echo htmlspecialchars($_SESSION['firma_navn']); ?> (<?php echo htmlspecialchars($_SESSION['bc_kunde_nr']); ?>)</td>
                            </tr>
                            <tr>
                                <td style="padding: 4px 10px 4px 0; color: var(--text-muted);">Status:</td>
                                <td style="padding: 4px 0;"><span class="badge badge-success">Oprettet som kladde</span></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <h4 style="color: var(--primary); margin-bottom: 15px; border-top: 1px solid var(--border-light); padding-top: 20px;">Bestilte varer</h4>
                <div class="table-responsive" style="margin-bottom: 30px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Varenr.</th>
                                <th>Beskrivelse</th>
                                <th style="text-align: center;">Antal</th>
                                <th style="text-align: right;">Aftalepris</th>
                                <th style="text-align: right;">Total (ekskl. moms)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ordrelinjer as $linje): ?>
                                <tr>
                                    <td style="font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars($linje['number']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($linje['name']); ?></strong></td>
                                    <td style="text-align: center;"><?php echo $linje['qty']; ?></td>
                                    <td style="text-align: right;"><?php echo number_format($linje['unit_price'], 2, ',', '.'); ?> kr.</td>
                                    <td style="text-align: right; font-weight: 500;"><?php echo number_format($linje['subtotal'], 2, ',', '.'); ?> kr.</td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="border-top: 2px solid var(--border-primary);">
                                <td colspan="3" style="border: none;"></td>
                                <td style="text-align: right; font-weight: bold; border: none; padding-top: 20px;">Total ekskl. moms:</td>
                                <td style="text-align: right; font-weight: bold; color: var(--primary); font-size: 18px; border: none; padding-top: 20px;">
                                    <?php echo number_format($ordre['total_beloeb'], 2, ',', '.'); ?> kr.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="text-align: center; display: flex; gap: 15px; justify-content: center;">
                    <a href="katalog.php" class="btn">Opret ny bestilling</a>
                    <a href="historik.php" class="btn btn-secondary">Se ordrehistorik</a>
                </div>
            </div>
        </div>

        <footer>
            &copy; <?php echo date('Y'); ?> Kaffeværkstedet ApS. Alle rettigheder forbeholdes.
        </footer>
    </body>
    </html>
    <?php
    exit;
}

// ─── TJEK AT KURVEN IKKE ER TOM ──────────────────────────────────────────────
if (empty($_SESSION['cart'])) {
    header("Location: katalog.php");
    exit;
}

// ─── BEHANDL BESTILLING (POST) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token     = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $fejl_besked = 'Sessionen er udløbet. Prøv igen.';
    } else {
        // Forbered leveringsdata. Standard = virksomhedens egen adresse (BC bruger
        // kundens registrerede adresse automatisk, når der ikke sendes shipTo).
        $shipping_data    = [];
        $adresse_navn     = '';
        $adresse_log_data = [];
        $lever_til_type   = 'virksomhed';

        $brug_dropshipping = isset($_POST['brug_dropshipping']);

        if (!$brug_dropshipping) {
            // Leveres til virksomheden selv
            $adresse_navn     = $_SESSION['firma_navn'] ?? 'Virksomhedens adresse';
            $adresse_log_data = ['name' => $adresse_navn, 'note' => 'Virksomhedens egen adresse i Business Central'];
        } else {
            // Dropshipping: bestem adressen — enten en gemt BC-adresse (valgt i rullegardinet)
            // eller en ny manuelt indtastet (som evt. kan gemmes i BC).
            $valgt_kode   = trim($_POST['valgt_gemt_kode'] ?? '');
            $name = $addressLine1 = $addressLine2 = $city = $postCode = '';
            $contact = $phone = $email = '';
            $country = 'DK';

            if ($valgt_kode !== '') {
                // (A) Gemt BC ship-to adresse — find den og kopiér felterne til ordren
                $adresser = bc_get_customer_addresses($customer_nr);
                $fundet = null;
                foreach ($adresser as $adr) {
                    if (($adr['code'] ?? '') === $valgt_kode) { $fundet = $adr; break; }
                }
                if (!$fundet) {
                    $fejl_besked = 'Den valgte leveringsadresse blev ikke fundet i Business Central.';
                } else {
                    $name         = $fundet['displayName'] ?? '';
                    $addressLine1 = $fundet['addressLine1'] ?? '';
                    $addressLine2 = $fundet['addressLine2'] ?? '';
                    $city         = $fundet['city'] ?? '';
                    $postCode     = $fundet['postalCode'] ?? '';
                    $country      = $fundet['country'] ?? 'DK';
                    $contact      = $fundet['contact'] ?? '';
                    $lever_til_type = 'gemt';
                }
            } else {
                // (B) Ny adresse indtastet manuelt
                $name         = trim($_POST['dropship_name'] ?? '');
                $addressLine1 = trim($_POST['dropship_address'] ?? '');
                $addressLine2 = trim($_POST['dropship_address2'] ?? '');
                $city         = trim($_POST['dropship_city'] ?? '');
                $postCode     = trim($_POST['dropship_postcode'] ?? '');
                $country      = trim($_POST['dropship_country'] ?? 'DK') ?: 'DK';
                $contact      = trim($_POST['dropship_contact'] ?? '');
                $phone        = trim($_POST['dropship_phone'] ?? '');
                $email        = trim($_POST['dropship_email'] ?? '');
                $gem_adresse  = isset($_POST['gem_adresse']);

                if ($name === '' || $addressLine1 === '' || $city === '' || $postCode === '') {
                    $fejl_besked = 'Udfyld venligst modtager, adresse, postnr. og by.';
                } elseif ($gem_adresse) {
                    // Gem som fast ship-to adresse i BC. Adressekoden er valgfri — hvis den
                    // ikke angives, genererer vi en unik kode automatisk ud fra navnet.
                    $kode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['gem_kode'] ?? ''));
                    if ($kode === '') {
                        $basis = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
                        $basis = $basis !== '' ? substr($basis, 0, 8) : 'ADR';
                        // Sikr at koden er unik blandt kundens eksisterende adresser
                        $optagne = [];
                        foreach (bc_get_customer_addresses($customer_nr) as $a) {
                            $optagne[strtoupper($a['code'] ?? '')] = true;
                        }
                        $kode = $basis;
                        $n = 1;
                        while (isset($optagne[$kode])) {
                            $suffix = (string) $n;
                            $kode = substr($basis, 0, 10 - strlen($suffix)) . $suffix;
                            $n++;
                        }
                    }
                    $kode = substr($kode, 0, 10);

                    $create = bc_create_customer_address($customer_nr, [
                        'code' => $kode, 'name' => $name, 'addressLine1' => $addressLine1, 'addressLine2' => $addressLine2,
                        'city' => $city, 'postCode' => $postCode, 'country' => $country,
                        'contact' => $contact, 'phone' => $phone, 'email' => $email
                    ]);
                    if (!$create['success']) {
                        $fejl_besked = 'Kunne ikke gemme leveringsadressen i Business Central: ' . ($create['error'] ?? 'ukendt fejl');
                    } else {
                        $lever_til_type = 'gemt';
                    }
                } else {
                    $lever_til_type = 'engang';
                }
            }

            // Adressen lægges altid på ordren som shipTo*-felter (salesInvoice har ikke shipToCode).
            // Telefon/email findes ikke på fakturaens shipTo — de gemmes kun på BC-adressen.
            if (empty($fejl_besked)) {
                $shipping_data = [
                    'shipToName'         => $name,
                    'shipToContact'      => $contact,
                    'shipToAddressLine1' => $addressLine1,
                    'shipToAddressLine2' => $addressLine2,
                    'shipToCity'         => $city,
                    'shipToPostCode'     => $postCode,
                    'shipToCountry'      => $country
                ];
                $adresse_navn     = $name;
                $adresse_log_data = ['name' => $name, 'contact' => $contact, 'addressLine1' => $addressLine1, 'addressLine2' => $addressLine2, 'city' => $city, 'postCode' => $postCode, 'country' => $country, 'phone' => $phone, 'email' => $email];
            }
        }
        
        // Hvis der ikke er fejl, opret fakturaen i BC
        if (empty($fejl_besked)) {
            try {
                // 1. Opret draft salgsfaktura i BC
                $invoice_res = bc_create_draft_invoice($customer_id, $shipping_data);
                
                if (!$invoice_res['success']) {
                    throw new Exception("Kunne ikke oprette salgsfaktura: " . ($invoice_res['error'] ?? 'Ukendt fejl'));
                }
                
                $bc_invoice_id = $invoice_res['data']['id'];
                $bc_invoice_nr = $invoice_res['data']['number'] ?? 'Draft';
                
                // 2. Tilføj alle linjer til fakturaen
                // Hent varekatalog + varianter + enheder for at kende navne/koder (til den lokale log)
                $katalog       = bc_get_all_items_cached();
                $variant_by_id = [];
                foreach (bc_get_variants_cached() as $liste) {
                    foreach ($liste as $v) {
                        $variant_by_id[$v['id']] = $v;
                    }
                }
                $unit_by_id = [];
                foreach (bc_get_units_cached() as $kode => $u) {
                    $unit_by_id[$u['id']] = ['code' => $kode, 'label' => $u['label']];
                }

                $lokale_linjer = [];
                $total_ekskl_moms = 0;
                $seq = 10000;

                foreach ($_SESSION['cart'] as $entry) {
                    $item_guid  = $entry['item_id'];
                    $variant_id = $entry['variant_id'] ?? '';
                    $unit_id    = $entry['unit_id'] ?? '';
                    $qty        = (int) $entry['qty'];

                    $line_res = bc_add_invoice_line($bc_invoice_id, $item_guid, $qty, $seq, $variant_id, $unit_id);

                    if (!$line_res['success']) {
                        throw new Exception("Kunne ikke tilføje varen til fakturalinjerne: " . ($line_res['error'] ?? ''));
                    }

                    // Læs den beregnede pris tilbage fra BC's svar.
                    // Linjebeløbet (ekskl. moms, efter rabat) ligger i amountExcludingTax/netAmount.
                    $calculated_unit_price = floatval($line_res['data']['unitPrice'] ?? 0);
                    $calculated_subtotal   = floatval(
                        $line_res['data']['amountExcludingTax']
                        ?? $line_res['data']['netAmount']
                        ?? ($calculated_unit_price * $qty)
                    );
                    $total_ekskl_moms     += $calculated_subtotal;

                    // Find varenr og navn
                    $vare_nr = '';
                    $vare_navn = '';
                    foreach ($katalog as $k_item) {
                        if (($k_item['id'] ?? '') === $item_guid) {
                            $vare_nr   = $k_item['number'];
                            $vare_navn = $k_item['displayName'] ?? $k_item['name'] ?? '';
                            break;
                        }
                    }
                    $variant_label = $variant_id !== '' && isset($variant_by_id[$variant_id]) ? $variant_by_id[$variant_id]['label'] : '';
                    $unit_label    = $unit_id !== '' && isset($unit_by_id[$unit_id]) ? $unit_by_id[$unit_id]['code'] : '';

                    $lokale_linjer[] = [
                        'id'            => $item_guid,
                        'variant_id'    => $variant_id,
                        'variant_label' => $variant_label,
                        'unit_label'    => $unit_label,
                        'number'        => $vare_nr,
                        'name'          => $vare_navn,
                        'qty'           => $qty,
                        'unit_price'    => $calculated_unit_price,
                        'subtotal'      => $calculated_subtotal
                    ];

                    $seq += 10000;
                }
                
                // 3. Gem ordren i vores lokale SQLite log
                $insert = $db->prepare("
                    INSERT INTO ordre_log (bruger_id, bc_faktura_nr, bc_faktura_id, lever_til_type, lever_til_navn, lever_til_json, linjer_json, total_beloeb, status)
                    VALUES (:bruger_id, :faktura_nr, :faktura_id, :type, :navn, :adresse_json, :linjer_json, :total, 'oprettet')
                ");
                
                $insert->execute([
                    ':bruger_id'     => $_SESSION['bruger_id'],
                    ':faktura_nr'    => $bc_invoice_nr,
                    ':faktura_id'    => $bc_invoice_id,
                    ':type'          => $lever_til_type,
                    ':navn'          => $adresse_navn,
                    ':adresse_json'  => json_encode($adresse_log_data),
                    ':linjer_json'   => json_encode($lokale_linjer),
                    ':total'         => $total_ekskl_moms
                ]);
                
                $nyt_ordre_id = $db->lastInsertId();
                
                // 4. Tøm kurven
                $_SESSION['cart'] = [];
                
                // 5. Omdiriger til kvittering
                header("Location: ordre.php?step=kvittering&id=" . $nyt_ordre_id);
                exit;
                
            } catch (Exception $e) {
                $fejl_besked = "Der opstod en fejl under oprettelse af din ordre i Business Central: " . $e->getMessage();
            }
        }
    }
}

// ─── FORBERED CHECKOUT SIDE ──────────────────────────────────────────────────
// Hent kundens gemte ship-to adresser fra Business Central (via kvwoo) til rullegardin/prefill
$adresser = [];
try {
    $adresser = bc_get_customer_addresses($customer_nr);
} catch (Exception $e) {
    $fejl_besked = "Kunne ikke hente dine leveringsadresser fra Business Central.";
}

// Hent kundens egen (firma)adresse til visning af standard-leveringsvalget
$firma_adresse = '';
try {
    $kunde = bc_get_customer_by_number($customer_nr);
    if ($kunde) {
        $dele = array_filter([
            $kunde['addressLine1'] ?? '',
            trim(($kunde['postalCode'] ?? '') . ' ' . ($kunde['city'] ?? '')),
            $kunde['country'] ?? ''
        ]);
        $firma_adresse = implode(', ', $dele);
    }
} catch (Exception $e) {
    // Ikke kritisk — vi viser blot firmanavnet uden adresse
}

// Beregn foreløbig kurv subtotal til visning
$midlertidig_total = 0;
$katalog = bc_get_all_items_cached();
$variant_by_id = [];
foreach (bc_get_variants_cached() as $liste) {
    foreach ($liste as $v) {
        $variant_by_id[$v['id']] = $v;
    }
}
$unit_by_id = [];
foreach (bc_get_units_cached() as $kode => $u) {
    $unit_by_id[$u['id']] = ['code' => $kode, 'label' => $u['label']];
}

$kurv_varer = [];
foreach ($_SESSION['cart'] as $entry) {
    $item_guid  = $entry['item_id'];
    $variant_id = $entry['variant_id'] ?? '';
    $unit_id    = $entry['unit_id'] ?? '';
    $qty        = (int) $entry['qty'];
    foreach ($katalog as $item) {
        if (($item['id'] ?? '') === $item_guid) {
            $price = floatval($item['unitPrice'] ?? 0);
            $subtotal = $price * $qty;
            $midlertidig_total += $subtotal;
            $variant_label = $variant_id !== '' && isset($variant_by_id[$variant_id]) ? $variant_by_id[$variant_id]['label'] : '';
            $unit_label    = $unit_id !== '' && isset($unit_by_id[$unit_id]) ? $unit_by_id[$unit_id]['code'] : '';
            $kurv_varer[] = [
                'name'          => $item['displayName'] ?? $item['name'] ?? '',
                'variant_label' => $variant_label,
                'unit_label'    => $unit_label,
                'qty'           => $qty,
                'price'         => $price,
                'subtotal'      => $subtotal
            ];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gennemfør bestilling — Kaffeværkstedet</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-light);
            padding-bottom: 10px;
        }
        .tab-btn {
            background: transparent;
            border: 1px solid var(--border-light);
            color: var(--text-muted);
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 500;
            font-family: inherit;
            transition: var(--transition);
        }
        .tab-btn.active {
            background: var(--primary);
            color: var(--text-dark);
            border-color: var(--primary);
        }
        .address-panel {
            display: none;
        }
        .address-panel.active {
            display: block;
        }
    </style>
</head>
<body>

    <header>
        <div class="nav-container">
            <div class="logo">
                ☕ <span>Kaffeværkstedet</span>
            </div>
            <div class="nav-links">
                <a href="katalog.php">Varekatalog</a>
                <a href="adresser.php">Leveringsadresser</a>
                <a href="historik.php">Ordrehistorik</a>
                <form action="logout.php" method="POST" style="display: inline;">
                    <button type="submit" class="btn-logout">Log ud</button>
                </form>
            </div>
        </div>
    </header>

    <div class="main-content">
        <h1 class="animate-fade-in" style="margin-bottom: 30px;">Levering & Bestilling</h1>

        <?php if (!empty($fejl_besked)): ?>
            <div class="alert alert-danger animate-fade-in">
                <span>⚠️</span>
                <div><?php echo htmlspecialchars($fejl_besked); ?></div>
            </div>
        <?php endif; ?>

        <div class="grid-2 animate-fade-in" style="grid-template-columns: 3fr 2fr; gap: 40px; align-items: start;">
            
            <!-- Venstre: Vælg adresse -->
            <div>
                <div class="card">
                    <h3 class="card-title" style="margin-bottom: 20px;">Levering</h3>

                    <form action="ordre.php" method="POST" id="checkout-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">

                        <!-- Standard: leveres til virksomhedens egen adresse -->
                        <div style="padding: 14px 16px; border: 1px solid var(--border-light); border-radius: var(--radius-sm); margin-bottom: 18px;">
                            <div style="font-weight: 600;">📦 Leveres til virksomheden</div>
                            <div style="color: var(--text-muted); font-size: 13px; margin-top: 4px;">
                                <?php echo htmlspecialchars($_SESSION['firma_navn']); ?><?php echo $firma_adresse !== '' ? ' — ' . htmlspecialchars($firma_adresse) : ''; ?>
                            </div>
                        </div>

                        <!-- Vælg i stedet en anden leveringsadresse -->
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 6px;">
                            <input type="checkbox" name="brug_dropshipping" id="brug_dropshipping" onchange="toggleDropship()">
                            <span>Send til en anden adresse (dropshipping)</span>
                        </label>

                        <!-- Panel: dropshipping -->
                        <div id="dropship-panel" style="display: none; border-top: 1px solid var(--border-light); margin-top: 14px; padding-top: 18px;">
                            <?php if (!empty($adresser)): ?>
                                <div class="form-group">
                                    <label for="valgt_gemt_kode" class="form-label">Vælg en gemt leveringsadresse</label>
                                    <select id="valgt_gemt_kode" name="valgt_gemt_kode" class="form-control" style="background-color: var(--bg-dark);" onchange="vaelgGemt()">
                                        <option value="">— Ny adresse (udfyld felterne nedenfor) —</option>
                                        <?php foreach ($adresser as $adr): ?>
                                            <option value="<?php echo htmlspecialchars($adr['code'] ?? ''); ?>"
                                                    data-name="<?php echo htmlspecialchars($adr['displayName'] ?? ''); ?>"
                                                    data-address="<?php echo htmlspecialchars($adr['addressLine1'] ?? ''); ?>"
                                                    data-address2="<?php echo htmlspecialchars($adr['addressLine2'] ?? ''); ?>"
                                                    data-postcode="<?php echo htmlspecialchars($adr['postalCode'] ?? ''); ?>"
                                                    data-city="<?php echo htmlspecialchars($adr['city'] ?? ''); ?>"
                                                    data-country="<?php echo htmlspecialchars($adr['country'] ?? 'DK'); ?>"
                                                    data-contact="<?php echo htmlspecialchars($adr['contact'] ?? ''); ?>"
                                                    data-phone="<?php echo htmlspecialchars($adr['phoneNumber'] ?? ''); ?>"
                                                    data-email="<?php echo htmlspecialchars($adr['email'] ?? ''); ?>">
                                                <?php echo htmlspecialchars(($adr['displayName'] ?? $adr['code']) . ' — ' . ($adr['addressLine1'] ?? '') . ', ' . ($adr['city'] ?? '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small style="color: var(--text-muted); display: block; margin-top: 6px; font-size: 12px;">Vælg en gemt adresse for at udfylde felterne — eller indtast en ny.</small>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="valgt_gemt_kode" id="valgt_gemt_kode" value="">
                                <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 15px;">Ingen gemte leveringsadresser endnu — indtast en ny nedenfor.</p>
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="dropship_name" class="form-label">Modtager / virksomhed</label>
                                <input type="text" id="dropship_name" name="dropship_name" class="form-control" placeholder="fx 'Kaffebaren Nørrebro'">
                            </div>

                            <div class="form-group">
                                <label for="dropship_address" class="form-label">Leveringsadresse (gade og husnummer)</label>
                                <input type="text" id="dropship_address" name="dropship_address" class="form-control" placeholder="fx 'Nørrebrogade 42, 1. th.'">
                            </div>

                            <div class="form-group">
                                <label for="dropship_address2" class="form-label">Adresse 2 (valgfri)</label>
                                <input type="text" id="dropship_address2" name="dropship_address2" class="form-control" placeholder="fx 'Bygning B, 2. sal'">
                            </div>

                            <div class="form-group">
                                <div style="display: flex; gap: 15px;">
                                    <div style="flex: 1;">
                                        <label for="dropship_postcode" class="form-label">Postnr.</label>
                                        <input type="text" id="dropship_postcode" name="dropship_postcode" class="form-control" placeholder="fx '2200'">
                                    </div>
                                    <div style="flex: 2;">
                                        <label for="dropship_city" class="form-label">By</label>
                                        <input type="text" id="dropship_city" name="dropship_city" class="form-control" placeholder="fx 'København N'">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="dropship_country" class="form-label">Landekode</label>
                                <input type="text" id="dropship_country" name="dropship_country" class="form-control" value="DK" placeholder="fx 'DK'">
                            </div>

                            <div class="form-group">
                                <label for="dropship_contact" class="form-label">Kontaktperson (valgfri)</label>
                                <input type="text" id="dropship_contact" name="dropship_contact" class="form-control" placeholder="fx 'Jens Jensen'">
                            </div>

                            <div class="form-group">
                                <div style="display: flex; gap: 15px;">
                                    <div style="flex: 1;">
                                        <label for="dropship_phone" class="form-label">Telefon (valgfri)</label>
                                        <input type="text" id="dropship_phone" name="dropship_phone" class="form-control" placeholder="fx '12 34 56 78'">
                                    </div>
                                    <div style="flex: 1;">
                                        <label for="dropship_email" class="form-label">Email (valgfri)</label>
                                        <input type="email" id="dropship_email" name="dropship_email" class="form-control" placeholder="fx 'levering@firma.dk'">
                                    </div>
                                </div>
                            </div>
                            <small style="color: var(--text-muted); display: block; margin-bottom: 4px; font-size: 12px;">
                                Telefon og email gemmes kun, hvis adressen gemmes i BC (de findes ikke på selve ordren).
                            </small>

                            <!-- Gem adressen fast i BC? -->
                            <label id="gem-adresse-row" style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin: 10px 0 6px;">
                                <input type="checkbox" name="gem_adresse" id="gem_adresse" onchange="toggleGem()">
                                <span>Gem denne leveringsadresse i Business Central</span>
                            </label>
                            <div class="form-group" id="gem-kode-row" style="display: none; margin-bottom: 10px;">
                                <label for="gem_kode" class="form-label">Adressekode (valgfri — genereres automatisk hvis tom)</label>
                                <input type="text" id="gem_kode" name="gem_kode" class="form-control" maxlength="10" placeholder="fx 'BUTIK2'">
                            </div>
                            <small style="color: var(--text-muted); display: block; margin-bottom: 25px; font-size: 12px;">
                                Med flueben gemmes adressen som en fast leveringsadresse i Business Central (kan genbruges senere). Uden flueben bruges den kun på denne ordre.
                            </small>
                        </div>

                        <div style="border-top: 1px solid var(--border-light); padding-top: 20px; display: flex; justify-content: space-between; align-items: center;">
                            <a href="katalog.php" style="color: var(--text-muted); font-size: 14px;">← Gå tilbage til varekataloget</a>
                            <button type="submit" class="btn" style="padding: 12px 30px;">
                                Send Bestilling 🚀
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Højre: Kurv opsummering -->
            <div>
                <div class="card" style="padding: 24px;">
                    <h3 class="card-title" style="margin-bottom: 20px;">Ordreopsummering</h3>
                    
                    <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 20px;">
                        <?php foreach ($kurv_varer as $item): ?>
                            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-light); padding-bottom: 8px;">
                                <div style="font-size: 14px;">
                                    <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                    <?php $detalje = trim(($item['variant_label'] ?? '') . ' ' . (!empty($item['unit_label']) ? '· ' . $item['unit_label'] : '')); ?>
                                    <?php if ($detalje !== ''): ?>
                                        <span style="color: var(--primary); font-size: 12px;">— <?php echo htmlspecialchars($detalje); ?></span>
                                    <?php endif; ?>
                                    <br>
                                    <span style="color: var(--text-muted); font-size: 12px;"><?php echo $item['qty']; ?> stk. &bull; <?php echo number_format($item['price'], 2, ',', '.'); ?> kr./stk.</span>
                                </div>
                                <span style="font-size: 14px; font-weight: 500;"><?php echo number_format($item['subtotal'], 2, ',', '.'); ?> kr.</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div style="background-color: rgba(255,255,255,0.02); padding: 15px; border-radius: var(--radius-sm); border: 1px solid var(--border-primary);">
                        <div style="display: flex; justify-content: space-between; font-size: 14px; color: var(--text-muted); margin-bottom: 8px;">
                            <span>Subtotal (vejl. listepriser)</span>
                            <span><?php echo number_format($midlertidig_total, 2, ',', '.'); ?> kr.</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 15px; font-weight: bold; color: var(--primary);">
                            <span>Din endelige aftalepris:</span>
                            <span>Beregnes af BC</span>
                        </div>
                        <small style="color: var(--text-muted); display: block; margin-top: 8px; font-size: 11px; line-height: 1.4;">
                            * Når du sender ordren, kontakter vi Business Central. Systemet udregner automatisk dine specifikke rabatter og priser baseret på din gældende forhandlerkontrakt. Du ser de endelige priser på kvitteringen.
                        </small>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        var FELTER = ['dropship_name', 'dropship_address', 'dropship_address2', 'dropship_postcode',
                      'dropship_city', 'dropship_country', 'dropship_contact', 'dropship_phone', 'dropship_email'];

        // Vis/skjul hele dropshipping-panelet
        function toggleDropship() {
            var on = document.getElementById('brug_dropshipping').checked;
            document.getElementById('dropship-panel').style.display = on ? 'block' : 'none';
        }

        function setReadonly(ro) {
            FELTER.forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.readOnly = ro;
            });
        }

        // Når en gemt adresse vælges: udfyld + lås felterne og skjul "gem"-flueben.
        // Vælges "Ny adresse": ryd + lås op og vis "gem"-flueben.
        function vaelgGemt() {
            var sel = document.getElementById('valgt_gemt_kode');
            if (!sel || sel.tagName !== 'SELECT') return;
            var gemRow = document.getElementById('gem-adresse-row');
            if (sel.value !== '') {
                var opt = sel.options[sel.selectedIndex];
                document.getElementById('dropship_name').value     = opt.dataset.name || '';
                document.getElementById('dropship_address').value  = opt.dataset.address || '';
                document.getElementById('dropship_address2').value = opt.dataset.address2 || '';
                document.getElementById('dropship_postcode').value = opt.dataset.postcode || '';
                document.getElementById('dropship_city').value     = opt.dataset.city || '';
                document.getElementById('dropship_country').value  = opt.dataset.country || 'DK';
                document.getElementById('dropship_contact').value  = opt.dataset.contact || '';
                document.getElementById('dropship_phone').value    = opt.dataset.phone || '';
                document.getElementById('dropship_email').value    = opt.dataset.email || '';
                setReadonly(true);
                document.getElementById('gem_adresse').checked = false;
                toggleGem();
                if (gemRow) gemRow.style.display = 'none';
            } else {
                ['dropship_name', 'dropship_address', 'dropship_address2', 'dropship_postcode',
                 'dropship_city', 'dropship_contact', 'dropship_phone', 'dropship_email'].forEach(function (id) {
                    document.getElementById(id).value = '';
                });
                document.getElementById('dropship_country').value = 'DK';
                setReadonly(false);
                if (gemRow) gemRow.style.display = 'flex';
            }
        }

        // Vis kode-feltet kun når adressen skal gemmes i BC
        function toggleGem() {
            var on = document.getElementById('gem_adresse').checked;
            document.getElementById('gem-kode-row').style.display = on ? 'block' : 'none';
        }
    </script>

    <footer>
        &copy; <?php echo date('Y'); ?> Kaffeværkstedet ApS. Alle rettigheder forbeholdes.
    </footer>

</body>
</html>
