<?php
/**
 * Vareadgang — Engros Bestillingsportal (Admin)
 *
 * Komplet vareoversigt (varenr + navn) med én kolonne pr. forhandler.
 * Admin krydser af, hvilke varer den enkelte kunde må se i kataloget.
 * Ingen afkrydsning = varen er skjult for den pågældende kunde.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bc_api.php';

require_admin();

$fejl_besked   = '';
$succes_besked = '';

// ─── Hent forhandlere (alle ikke-admin brugere) ──────────────────────────────
$brugere = $db->query("
    SELECT id, brugernavn, firma_navn, bc_kunde_nr, aktiv
    FROM brugere
    WHERE brugernavn != 'admin'
    ORDER BY firma_navn, brugernavn
")->fetchAll();

// ─── Gem afkrydsninger (POST) ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gem') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $fejl_besked = 'Ugyldig session (CSRF). Prøv igen.';
    } else {
        // $_POST['adgang'] = [ bruger_id => [ varenr, varenr, ... ] ]
        $adgang = $_POST['adgang'] ?? [];
        try {
            $db->beginTransaction();
            $del = $db->prepare("DELETE FROM bruger_vareadgang WHERE bruger_id = :id");
            $ins = $db->prepare("INSERT OR IGNORE INTO bruger_vareadgang (bruger_id, item_number) VALUES (:id, :nr)");

            foreach ($brugere as $b) {
                $uid = (int) $b['id'];
                $del->execute([':id' => $uid]);
                $valgte = $adgang[$uid] ?? [];
                if (is_array($valgte)) {
                    foreach ($valgte as $nr) {
                        $nr = trim((string) $nr);
                        if ($nr !== '') {
                            $ins->execute([':id' => $uid, ':nr' => $nr]);
                        }
                    }
                }
            }
            $db->commit();
            $succes_besked = 'Vareadgangen blev gemt.';
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $fejl_besked = 'Kunne ikke gemme vareadgang: ' . $e->getMessage();
        }
    }
}

// ─── Hent nuværende adgang fra DB ─────────────────────────────────────────────
$eksisterende = []; // [bruger_id][item_number] = true
foreach ($db->query("SELECT bruger_id, item_number FROM bruger_vareadgang")->fetchAll() as $r) {
    $eksisterende[(int) $r['bruger_id']][$r['item_number']] = true;
}

// ─── Hent alle varer fra BC (cachet) ──────────────────────────────────────────
$items = [];
try {
    $items = bc_get_all_items_cached();
} catch (Exception $e) {
    $fejl_besked = $fejl_besked ?: ('Kunne ikke hente varer fra Business Central: ' . $e->getMessage());
}

// Behold kun varer med et nummer, og sortér efter varenummer
$items = array_filter($items, fn($it) => !empty($it['number']));
usort($items, fn($a, $b) => strnatcasecmp($a['number'], $b['number']));
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vareadgang — Administration</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .matrix-wrap { overflow-x: auto; max-height: 70vh; overflow-y: auto; border: 1px solid var(--border-light, #e2e2e2); border-radius: 8px; }
        table.matrix { border-collapse: collapse; width: 100%; font-size: 13px; }
        table.matrix th, table.matrix td { padding: 8px 10px; border-bottom: 1px solid var(--border-light, #eee); white-space: nowrap; }
        table.matrix thead th { position: sticky; top: 0; background: var(--bg-card, #fff); z-index: 2; box-shadow: 0 1px 0 var(--border-primary, #ccc); }
        table.matrix td.col-nr { font-family: monospace; color: var(--primary, #7a4a2b); }
        table.matrix th.col-user, table.matrix td.col-user { text-align: center; }
        table.matrix tbody tr:hover { background: rgba(0,0,0,.03); }
        .user-col-head { font-size: 12px; }
        .user-col-head .firma { font-weight: 600; }
        .user-col-head .meta { color: var(--text-muted); font-weight: 400; }
        .matrix-toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
        .pill { background: var(--bg-muted, #f3f3f3); border-radius: 999px; padding: 4px 12px; font-size: 12px; color: var(--text-muted); }
    </style>
</head>
<body>

    <header>
        <div class="nav-container">
            <div class="logo">☕ <span>Kaffeværkstedet</span> Admin</div>
            <div class="nav-links">
                <a href="admin.php">Forhandlere</a>
                <a href="vareadgang.php" class="active">Vareadgang</a>
                <form action="logout.php" method="POST" style="display: inline;">
                    <button type="submit" class="btn-logout">Log ud</button>
                </form>
            </div>
        </div>
    </header>

    <div class="main-content">
        <h1 class="animate-fade-in" style="margin-bottom: 8px;">Vareadgang pr. forhandler</h1>
        <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 24px;">
            Kryds af hvilke varer den enkelte kunde må se i kataloget. Varer uden afkrydsning er skjult for kunden.
        </p>

        <?php if (!empty($fejl_besked)): ?>
            <div class="alert alert-danger animate-fade-in"><span>⚠️</span><div><?php echo htmlspecialchars($fejl_besked); ?></div></div>
        <?php endif; ?>
        <?php if (!empty($succes_besked)): ?>
            <div class="alert alert-success animate-fade-in"><span>✅</span><div><?php echo htmlspecialchars($succes_besked); ?></div></div>
        <?php endif; ?>

        <?php if (empty($brugere)): ?>
            <div class="card"><div class="empty-state"><p>Der er endnu ingen forhandlere oprettet. Opret en under <a href="admin.php">Forhandlere</a> først.</p></div></div>
        <?php elseif (empty($items)): ?>
            <div class="card"><div class="empty-state"><p>Kunne ikke hente varer fra Business Central. Prøv at genindlæse siden.</p></div></div>
        <?php else: ?>

        <form action="vareadgang.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
            <input type="hidden" name="action" value="gem">

            <div class="matrix-toolbar">
                <input type="text" id="vareSoeg" class="form-control" style="max-width: 320px;" placeholder="Søg på varenr eller navn…">
                <span class="pill"><strong><?php echo count($items); ?></strong> varer</span>
                <span class="pill"><strong><?php echo count($brugere); ?></strong> forhandlere</span>
                <button type="submit" class="btn" style="margin-left: auto;">💾 Gem ændringer</button>
            </div>

            <div class="matrix-wrap">
                <table class="matrix">
                    <thead>
                        <tr>
                            <th class="col-nr">Varenr.</th>
                            <th>Varenavn</th>
                            <?php foreach ($brugere as $b): ?>
                                <th class="col-user">
                                    <div class="user-col-head">
                                        <div class="firma"><?php echo htmlspecialchars($b['firma_navn'] ?: $b['brugernavn']); ?></div>
                                        <div class="meta"><?php echo htmlspecialchars($b['bc_kunde_nr']); ?><?php echo $b['aktiv'] ? '' : ' · inaktiv'; ?></div>
                                        <label style="display:block; margin-top:4px; font-size:11px; color:var(--text-muted); cursor:pointer;">
                                            <input type="checkbox" class="toggle-col" data-uid="<?php echo (int)$b['id']; ?>"> alle
                                        </label>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it):
                            $nr   = $it['number'];
                            $navn = $it['displayName'] ?? $it['name'] ?? '';
                            $spaerret = !empty($it['blocked']);
                            $soegetekst = strtolower($nr . ' ' . $navn);
                        ?>
                            <tr class="vare-row" data-soeg="<?php echo htmlspecialchars($soegetekst); ?>">
                                <td class="col-nr"><?php echo htmlspecialchars($nr); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($navn ?: '(uden navn)'); ?>
                                    <?php if ($spaerret): ?><span class="badge badge-danger" style="margin-left:6px; font-size:10px;">spærret i BC</span><?php endif; ?>
                                </td>
                                <?php foreach ($brugere as $b):
                                    $uid = (int) $b['id'];
                                    $checked = isset($eksisterende[$uid][$nr]) ? 'checked' : '';
                                ?>
                                    <td class="col-user">
                                        <input type="checkbox"
                                               class="adgang-box col-<?php echo $uid; ?>"
                                               name="adgang[<?php echo $uid; ?>][]"
                                               value="<?php echo htmlspecialchars($nr); ?>"
                                               <?php echo $checked; ?>>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px; display:flex; justify-content:flex-end;">
                <button type="submit" class="btn">💾 Gem ændringer</button>
            </div>
        </form>

        <script>
            // Klient-side søgning (skjuler rækker uden at fjerne dem fra formularen)
            (function () {
                var soeg = document.getElementById('vareSoeg');
                var rows = Array.prototype.slice.call(document.querySelectorAll('.vare-row'));
                soeg.addEventListener('input', function () {
                    var q = this.value.trim().toLowerCase();
                    rows.forEach(function (r) {
                        r.style.display = (q === '' || r.getAttribute('data-soeg').indexOf(q) !== -1) ? '' : 'none';
                    });
                });

                // "alle"-knap pr. kolonne: kryds alle SYNLIGE rækker af/fra for den bruger
                document.querySelectorAll('.toggle-col').forEach(function (master) {
                    master.addEventListener('change', function () {
                        var uid = this.getAttribute('data-uid');
                        document.querySelectorAll('.adgang-box.col-' + uid).forEach(function (box) {
                            if (box.closest('tr').style.display !== 'none') {
                                box.checked = master.checked;
                            }
                        });
                    });
                });
            })();
        </script>

        <?php endif; ?>
    </div>

    <footer>&copy; <?php echo date('Y'); ?> Kaffeværkstedet ApS. Alle rettigheder forbeholdes.</footer>
</body>
</html>
