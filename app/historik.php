<?php
/**
 * Ordrehistorik — Engros Bestillingsportal
 * 
 * Viser en oversigt over tidligere ordrer foretaget af den loggede kunde.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bc_api.php';
require_once __DIR__ . '/gls_api.php';

// Kræv login
require_login();
if ($_SESSION['brugernavn'] === 'admin') {
    header("Location: admin.php");
    exit;
}

$bruger_id = $_SESSION['bruger_id'];

// Hent ordrer fra SQLite
$stmt = $db->prepare("SELECT * FROM ordre_log WHERE bruger_id = :bruger_id ORDER BY oprettet DESC");
$stmt->execute([':bruger_id' => $bruger_id]);
$ordrer = $stmt->fetchAll();

// Hent LIVE status + GLS-tracking for kundens fakturaer fra Business Central
// (web servicen Claus_salgsfaktura). Ét kald, indekseret på fakturanummer.
// Hvis BC ikke svarer, er mappen tom, og vi falder pænt tilbage på lokal status.
$bc_status_map = [];
try {
    $bc_status_map = bc_get_customer_invoice_status($_SESSION['bc_kunde_nr'] ?? '');
} catch (Exception $e) {
    error_log("Historik: kunne ikke hente live ordrestatus fra BC: " . $e->getMessage());
}

/**
 * Oversætter en ordres BC-status + GLS-felter til visningsdata til historikken.
 * Falder tilbage på en neutral "Sendt til BC"-badge, hvis BC ikke kender fakturaen.
 */
function hist_status_info($bc_status_map, $faktura_nr) {
    $rec = $bc_status_map[$faktura_nr] ?? null;

    // Ordrestatus → dansk label + badge-farve
    $status_label = 'Sendt til BC';
    $status_badge = 'badge-info';
    if ($rec) {
        switch ($rec['status']) {
            case 'Open':
                $status_label = 'Igangværende'; $status_badge = 'badge-warning'; break;
            case 'Released':
                $status_label = 'Frigivet';     $status_badge = 'badge-success'; break;
            default:
                if ($rec['status'] !== '') { $status_label = $rec['status']; $status_badge = 'badge-info'; }
        }
    }

    // GLS-forsendelse
    $gls_numre = $rec['gls_numre'] ?? [];
    $gls_sent  = $rec && (strcasecmp($rec['gls_shipment_status'] ?? '', 'Sent') === 0 || !empty($gls_numre));

    // Live leveringsstatus pr. pakkenummer (kun hvis GLS API er konfigureret).
    // gls_get_parcel_status() returnerer null når integrationen er slået fra.
    $gls_live = [];
    if ($gls_sent && !empty($gls_numre)) {
        foreach ($gls_numre as $nr) {
            $live = gls_get_parcel_status($nr);
            if ($live !== null) $gls_live[$nr] = $live;
        }
    }

    return [
        'kendt'        => (bool) $rec,
        'status_label' => $status_label,
        'status_badge' => $status_badge,
        'gls_numre'    => $gls_numre,
        'gls_sent'     => $gls_sent,
        'gls_live'     => $gls_live,
    ];
}

/** Bygger et offentligt GLS-sporingslink for et pakkenummer. */
function gls_track_link($nr) {
    return str_replace('{NR}', rawurlencode($nr), GLS_TRACK_URL);
}

// Håndter detalje visning
$valgt_ordre_id = intval($_GET['detalje'] ?? 0);
$valgt_ordre = null;

