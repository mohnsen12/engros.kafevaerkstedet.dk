<?php
/**
 * Nød-nulstilling af admin-adgangskode — Engros Bestillingsportal
 *
 * Bruges KUN hvis admin-kodeordet er glemt. Beskyttet med jeres BC client secret
 * (samme værdi som i bc_config.json → BC_CLIENT_SECRET), så kun I kan bruge den.
 *
 * Sådan gør du:
 *   1. Åbn https://engros.kaffevaerkstedet.dk/nulstil-admin.php
 *   2. Indsæt jeres BC client secret som "nøgle" + vælg et nyt admin-kodeord.
 *   3. Log ind med admin + det nye kodeord.
 *   4. (Anbefalet) slet denne fil bagefter via Filhåndtering for en sikkerheds skyld.
 */

require_once __DIR__ . '/config.php';  // giver BC_SECRET
require_once __DIR__ . '/db.php';      // giver $db (og opretter tabeller/admin ved behov)

$besked = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_POST['key'] ?? '';
    $ny  = $_POST['ny'] ?? '';

    // Konstant-tids sammenligning mod BC client secret
    if (BC_SECRET === '' || !hash_equals(BC_SECRET, $key)) {
        $besked = 'Forkert nøgle. (Nøglen er jeres BC client secret fra bc_config.json.)';
    } elseif (strlen($ny) < 8) {
        $besked = 'Det nye kodeord skal være mindst 8 tegn.';
    } else {
        // Sørg for at admin-brugeren findes, og sæt nyt kodeord
        $findes = $db->query("SELECT COUNT(*) FROM brugere WHERE brugernavn = 'admin'")->fetchColumn();
        if ($findes > 0) {
            $stmt = $db->prepare("UPDATE brugere SET password_hash = :h, aktiv = 1 WHERE brugernavn = 'admin'");
            $stmt->execute([':h' => password_hash($ny, PASSWORD_BCRYPT)]);
        } else {
            $stmt = $db->prepare("INSERT INTO brugere (brugernavn, password_hash, bc_kunde_nr, bc_kunde_id, firma_navn, aktiv)
                                  VALUES ('admin', :h, 'ADMIN', 'ADMIN', 'Administrator', 1)");
            $stmt->execute([':h' => password_hash($ny, PASSWORD_BCRYPT)]);
        }
        $ok = true;
        $besked = 'Admin-kodeordet er nulstillet. Log ind med brugernavn "admin" og dit nye kodeord — og slet derefter denne fil.';
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nulstil admin-adgangskode</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="main-content" style="max-width: 480px; margin: 60px auto;">
        <div class="card">
            <h3 class="card-title">Nulstil admin-adgangskode</h3>

            <?php if ($besked): ?>
                <div class="alert <?php echo $ok ? 'alert-success' : 'alert-danger'; ?>" style="margin-bottom: 20px;">
                    <span><?php echo $ok ? '✅' : '⚠️'; ?></span>
                    <div><?php echo htmlspecialchars($besked); ?></div>
                </div>
            <?php endif; ?>

            <?php if (!$ok): ?>
            <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">
                Nødværktøj. Nøglen er jeres <strong>BC client secret</strong> (fra <code>bc_config.json</code>).
            </p>
            <form method="POST">
                <div class="form-group">
                    <label for="key" class="form-label">Nøgle (BC client secret)</label>
                    <input type="password" id="key" name="key" class="form-control" required autocomplete="off">
                </div>
                <div class="form-group" style="margin-bottom: 25px;">
                    <label for="ny" class="form-label">Nyt admin-kodeord (mindst 8 tegn)</label>
                    <input type="password" id="ny" name="ny" class="form-control" minlength="8" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-block">Nulstil kodeord</button>
            </form>
            <?php else: ?>
                <a href="login.php" class="btn btn-block" style="text-align: center;">Gå til login</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
