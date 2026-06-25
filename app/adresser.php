<?php
/**
 * Leveringsadresser — Engros Bestillingsportal
 *
 * Viser og administrerer den indloggede kundes ship-to leveringsadresser i Business Central.
 * Adresserne læses/oprettes/slettes via det custom kvwoo-API (standard-API'et eksponerer dem ikke).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bc_api.php';

require_login();
if ($_SESSION['brugernavn'] === 'admin') {
    header("Location: admin.php");
    exit;
}

$fejl_besked   = '';
$succes_besked = '';
$customer_nr   = $_SESSION['bc_kunde_nr'] ?? '';

// ─── Håndtering (POST) ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $fejl_besked = 'Ugyldig session. Prøv igen.';
    } elseif ($action === 'opret') {
        $kode     = strtoupper(substr(trim($_POST['kode'] ?? ''), 0, 10));
        $navn     = trim($_POST['navn'] ?? '');
        $adresse  = trim($_POST['adresse'] ?? '');
        $adresse2 = trim($_POST['adresse2'] ?? '');
        $postnr   = trim($_POST['postnr'] ?? '');
        $by       = trim($_POST['by'] ?? '');
        $land     = trim($_POST['land'] ?? 'DK') ?: 'DK';
        $kontakt  = trim($_POST['kontakt'] ?? '');
        $telefon  = trim($_POST['telefon'] ?? '');
        $email    = trim($_POST['email'] ?? '');

        if ($kode === '' || $navn === '' || $adresse === '' || $postnr === '' || $by === '') {
            $fejl_besked = 'Udfyld venligst kode, modtager, adresse, postnr. og by.';
        } else {
            try {
                $res = bc_create_customer_address($customer_nr, [
                    'code' => $kode, 'name' => $navn, 'addressLine1' => $adresse, 'addressLine2' => $adresse2,
                    'city' => $by, 'postCode' => $postnr, 'country' => $land,
                    'contact' => $kontakt, 'phone' => $telefon, 'email' => $email
                ]);
                if ($res['success']) {
                    $succes_besked = "Leveringsadressen '$navn' blev gemt i Business Central.";
                } else {
                    $fejl_besked = "Kunne ikke gemme adressen i BC: " . ($res['error'] ?? 'Ukendt fejl');
                }
            } catch (Exception $e) {
                $fejl_besked = "Netværksfejl under oprettelse af adresse: " . $e->getMessage();
            }
        }
    } elseif ($action === 'opdater') {
        $id       = trim($_POST['id'] ?? '');
        $navn     = trim($_POST['navn'] ?? '');
        $adresse  = trim($_POST['adresse'] ?? '');
        $adresse2 = trim($_POST['adresse2'] ?? '');
        $postnr   = trim($_POST['postnr'] ?? '');
        $by       = trim($_POST['by'] ?? '');
        $land     = trim($_POST['land'] ?? 'DK') ?: 'DK';
        $kontakt  = trim($_POST['kontakt'] ?? '');
        $telefon  = trim($_POST['telefon'] ?? '');
        $email    = trim($_POST['email'] ?? '');

        if ($id === '' || $navn === '' || $adresse === '' || $postnr === '' || $by === '') {
            $fejl_besked = 'Udfyld venligst modtager, adresse, postnr. og by.';
        } else {
            try {
                $res = bc_update_customer_address($id, [
                    'name' => $navn, 'addressLine1' => $adresse, 'addressLine2' => $adresse2,
                    'city' => $by, 'postCode' => $postnr, 'country' => $land,
                    'contact' => $kontakt, 'phone' => $telefon, 'email' => $email
                ]);
                if ($res['success']) {
                    $succes_besked = "Leveringsadressen '$navn' blev opdateret i Business Central.";
                } else {
                    $fejl_besked = "Kunne ikke opdatere adressen i BC: " . ($res['error'] ?? 'Ukendt fejl');
                }
            } catch (Exception $e) {
                $fejl_besked = "Netværksfejl under opdatering af adresse: " . $e->getMessage();
            }
        }
    } elseif ($action === 'slet') {
        $id = trim($_POST['id'] ?? '');
        try {
            $res = bc_delete_customer_address($id);
            if ($res['success']) {
                $succes_besked = 'Leveringsadressen blev slettet i Business Central.';
            } else {
                $fejl_besked = "Kunne ikke slette adressen i BC: " . ($res['error'] ?? 'Ukendt fejl');
            }
        } catch (Exception $e) {
            $fejl_besked = "Netværksfejl under sletning af adresse: " . $e->getMessage();
        }
    }
}

// ─── Hent adresser fra BC (kvwoo) ─────────────────────────────────────────────
$adresser = [];
try {
    $adresser = bc_get_customer_addresses($customer_nr);
} catch (Exception $e) {
    $fejl_besked = $fejl_besked ?: "Kunne ikke hente leveringsadresser fra Business Central.";
}

// Redigeringstilstand: find den valgte adresse (til prefill af formularen)
$rediger_adresse = null;
$rediger_id = $_GET['rediger'] ?? '';
if ($rediger_id !== '') {
    foreach ($adresser as $a) {
        if (($a['id'] ?? '') === $rediger_id) { $rediger_adresse = $a; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leveringsadresser — Kaffeværkstedet</title>
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
                <a href="adresser.php" class="active">Leveringsadresser</a>
                <a href="historik.php">Ordrehistorik</a>
                <form action="logout.php" method="POST" style="display: inline;">
                    <button type="submit" class="btn-logout">Log ud</button>
                </form>
            </div>
        </div>
    </header>

    <div class="main-content">
        <div style="margin-bottom: 30px;">
            <h1 class="animate-fade-in">Leveringsadresser</h1>
            <p style="color: var(--text-muted); font-size: 14px;">Faste leveringssteder for <?php echo htmlspecialchars($_SESSION['firma_navn']); ?> (BC: <?php echo htmlspecialchars($customer_nr); ?>). Kan vælges direkte ved bestilling.</p>
        </div>

        <?php if (!empty($fejl_besked)): ?>
            <div class="alert alert-danger animate-fade-in">
                <span>⚠️</span>
                <div><?php echo htmlspecialchars($fejl_besked); ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($succes_besked)): ?>
            <div class="alert alert-success animate-fade-in">
                <span>✅</span>
                <div><?php echo htmlspecialchars($succes_besked); ?></div>
            </div>
        <?php endif; ?>

        <div class="grid-2 animate-fade-in" style="grid-template-columns: 1fr 2fr; gap: 40px; align-items: start;">

            <!-- Opret / rediger adresse -->
            <div>
                <div class="card">
                    <?php if ($rediger_adresse): ?>
                        <h3 class="card-title">Rediger leveringsadresse</h3>
                        <form action="adresser.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                            <input type="hidden" name="action" value="opdater">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($rediger_adresse['id'] ?? ''); ?>">

                            <div class="form-group">
                                <label class="form-label">Adresse-kode</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($rediger_adresse['code'] ?? ''); ?>" readonly style="opacity: 0.6;">
                                <small style="color: var(--text-muted); font-size: 12px;">Koden er adressens nøgle i BC og kan ikke ændres.</small>
                            </div>

                            <div class="form-group">
                                <label for="navn" class="form-label">Modtager / butiksnavn</label>
                                <input type="text" id="navn" name="navn" class="form-control" value="<?php echo htmlspecialchars($rediger_adresse['displayName'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="adresse" class="form-label">Adresse</label>
                                <input type="text" id="adresse" name="adresse" class="form-control" value="<?php echo htmlspecialchars($rediger_adresse['addressLine1'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="adresse2" class="form-label">Adresse 2 (valgfri)</label>
                                <input type="text" id="adresse2" name="adresse2" class="form-control" value="<?php echo htmlspecialchars($rediger_adresse['addressLine2'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <div style="display: flex; gap: 15px;">
                                    <div style="flex: 1;">
                                        <label for="postnr" class="form-label">Postnummer</label>
                                        <input type="text" id="postnr" name="postnr" class="form-control" value="<?php echo htmlspecialchars($rediger_adresse['postalCode'] ?? ''); ?>" required>
                                    </div>
                                    <div style="flex: 2;">
                                        <label for="by" class="form-label">By</label>
                                        <input type="text" id="by" name="by" class="form-control" value="<?php echo htmlspecialchars($rediger_adresse['city'] ?? ''); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="land" class="form-label">Landekode</label>
                                <input type="text" id="land" name="land" class="form-control" value="<?php echo htmlspecialchars($rediger_adresse['country'] ?? 'DK'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="kontakt" class="form-label">Kontaktperson (valgfri)</label>
                                <input type="text" id="kontakt" name="kontakt" class="form-control" value="<?php echo htmlspecialchars($rediger_adresse['contact'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="telefon" class="form-label">Telefon (valgfri)</label>
                                <input type="text" id="telefon" name="telefon" class="form-control" value="<?php echo htmlspecialchars($rediger_adresse['phoneNumber'] ?? ''); ?>">
                            </div>

                            <div class="form-group" style="margin-bottom: 25px;">
                                <label for="email" class="form-label">Email (valgfri)</label>
                                <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($rediger_adresse['email'] ?? ''); ?>">
                            </div>

                            <button type="submit" class="btn btn-block">Opdater i Business Central</button>
                            <a href="adresser.php" class="btn btn-secondary btn-block" style="text-align: center; margin-top: 10px;">Annuller</a>
                        </form>
                    <?php else: ?>
                        <h3 class="card-title">Tilføj ny leveringsadresse</h3>
                        <form action="adresser.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                            <input type="hidden" name="action" value="opret">

                            <div class="form-group">
                                <label for="kode" class="form-label">Adresse-kode (max 10 tegn)</label>
                                <input type="text" id="kode" name="kode" class="form-control" maxlength="10" placeholder="fx 'BUTIK2'" required autocomplete="off">
                            </div>

                            <div class="form-group">
                                <label for="navn" class="form-label">Modtager / butiksnavn</label>
                                <input type="text" id="navn" name="navn" class="form-control" placeholder="fx 'Kaffebaren Nørrebro'" required>
                            </div>

                            <div class="form-group">
                                <label for="adresse" class="form-label">Adresse</label>
                                <input type="text" id="adresse" name="adresse" class="form-control" placeholder="fx 'Nørrebrogade 42, 1. th.'" required>
                            </div>

                            <div class="form-group">
                                <label for="adresse2" class="form-label">Adresse 2 (valgfri)</label>
                                <input type="text" id="adresse2" name="adresse2" class="form-control" placeholder="fx 'Bygning B, 2. sal'">
                            </div>

                            <div class="form-group">
                                <div style="display: flex; gap: 15px;">
                                    <div style="flex: 1;">
                                        <label for="postnr" class="form-label">Postnummer</label>
                                        <input type="text" id="postnr" name="postnr" class="form-control" placeholder="fx '2200'" required>
                                    </div>
                                    <div style="flex: 2;">
                                        <label for="by" class="form-label">By</label>
                                        <input type="text" id="by" name="by" class="form-control" placeholder="fx 'København N'" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="land" class="form-label">Landekode</label>
                                <input type="text" id="land" name="land" class="form-control" value="DK" placeholder="fx 'DK'" required>
                            </div>

                            <div class="form-group">
                                <label for="kontakt" class="form-label">Kontaktperson (valgfri)</label>
                                <input type="text" id="kontakt" name="kontakt" class="form-control" placeholder="fx 'Jens Jensen'">
                            </div>

                            <div class="form-group">
                                <label for="telefon" class="form-label">Telefon (valgfri)</label>
                                <input type="text" id="telefon" name="telefon" class="form-control" placeholder="fx '12 34 56 78'">
                            </div>

                            <div class="form-group" style="margin-bottom: 25px;">
                                <label for="email" class="form-label">Email (valgfri)</label>
                                <input type="email" id="email" name="email" class="form-control" placeholder="fx 'levering@firma.dk'">
                            </div>

                            <button type="submit" class="btn btn-block">Gem leveringsadresse</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Eksisterende adresser -->
            <div>
                <div class="card">
                    <h3 class="card-title">Gemte leveringsadresser i Business Central</h3>

                    <?php if (empty($adresser)): ?>
                        <div class="empty-state">
                            <p>Der er ikke registreret nogen leveringsadresser i Business Central endnu.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Modtager / Navn</th>
                                        <th>Adresse</th>
                                        <th>Postnr. / By</th>
                                        <th>Kontakt</th>
                                        <th style="text-align: right;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($adresser as $adr): ?>
                                        <tr>
                                            <td style="font-family: monospace; font-weight: bold; color: var(--primary);">
                                                <?php echo htmlspecialchars($adr['code'] ?? ''); ?>
                                            </td>
                                            <td><strong><?php echo htmlspecialchars($adr['displayName'] ?? ''); ?></strong></td>
                                            <td>
                                                <?php echo htmlspecialchars($adr['addressLine1'] ?? ''); ?>
                                                <?php if (!empty($adr['addressLine2'])): ?>
                                                    <div style="font-size: 12px; color: var(--text-muted);"><?php echo htmlspecialchars($adr['addressLine2']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(($adr['postalCode'] ?? '') . ' ' . ($adr['city'] ?? '')); ?></td>
                                            <td style="font-size: 12px;">
                                                <?php if (!empty($adr['contact'])): ?><div><?php echo htmlspecialchars($adr['contact']); ?></div><?php endif; ?>
                                                <?php if (!empty($adr['phoneNumber'])): ?><div style="color: var(--text-muted);"><?php echo htmlspecialchars($adr['phoneNumber']); ?></div><?php endif; ?>
                                                <?php if (!empty($adr['email'])): ?><div style="color: var(--text-muted);"><?php echo htmlspecialchars($adr['email']); ?></div><?php endif; ?>
                                                <?php if (empty($adr['contact']) && empty($adr['phoneNumber']) && empty($adr['email'])): ?><span style="color: var(--text-muted);">—</span><?php endif; ?>
                                            </td>
                                            <td style="text-align: right; white-space: nowrap;">
                                                <a href="adresser.php?rediger=<?php echo urlencode($adr['id'] ?? ''); ?>" class="action-icon-btn" title="Rediger" style="text-decoration: none;">✏️</a>
                                                <form action="adresser.php" method="POST" style="display: inline;" onsubmit="return confirm('Slet denne leveringsadresse i Business Central?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="slet">
                                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($adr['id'] ?? ''); ?>">
                                                    <button type="submit" class="action-icon-btn danger-hover" title="Slet">🗑️</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <footer>
        &copy; <?php echo date('Y'); ?> Kaffeværkstedet ApS. Alle rettigheder forbeholdes.
    </footer>

</body>
</html>
