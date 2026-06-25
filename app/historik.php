<?php
/**
 * Ordrehistorik — Engros Bestillingsportal
 * 
 * Viser en oversigt over tidligere ordrer foretaget af den loggede kunde.
 */

require_once __DIR__ . '/auth.php';

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
    <title>Ordrehistorik — Kaffeværkstedet</title>
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
                <a href="historik.php" class="active">Ordrehistorik</a>
                <form action="logout.php" method="POST" style="display: inline;">
                    <button type="submit" class="btn-logout">Log ud</button>
                </form>
            </div>
        </div>
    </header>

    <div class="main-content">
        <div style="margin-bottom: 30px;">
            <h1 class="animate-fade-in">Ordrehistorik</h1>
            <p style="color: var(--text-muted); font-size: 14px;">Oversigt over dine afsendte bestillinger for <?php echo htmlspecialchars($_SESSION['firma_navn']); ?></p>
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
                                        <th style="text-align: right;">Handling</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ordrer as $o): ?>
                                        <?php 
                                            $active_class = ($valgt_ordre_id === intval($o['id'])) ? 'style="background-color: rgba(197, 155, 108, 0.05);"' : '';
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
                                                <span class="badge badge-success">Sendt til BC</span>
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

                    <h4 style="color: var(--primary); margin-bottom: 10px; font-size: 15px;">Varer:</h4>
                    <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; border-top: 1px solid var(--border-light); padding-top: 10px;">
                        <?php foreach ($linjer as $l): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 14px;">
                                <div style="flex: 1; padding-right: 10px;">
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($l['name']); ?></div>
                                    <div style="font-size: 11px; color: var(--text-muted);">Varenr: <?php echo htmlspecialchars($l['number']); ?></div>
                                </div>
                                <div style="text-align: right;">
                                    <div><?php echo $l['qty']; ?> stk. &bull; <?php echo number_format($l['unit_price'], 2, ',', '.'); ?> kr.</div>
                                    <div style="font-weight: 600; color: var(--primary);"><?php echo number_format($l['subtotal'], 2, ',', '.'); ?> kr.</div>
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