if ($valgt_ordre_id > 0) {
    $detail_stmt = $db->prepare("SELECT * FROM ordre_log WHERE id = :id AND bruger_id = :bruger_id LIMIT 1");
    $detail_stmt->execute([':id' => $valgt_ordre_id, ':bruger_id' => $bruger_id]);
    $valgt_ordre = $detail_stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tidligere bestillinger — Kaffeværkstedet</title>
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
                <a href="historik.php" class="active">Tidligere bestillinger</a>
                <form action="logout.php" method="POST" style="display: inline;">
                    <button type="submit" class="btn-logout">Log ud</button>
                </form>
            </div>
        </div>
    </header>

    <div class="main-content">
        <div style="margin-bottom: 30px;">
            <h1 class="animate-fade-in">Tidligere bestillinger</h1>
            <p style="color: var(--text-muted); font-size: 14px;">Oversigt over dine bestillinger for <?php echo htmlspecialchars($_SESSION['firma_navn']); ?> — med live status og GLS-pakkesporing fra Business Central.</p>
        </div>

        <div class="grid-2 animate-fade-in" style="grid-template-columns: <?php echo $valgt_ordre ? '3.5fr 2.5fr' : '1fr'; ?>; gap: 40px; align-items: start; transition: all 0.3s ease;">
            
            <!-- Liste over ordrer -->
            <div>
                <div class="card">
                    <h3 class="card-title">Tidligere bestillinger</h3>
                    
                    <?php if (empty($ordrer)): ?>
                        <div class="empty-state">
                            <p>Du har ikke foretaget nogen bestillinger endnu.</p>
                            <a href="katalog.php" class="btn" style="margin-top: 15px;">Opret din første ordre</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Dato</th>
                                        <th>Faktura Nr. (BC)</th>
                                        <th>Modtager</th>
                                        <th>Total (ekskl. moms)</th>
                                        <th>Status</th>
                                        <th>Forsendelse (GLS)</th>
                                        <th style="text-align: right;">Handling</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ordrer as $o): ?>
                                        <?php
                                            $active_class = ($valgt_ordre_id === intval($o['id'])) ? 'style="background-color: rgba(197, 155, 108, 0.05);"' : '';
                                            $si = hist_status_info($bc_status_map, $o['bc_faktura_nr']);
                                        ?>
                                        <tr <?php echo $active_class; ?>>
                                            <td>
                                                <?php echo date('d-m-Y H:i', strtotime($o['oprettet'])); ?>
                                            </td>
                                            <td style="font-family: monospace; font-weight: 500; color: var(--primary);">
                                                <?php echo htmlspecialchars($o['bc_faktura_nr']); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($o['lever_til_navn']); ?>
                                                <small style="display: block; color: var(--text-muted); font-size: 11px;">
                                                    <?php echo $o['lever_til_type'] === 'gemt' ? 'Aftaleadresse' : 'Drop shipping'; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php echo number_format(floatval($o['total_beloeb']), 2, ',', '.'); ?> kr.
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $si['status_badge']; ?>"><?php echo htmlspecialchars($si['status_label']); ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($si['gls_numre'])): ?>
                                                    <?php
                                                        // Hvis live-status findes, vis den seneste/mest sigende; ellers blot "Afsendt".
                                                        $live_label = '';
                                                        foreach ($si['gls_live'] as $lv) {
                                                            if (!empty($lv['ok']) && $lv['status_label'] !== '') { $live_label = $lv['status_label']; }
                                                        }
                                                    ?>
                                                    <span class="badge badge-success"><?php echo $live_label !== '' ? htmlspecialchars($live_label) : 'Afsendt'; ?></span>
                                                    <?php foreach ($si['gls_numre'] as $nr): ?>
                                                        <?php $lv = $si['gls_live'][$nr] ?? null; ?>
                                                        <a href="<?php echo htmlspecialchars(gls_track_link($nr)); ?>" target="_blank" rel="noopener"
                                                           style="display: block; font-family: monospace; font-size: 12px; margin-top: 3px;" title="Spor pakken hos GLS">
                                                            📦 <?php echo htmlspecialchars($nr); ?>
                                                        </a>
                                                        <?php if ($lv && !empty($lv['ok']) && $lv['status_label'] !== ''): ?>
                                                            <small style="display: block; color: var(--text-muted); font-size: 11px; margin-bottom: 4px;"><?php echo htmlspecialchars($lv['status_label']); ?></small>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                <?php elseif ($si['gls_sent']): ?>
                                                    <span class="badge badge-success">Afsendt</span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted); font-size: 13px;">Ikke afsendt endnu</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <a href="historik.php?detalje=<?php echo $o['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                                                    Vis detaljer
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Detaljer (hvis valgt) -->
            <?php if ($valgt_ordre): ?>
                <?php 
                    $adresse = json_decode($valgt_ordre['lever_til_json'], true);
                    $linjer  = json_decode($valgt_ordre['linjer_json'], true);
                ?>
                <div class="card animate-fade-in" style="border-color: var(--primary); position: sticky; top: 100px;">
                    <div class="card-title">
                        <span>Detaljer for #<?php echo htmlspecialchars($valgt_ordre['bc_faktura_nr']); ?></span>
                        <a href="historik.php" style="font-size: 14px; color: var(--text-muted);">Luk ❌</a>
                    </div>
                    
                    <div style="font-size: 14px; margin-bottom: 20px; line-height: 1.6;">
                        <h4 style="color: var(--primary); margin-bottom: 6px;">Leveres til:</h4>
                        <strong><?php echo htmlspecialchars($adresse['name'] ?? ''); ?></strong><br>
                        <?php echo htmlspecialchars($adresse['addressLine1'] ?? ''); ?><br>
                        <?php if (!empty($adresse['addressLine2'])): ?>
                            <?php echo htmlspecialchars($adresse['addressLine2']); ?><br>
                        <?php endif; ?>
                        <?php echo htmlspecialchars(($adresse['postCode'] ?? '') . ' ' . ($adresse['city'] ?? '')); ?><br>
                        <?php echo htmlspecialchars($adresse['country'] ?? 'DK'); ?>
                    </div>
                    
                    <div style="margin-bottom: 20px; font-size: 13px; color: var(--text-muted);">
                        Bestilt: <?php echo date('d-m-Y H:i', strtotime($valgt_ordre['oprettet'])); ?>
                    </div>

                    <?php $dsi = hist_status_info($bc_status_map, $valgt_ordre['bc_faktura_nr']); ?>
                    <div style="border-top: 1px solid var(--border-light); padding-top: 16px; margin-bottom: 20px;">
                        <h4 style="color: var(--primary); margin-bottom: 10px; font-size: 15px;">Status & forsendelse</h4>
                        <div style="display: flex; align-items: center; gap: 8px; font-size: 14px; margin-bottom: 12px;">
                            <span>Ordrestatus:</span>
                            <span class="badge <?php echo $dsi['status_badge']; ?>"><?php echo htmlspecialchars($dsi['status_label']); ?></span>
                        </div>

                        <?php if (!empty($dsi['gls_numre'])): ?>
                            <div style="font-size: 14px;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                    <span>GLS-forsendelse:</span>
                                    <span class="badge badge-success">Afsendt</span>
                                </div>
                                <div style="color: var(--text-muted); font-size: 12px; margin-bottom: 6px;">Klik på et pakkenummer for at spore pakken hos GLS:</div>
                                <?php foreach ($dsi['gls_numre'] as $nr): ?>
                                    <?php $lv = $dsi['gls_live'][$nr] ?? null; ?>
                                    <div style="margin-bottom: 8px;">
                                        <a href="<?php echo htmlspecialchars(gls_track_link($nr)); ?>" target="_blank" rel="noopener"
                                           class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; font-size: 13px; font-family: monospace;">
                                            📦 <?php echo htmlspecialchars($nr); ?> →
                                        </a>
                                        <?php if ($lv && !empty($lv['ok']) && $lv['status_label'] !== ''): ?>
                                            <div style="font-size: 13px; margin-top: 4px;">
                                                Status hos GLS: <strong style="color: var(--primary);"><?php echo htmlspecialchars($lv['status_label']); ?></strong>
                                                <?php if (!empty($lv['last_event_time'])): ?>
                                                    <span style="color: var(--text-muted);"> — <?php echo htmlspecialchars($lv['last_event_time']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($lv['last_event'])): ?>
                                                    <div style="color: var(--text-muted); font-size: 12px;"><?php echo htmlspecialchars($lv['last_event']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($dsi['gls_sent']): ?>
                            <div style="font-size: 14px; display: flex; align-items: center; gap: 8px;">
                                <span>GLS-forsendelse:</span>
                                <span class="badge badge-success">Afsendt</span>
                                <small style="color: var(--text-muted);">(pakkenummer endnu ikke registreret)</small>
                            </div>
                        <?php else: ?>
                            <div style="font-size: 14px; color: var(--text-muted);">
                                📦 Pakken er <strong>ikke afsendt endnu</strong>. Tracking-nummer vises her, så snart ordren er pakket og sendt med GLS.
                            </div>
                        <?php endif; ?>

                        <?php if (!$dsi['kendt']): ?>
                            <div style="color: var(--text-muted); font-size: 12px; margin-top: 10px;">
                                Live status kunne ikke hentes fra Business Central lige nu — viser senest kendte oplysninger.
                            </div>
                        <?php endif; ?>
                    </div>

                    <h4 style="color: var(--primary); margin-bottom: 10px; font-size: 15px;">Varer:</h4>
                    <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; border-top: 1px solid var(--border-light); padding-top: 10px;">
                        <?php foreach ($linjer as $l): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 14px;">
                                <div style="flex: 1; padding-right: 10px;">
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($l['name']); ?></div>
                                    <div style="font-size: 11px; color: var(--text-muted);">Varenr: <?php echo htmlspecialchars($l['number']); ?></div>
                                </div>
                                <div style="text-align: right;">
                                    <?php if (!empty($l['fragt'])): ?>
                                        <div style="color: var(--text-muted); font-style: italic;">Beregnes ved forsendelse</div>
                                    <?php else: ?>
                                        <div><?php echo $l['qty']; ?> stk. &bull; <?php echo number_format($l['unit_price'], 2, ',', '.'); ?> kr.</div>
                                        <div style="font-weight: 600; color: var(--primary);"><?php echo number_format($l['subtotal'], 2, ',', '.'); ?> kr.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div style="border-top: 2px solid var(--border-primary); padding-top: 15px; display: flex; justify-content: space-between; font-weight: bold; font-size: 16px;">
                        <span>Total (ekskl. moms):</span>
                        <span style="color: var(--primary);"><?php echo number_format(floatval($valgt_ordre['total_beloeb']), 2, ',', '.'); ?> kr.</span>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <footer>
        &copy; <?php echo date('Y'); ?> Kaffeværkstedet ApS. Alle rettigheder forbeholdes.
    </footer>

</body>
</html>
