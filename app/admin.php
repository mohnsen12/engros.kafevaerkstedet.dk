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
    }
}

// Hent alle brugere
$brugere = $db->query("SELECT * FROM brugere ORDER BY id ASC")->fetchAll();
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
            
            <!-- Venstre side: Opret bruger form -->
            <div>
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
                                                    <!-- Toggle Aktiv status form -->
                                                    <form action="admin.php" method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="toggle_aktiv">
                                                        <input type="hidden" name="bruger_id" value="<?php echo $b['id']; ?>">
                                                        <button type="submit" class="action-icon-btn" title="<?php echo $b['aktiv'] ? 'Deaktiver' : 'Aktiver'; ?>">
                                                            <?php echo $b['aktiv'] ? '⏸️' : '▶️'; ?>
                                                        </button>
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

</body>
</html>
