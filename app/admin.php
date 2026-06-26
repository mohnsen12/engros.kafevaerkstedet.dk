<?php
/**
 * Admin Panel — Engros Bestillingsportal
 * 
 * Giver administratoren mulighed for at administrere engros-brugere og tilknytte dem BC-kunder.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bc_api.php';

// Sikr at kun admin har adgang
require_admin();

$fejl_besked = '';
$succes_besked = '';

// Behandl handlinger
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $fejl_besked = 'Ugyldig session (CSRF validering fejlede).';
    } else {
        // ─── Handling: OPRET BRUGER ──────────────────────────────────────────
        if ($action === 'opret') {
            $brugernavn  = trim($_POST['brugernavn'] ?? '');
            $password    = $_POST['password'] ?? '';
            $bc_kunde_nr = trim($_POST['bc_kunde_nr'] ?? '');
            
            if (empty($brugernavn) || empty($password) || empty($bc_kunde_nr)) {
                $fejl_besked = 'Alle felter skal udfyldes.';
            } else {
                // Tjek om brugernavn er optaget
                $stmt = $db->prepare("SELECT COUNT(*) FROM brugere WHERE brugernavn = :username");
                $stmt->execute([':username' => $brugernavn]);
                if ($stmt->fetchColumn() > 0) {
                    $fejl_besked = 'Brugernavnet er allerede optaget.';
                } else {
                    // Slå kunden op i Business Central via API for at hente rigtigt navn og GUID
                    try {
                        $bc_kunde = bc_get_customer_by_number($bc_kunde_nr);
                        
                        if (!$bc_kunde) {
                            $fejl_besked = "Kundenummer '$bc_kunde_nr' blev ikke fundet i Business Central. Brugeren blev ikke oprettet.";
                        } else {
                            $bc_kunde_id = $bc_kunde['id'];
                            $firma_navn  = $bc_kunde['displayName'] ?? $bc_kunde['name'] ?? '';
                            
                            $hash = password_hash($password, PASSWORD_BCRYPT);
                            
                            $insert = $db->prepare("
                                INSERT INTO brugere (brugernavn, password_hash, bc_kunde_nr, bc_kunde_id, firma_navn, aktiv)
                                VALUES (:username, :hash, :bc_nr, :bc_id, :firma, 1)
                            ");
                            $insert->execute([
                                ':username' => $brugernavn,
                                ':hash'     => $hash,
                                ':bc_nr'    => $bc_kunde_nr,
                                ':bc_id'    => $bc_kunde_id,
                                ':firma'    => $firma_navn
                            ]);
                            
                            $succes_besked = "Bruger '$brugernavn' blev oprettet og succesfuldt knyttet til '$firma_navn' i BC.";
                        }
                    } catch (Exception $e) {
                        $fejl_besked = "Der opstod en fejl under opslag i Business Central: " . $e->getMessage();
                    }
                }
            }
        }
        // ─── Handling: TOGGLE AKTIV / DEAKTIVER ──────────────────────────────
        elseif ($action === 'toggle_aktiv') {
            $bruger_id = intval($_POST['bruger_id'] ?? 0);
            
            // Forhindre deaktivering af admin sig selv
            $stmt = $db->prepare("SELECT brugernavn, aktiv FROM brugere WHERE id = :id");
            $stmt->execute([':id' => $bruger_id]);
            $bruger = $stmt->fetch();
            
            if ($bruger) {
                if ($bruger['brugernavn'] === 'admin') {
                    $fejl_besked = 'Du kan ikke deaktivere administrator-kontoen.';
                } else {
                    $ny_status = $bruger['aktiv'] ? 0 : 1;
                    $update = $db->prepare("UPDATE brugere SET aktiv = :aktiv WHERE id = :id");
                    $update->execute([':aktiv' => $ny_status, ':id' => $bruger_id]);
                    $succes_besked = "Brugerens status blev opdateret.";
                }
            }
        }
        // ─── Handling: SLET BRUGER ───────────────────────────────────────────
        elseif ($action === 'slet') {
            $bruger_id = intval($_POST['bruger_id'] ?? 0);
            
            $stmt = $db->prepare("SELECT brugernavn FROM brugere WHERE id = :id");
            $stmt->execute([':id' => $bruger_id]);
            $username = $stmt->fetchColumn();
            
            if ($username === 'admin') {
                $fejl_besked = 'Du kan ikke slette administrator-kontoen.';
            } else {
                $delete = $db->prepare("DELETE FROM brugere WHERE id = :id");
                $delete->execute([':id' => $bruger_id]);
                $succes_besked = "Brugeren '$username' blev slettet fra portalen.";
            }
        }
        // ─── Handling: SKIFT ADMINS EGET KODEORD ─────────────────────────────
        elseif ($action === 'skift_admin_kode') {
            $nuvaerende = $_POST['nuvaerende'] ?? '';
            $ny         = $_POST['ny'] ?? '';
            $ny2        = $_POST['ny2'] ?? '';

            $stmt = $db->prepare("SELECT password_hash FROM brugere WHERE id = :id");
            $stmt->execute([':id' => $_SESSION['bruger_id']]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($nuvaerende, $hash)) {
                $fejl_besked = 'Det nuværende kodeord er forkert.';
            } elseif (strlen($ny) < 8) {
                $fejl_besked = 'Det nye kodeord skal være mindst 8 tegn.';
            } elseif ($ny !== $ny2) {
                $fejl_besked = 'De to nye kodeord er ikke ens.';
            } else {
                $update = $db->prepare("UPDATE brugere SET password_hash = :hash WHERE id = :id");
                $update->execute([':hash' => password_hash($ny, PASSWORD_BCRYPT), ':id' => $_SESSION['bruger_id']]);
                $succes_besked = 'Dit administrator-kodeord blev opdateret.';
            }
        }
        // ─── Handling: NULSTIL EN FORHANDLERS KODEORD ────────────────────────
        elseif ($action === 'nulstil_kode') {
            $bruger_id = intval($_POST['bruger_id'] ?? 0);
            $ny        = $_POST['ny_kode'] ?? '';

            $stmt = $db->prepare("SELECT brugernavn FROM brugere WHERE id = :id");
            $stmt->execute([':id' => $bruger_id]);
            $username = $stmt->fetchColumn();

            if (!$username) {
                $fejl_besked = 'Brugeren blev ikke fundet.';
            } elseif ($username === 'admin') {
                $fejl_besked = 'Administrator-kodeordet skiftes via "Skift dit admin-kodeord" (kræver nuværende kodeord).';
            } elseif (strlen($ny) < 8) {
                $fejl_besked = 'Det nye kodeord skal være mindst 8 tegn.';
            } else {
                $update = $db->prepare("UPDATE brugere SET password_hash = :hash WHERE id = :id");
                $update->execute([':hash' => password_hash($ny, PASSWORD_BCRYPT), ':id' => $bruger_id]);
                $succes_besked = "Kodeordet for '$username' blev nulstillet. Udlever det nye kodeord til forhandleren.";
            }
        }
        // ─── Handling: REDIGER FORHANDLER ────────────────────────────────────
        elseif ($action === 'rediger_bruger') {
            $bruger_id   = intval($_POST['bruger_id'] ?? 0);
            $brugernavn  = trim($_POST['brugernavn'] ?? '');
            $bc_kunde_nr = trim($_POST['bc_kunde_nr'] ?? '');

            $stmt = $db->prepare("SELECT brugernavn FROM brugere WHERE id = :id");
            $stmt->execute([':id' => $bruger_id]);
            $eksisterende = $stmt->fetchColumn();

            if (!$eksisterende) {
                $fejl_besked = 'Forhandleren blev ikke fundet.';
            } elseif ($eksisterende === 'admin') {
                $fejl_besked = 'Administrator-kontoen kan ikke redigeres her.';
            } elseif ($brugernavn === '' || $bc_kunde_nr === '') {
                $fejl_besked = 'Brugernavn og BC-kundenummer skal udfyldes.';
            } else {
                // Tjek at brugernavnet ikke er taget af en ANDEN bruger
                $stmt = $db->prepare("SELECT COUNT(*) FROM brugere WHERE brugernavn = :u AND id != :id");
                $stmt->execute([':u' => $brugernavn, ':id' => $bruger_id]);
                if ($stmt->fetchColumn() > 0) {
                    $fejl_besked = 'Brugernavnet er allerede optaget af en anden bruger.';
                } else {
                    // Slå kundenummeret op i BC for at hente korrekt firmanavn + GUID
                    try {
                        $bc_kunde = bc_get_customer_by_number($bc_kunde_nr);
                        if (!$bc_kunde) {
                            $fejl_besked = "Kundenummer '$bc_kunde_nr' blev ikke fundet i Business Central. Intet blev ændret.";
                        } else {
                            $update = $db->prepare("
                                UPDATE brugere
                                SET brugernavn = :u, bc_kunde_nr = :nr, bc_kunde_id = :id_bc, firma_navn = :firma
                                WHERE id = :id
                            ");
                            $update->execute([
                                ':u'     => $brugernavn,
                                ':nr'    => $bc_kunde_nr,
                                ':id_bc' => $bc_kunde['id'],
                                ':firma' => $bc_kunde['displayName'] ?? $bc_kunde['name'] ?? '',
                                ':id'    => $bruger_id
                            ]);
                            $succes_besked = "Forhandleren blev opdateret og knyttet til '" . ($bc_kunde['displayName'] ?? '') . "' i BC.";
                        }
                    } catch (Exception $e) {
                        $fejl_besked = "Fejl under opslag i Business Central: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Hent alle brugere
$brugere = $db->query("SELECT * FROM brugere ORDER BY id ASC")->fetchAll();

// Redigeringstilstand: find den valgte forhandler (til prefill af formularen)
$rediger_bruger = null;
$rediger_id = intval($_GET['rediger'] ?? 0);
if ($rediger_id > 0) {
    foreach ($brugere as $b) {
        if ((int) $b['id'] === $rediger_id && $b['brugernavn'] !== 'admin') {
            $rediger_bruger = $b;
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
    <title>Administration — Engros Bestillingsportal</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

    <header>
        <div class="nav-container">
            <div class="logo">
                ☕ <span>Kaffeværkstedet</span> Admin
            </div>
            <div class="nav-links">
                <a href="admin.php" class="active">Forhandlere</a>
                <a href="vareadgang.php">Vareadgang</a>
                <form action="logout.php" method="POST" style="display: inline;">
                    <button type="submit" class="btn-logout">Log ud</button>
                </form>
            </div>
        </div>
    </header>

    <div class="main-content">
        <h1 class="animate-fade-in" style="margin-bottom: 30px;">Forhandleradministration</h1>

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

        <div class="grid-2 animate-fade-in">
            
            <!-- Venstre side: Opret / rediger forhandler -->
            <div>
                <?php if ($rediger_bruger): ?>
                <div class="card">
                    <h3 class="card-title">Rediger forhandler</h3>
                    <form action="admin.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                        <input type="hidden" name="action" value="rediger_bruger">
                        <input type="hidden" name="bruger_id" value="<?php echo (int) $rediger_bruger['id']; ?>">

                        <div class="form-group">
                            <label for="rediger_brugernavn" class="form-label">Portal brugernavn</label>
                            <input type="text" id="rediger_brugernavn" name="brugernavn" class="form-control"
                                   value="<?php echo htmlspecialchars($rediger_bruger['brugernavn']); ?>" required autocomplete="off">
                        </div>

                        <div class="form-group" style="margin-bottom: 25px;">
                            <label for="rediger_bc_kunde_nr" class="form-label">Business Central Kundenummer</label>
                            <input type="text" id="rediger_bc_kunde_nr" name="bc_kunde_nr" class="form-control"
                                   value="<?php echo htmlspecialchars($rediger_bruger['bc_kunde_nr']); ?>" required>
                            <small style="color: var(--text-muted); display: block; margin-top: 6px; font-size: 12px;">
                                Firmanavn og GUID opdateres automatisk fra BC ved gem. Nuværende firma: <strong><?php echo htmlspecialchars($rediger_bruger['firma_navn']); ?></strong>
                            </small>
                        </div>

                        <button type="submit" class="btn btn-block">Gem ændringer</button>
                        <a href="admin.php" class="btn btn-secondary btn-block" style="text-align: center; margin-top: 10px;">Annuller</a>
                    </form>
                </div>
                <?php else: ?>
                <div class="card">
                    <h3 class="card-title">Opret ny engros-kunde</h3>
                    <form action="admin.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                        <input type="hidden" name="action" value="opret">
                        
                        <div class="form-group">
                            <label for="brugernavn" class="form-label">Portal brugernavn</label>
                            <input type="text" id="brugernavn" name="brugernavn" class="form-control" 
                                   placeholder="fx 'cafehygge'" required autocomplete="off">
                        </div>
                        
                        <div class="form-group">
                            <label for="password" class="form-label">Adgangskode (skal udleveres til kunden)</label>
                            <input type="password" id="password" name="password" class="form-control" 
                                   placeholder="Skriv en stærk adgangskode" required>
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 25px;">
                            <label for="bc_kunde_nr" class="form-label">Business Central Kundenummer</label>
                            <input type="text" id="bc_kunde_nr" name="bc_kunde_nr" class="form-control" 
                                   placeholder="fx 'D00010' eller '20000'" required>
                            <small style="color: var(--text-muted); display: block; margin-top: 6px; font-size: 12px;">
                                Systemet slår automatisk kunden op i Business Central for at hente firmanavn og GUID ved oprettelse.
                            </small>
                        </div>
                        
                        <button type="submit" class="btn btn-block">
                            Opret Bruger & Forbind til BC
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="card" style="margin-top: 30px;">
                    <h3 class="card-title">Skift dit admin-kodeord</h3>
                    <form action="admin.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                        <input type="hidden" name="action" value="skift_admin_kode">

                        <div class="form-group">
                            <label for="nuvaerende" class="form-label">Nuværende kodeord</label>
                            <input type="password" id="nuvaerende" name="nuvaerende" class="form-control" required autocomplete="current-password">
                        </div>

                        <div class="form-group">
                            <label for="ny" class="form-label">Nyt kodeord (mindst 8 tegn)</label>
                            <input type="password" id="ny" name="ny" class="form-control" minlength="8" required autocomplete="new-password">
                        </div>

                        <div class="form-group" style="margin-bottom: 25px;">
                            <label for="ny2" class="form-label">Gentag nyt kodeord</label>
                            <input type="password" id="ny2" name="ny2" class="form-control" minlength="8" required autocomplete="new-password">
                        </div>

                        <button type="submit" class="btn btn-block">Opdater kodeord</button>
                    </form>
                </div>
            </div>

            <!-- Højre side: Liste over brugere -->
            <div>
                <div class="card">
                    <h3 class="card-title">Aktive brugere</h3>
                    
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Brugernavn</th>
                                    <th>BC Nr / Firma</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Handlinger</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($brugere as $b): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($b['brugernavn']); ?></strong>
                                            <?php if ($b['brugernavn'] === 'admin'): ?>
                                                <span class="badge badge-info" style="margin-left: 5px;">System</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($b['bc_kunde_nr'] !== 'ADMIN'): ?>
                                                <div style="font-family: monospace; font-size: 13px; color: var(--primary);">
                                                    <?php echo htmlspecialchars($b['bc_kunde_nr']); ?>
                                                </div>
                                                <div style="font-size: 12px; color: var(--text-muted);">
                                                    <?php echo htmlspecialchars($b['firma_navn']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-size: 13px;">Ikke relevant</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($b['aktiv']): ?>
                                                <span class="badge badge-success">Aktiv</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Deaktiveret</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <?php if ($b['brugernavn'] !== 'admin'): ?>
                                                <div style="display: inline-flex; gap: 8px; justify-content: flex-end;">
                                                    <!-- Rediger forhandler -->
                                                    <a href="admin.php?rediger=<?php echo (int) $b['id']; ?>" class="action-icon-btn" title="Rediger oplysninger" style="text-decoration: none;">✏️</a>

                                                    <!-- Toggle Aktiv status form -->
                                                    <form action="admin.php" method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="toggle_aktiv">
                                                        <input type="hidden" name="bruger_id" value="<?php echo $b['id']; ?>">
                                                        <button type="submit" class="action-icon-btn" title="<?php echo $b['aktiv'] ? 'Deaktiver' : 'Aktiver'; ?>">
                                                            <?php echo $b['aktiv'] ? '⏸️' : '▶️'; ?>
                                                        </button>
                                                    </form>

                                                    <!-- Nulstil kodeord form -->
                                                    <form action="admin.php" method="POST" style="display:inline;" onsubmit="return nulstilKode(this);">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="nulstil_kode">
                                                        <input type="hidden" name="bruger_id" value="<?php echo $b['id']; ?>">
                                                        <input type="hidden" name="ny_kode" value="">
                                                        <button type="submit" class="action-icon-btn" title="Nulstil kodeord">🔑</button>
                                                    </form>

                                                    <!-- Slet bruger form -->
                                                    <form action="admin.php" method="POST" style="display:inline;" onsubmit="return confirm('Er du sikker på, at du vil slette brugeren \'<?php echo htmlspecialchars($b['brugernavn']); ?>\' permanent?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="slet">
                                                        <input type="hidden" name="bruger_id" value="<?php echo $b['id']; ?>">
                                                        <button type="submit" class="action-icon-btn danger-hover" title="Slet bruger">
                                                            🗑️
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-size: 13px;">Ingen handlinger</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <footer>
        &copy; <?php echo date('Y'); ?> Kaffeværkstedet ApS. Alle rettigheder forbeholdes.
    </footer>

    <script>
        // Spørg om nyt kodeord ved nulstilling af en forhandlers adgangskode.
        function nulstilKode(form) {
            var p = prompt('Indtast et nyt kodeord for forhandleren (mindst 8 tegn):');
            if (p === null) return false;            // annulleret
            if (p.length < 8) { alert('Kodeordet skal være mindst 8 tegn.'); return false; }
            form.ny_kode.value = p;
            return true;
        }
    </script>

</body>
</html>
