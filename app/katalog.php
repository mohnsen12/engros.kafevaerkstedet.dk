<?php
/**
 * Varekatalog — Engros Bestillingsportal
 * 
 * Viser varer fra Business Central med søgefunktion, filtrering, caching og en integreret indkøbskurv.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bc_api.php';

// Kræv at brugeren er logget ind som forhandler
require_login();
if ($_SESSION['brugernavn'] === 'admin') {
    header("Location: admin.php");
    exit;
}

// ─── Indlæs Indkøbskurv (i session) ──────────────────────────────────────────
// Kurv-format: [ "<item_id>|<variant_id>" => ['item_id'=>, 'variant_id'=>, 'qty'=>int] ]
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
// Ryd evt. gammelt kurv-format (skalarværdier) for at undgå fejl efter opdatering
foreach ($_SESSION['cart'] as $v) {
    if (!is_array($v)) { $_SESSION['cart'] = []; break; }
}

/** Sammensæt en unik kurv-nøgle af vare-id, variant-id og enheds-id. */
function cart_key($item_id, $variant_id, $unit_id = '') {
    return $item_id . '|' . ($variant_id ?? '') . '|' . ($unit_id ?? '');
}

$fejl_besked = '';
$succes_besked = '';

// ─── Håndtering af kurv-handlinger (POST) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $fejl_besked = 'Sessionen er udløbet. Prøv igen.';
    } else {
        if ($action === 'add') {
            $item_id    = $_POST['item_id'] ?? '';
            $variant_id = trim($_POST['variant_id'] ?? '');
            $unit_id    = trim($_POST['unit_id'] ?? '');
            $quantity   = intval($_POST['quantity'] ?? 0);
            if ($item_id !== '' && $quantity > 0) {
                $key = cart_key($item_id, $variant_id, $unit_id);
                if (isset($_SESSION['cart'][$key])) {
                    $_SESSION['cart'][$key]['qty'] += $quantity;
                } else {
                    $_SESSION['cart'][$key] = ['item_id' => $item_id, 'variant_id' => $variant_id, 'unit_id' => $unit_id, 'qty' => $quantity];
                }
                $succes_besked = 'Vare blev tilføjet til indkøbskurven.';
            }
        } elseif ($action === 'update') {
            $key      = $_POST['cart_key'] ?? '';
            $quantity = intval($_POST['quantity'] ?? 0);
            if (isset($_SESSION['cart'][$key])) {
                if ($quantity <= 0) {
                    unset($_SESSION['cart'][$key]);
                } else {
                    $_SESSION['cart'][$key]['qty'] = $quantity;
                }
            }
            $succes_besked = 'Indkøbskurven blev opdateret.';
        } elseif ($action === 'remove') {
            unset($_SESSION['cart'][$_POST['cart_key'] ?? '']);
            $succes_besked = 'Varen blev fjernet fra kurven.';
        } elseif ($action === 'clear') {
            $_SESSION['cart'] = [];
            $succes_besked = 'Indkøbskurven blev tømt.';
        }

        // Redirect for at undgå at sende POST igen ved refresh
        header("Location: katalog.php?success=" . urlencode($succes_besked));
        exit;
    }
}

if (isset($_GET['success'])) {
    $succes_besked = $_GET['success'];
}

// ─── Hent varer (cachet) og filtrér til kundens tilladte sortiment ────────────
$items = [];
try {
    $alle_varer = bc_get_all_items_cached(); // alle varer fra BC (inkl. spærrede), cachet
} catch (Exception $e) {
    $alle_varer = [];
    $fejl_besked = "Kunne ikke synkronisere med Business Central katalog: " . $e->getMessage();
}

// Hent hvilke varenumre denne bruger må se (styres af admin under "Vareadgang")
$tilladte_numre = [];
$stmt = $db->prepare("SELECT item_number FROM bruger_vareadgang WHERE bruger_id = :id");
$stmt->execute([':id' => $_SESSION['bruger_id']]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nr) {
    $tilladte_numre[$nr] = true;
}

// Vis kun varer der (a) er tildelt brugeren og (b) ikke er spærret i BC
foreach ($alle_varer as $vare) {
    $nr = $vare['number'] ?? '';
    if ($nr !== '' && isset($tilladte_numre[$nr]) && empty($vare['blocked'])) {
        $items[] = $vare;
    }
}

// ─── Hent varianter (cachet, valgfrit) ───────────────────────────────────────
$variant_map   = []; // itemNo => [ ['id','code','label'], ... ]
$variant_by_id = []; // variant_id => ['code','label']
try {
    $variant_map = bc_get_variants_cached();
    foreach ($variant_map as $liste) {
        foreach ($liste as $v) {
            $variant_by_id[$v['id']] = $v;
        }
    }
} catch (Exception $e) {
    // Varianter er valgfrie — fejl her må ikke vælte kataloget
}

// ─── Hent enheder (cachet, valgfrit) ──────────────────────────────────────────
$units_map       = []; // kode => ['id','label']
$item_unit_codes = []; // itemNo => [koder]  (fra prislister — fallback)
$item_units      = []; // itemNo => [koder]  (autoritativ, fra Item Unit of Measure)
$unit_by_id      = []; // unit_id => ['code','label']
try {
    $units_map       = bc_get_units_cached();
    $item_units      = bc_get_item_units_cached();       // komplet liste (når kvwoo udstiller den)
    $item_unit_codes = bc_get_item_unit_codes_cached();  // fallback fra prislister
    foreach ($units_map as $kode => $u) {
        $unit_by_id[$u['id']] = ['code' => $kode, 'label' => $u['label']];
    }
} catch (Exception $e) {
    // Enheder er valgfrie — fejl her må ikke vælte kataloget
}

/**
 * Bygger listen af valgbare enheder for en vare. Kilde-prioritet:
 *   1) Basisenheden (altid med).
 *   2) Item Unit of Measure fra BC (komplet — inkl. enheder uden pris, fx 250G).
 *   3) Prisliste-enheder (fallback indtil kvwoo udstiller punkt 2).
 * Returnerer [ ['id','code','label'], ... ].
 */
function byg_enheds_valg($item, $item_units, $item_unit_codes, $units_map) {
    $nr = $item['number'] ?? '';
    $koder = array_merge(
        [$item['baseUnitOfMeasureCode'] ?? ''],
        $item_units[$nr] ?? [],
        $item_unit_codes[$nr] ?? []
    );
    $valg = [];
    $set  = [];
    foreach ($koder as $kode) {
        if ($kode === '' || isset($set[$kode]) || !isset($units_map[$kode])) continue;
        $set[$kode] = true;
        $valg[] = ['id' => $units_map[$kode]['id'], 'code' => $kode, 'label' => $units_map[$kode]['label']];
    }
    return $valg;
}

// ─── Søgning og filtrering ───────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
if (!empty($search)) {
    $filtered_items = [];
    foreach ($items as $item) {
        $name = $item['displayName'] ?? $item['name'] ?? '';
        $number = $item['number'] ?? '';
        if (stripos($name, $search) !== false || stripos($number, $search) !== false) {
            $filtered_items[] = $item;
        }
    }
    $display_items = $filtered_items;
} else {
    $display_items = $items;
}

// Strukturér kurven til visning
$cart_details = [];
$cart_total = 0;
foreach ($_SESSION['cart'] as $key => $entry) {
    // Find varen i hele varekataloget (ikke kun det filtrerede)
    $found_item = null;
    foreach ($alle_varer as $item) {
        if (($item['id'] ?? '') === $entry['item_id']) {
            $found_item = $item;
            break;
        }
    }
    if (!$found_item) {
        unset($_SESSION['cart'][$key]); // Vare ikke fundet, fjern fra kurv
        continue;
    }
    $qty      = (int) $entry['qty'];
    $price    = floatval($found_item['unitPrice'] ?? 0);
    $subtotal = $price * $qty;
    $cart_total += $subtotal;

    $variant_label = '';
    if (!empty($entry['variant_id']) && isset($variant_by_id[$entry['variant_id']])) {
        $variant_label = $variant_by_id[$entry['variant_id']]['label'];
    }
    $unit_label = '';
    if (!empty($entry['unit_id']) && isset($unit_by_id[$entry['unit_id']])) {
        $unit_label = $unit_by_id[$entry['unit_id']]['code'];
    }

    $cart_details[] = [
        'key'           => $key,
        'number'        => $found_item['number'],
        'name'          => $found_item['displayName'] ?? $found_item['name'] ?? 'Ukendt vare',
        'variant_label' => $variant_label,
        'unit_label'    => $unit_label,
        'price'         => $price,
        'qty'           => $qty,
        'subtotal'      => $subtotal
    ];
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Varekatalog — Kaffeværkstedet</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

    <header>
        <div class="nav-container">
            <div class="logo">
                ☕ <span>Kaffeværkstedet</span>
            </div>
            <div class="nav-links">
                <a href="katalog.php" class="active">Varekatalog</a>
                <a href="adresser.php">Leveringsadresser</a>
                <a href="historik.php">Ordrehistorik</a>
                <form action="logout.php" method="POST" style="display: inline;">
                    <button type="submit" class="btn-logout">Log ud</button>
                </form>
            </div>
        </div>
    </header>

    <div class="main-content">
        <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <h1 class="animate-fade-in" style="margin: 0;">Velkommen, <?php echo htmlspecialchars($_SESSION['firma_navn']); ?></h1>
                <p style="color: var(--text-muted); font-size: 14px;">Kundenummer: <?php echo htmlspecialchars($_SESSION['bc_kunde_nr']); ?></p>
            </div>
            
            <form action="katalog.php" method="GET" style="display: flex; gap: 10px; width: 100%; max-width: 400px;">
                <input type="text" name="search" class="form-control" placeholder="Søg på varenavn eller nr..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-secondary" style="padding: 10px 16px;">Søg</button>
                <?php if (!empty($search)): ?>
                    <a href="katalog.php" class="btn btn-secondary" style="padding: 10px 16px; display: flex; align-items: center;">Nulstil</a>
                <?php endif; ?>
            </form>
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

        <div class="grid-2 animate-fade-in" style="grid-template-columns: 2fr 1fr; gap: 40px; align-items: start;">
            
            <!-- Varekatalog -->
            <div>
                <div class="card" style="padding: 24px;">
                    <h3 class="card-title" style="margin-bottom: 20px;">Varesortiment</h3>
                    
                    <?php if (empty($display_items)): ?>
                        <div class="empty-state">
                            <p>Ingen varer fundet i sortimentet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="catalog-list">
                                <thead>
                                    <tr>
                                        <th>Vare</th>
                                        <th>Variant / enhed</th>
                                        <th style="text-align: right;">Vejl. pris</th>
                                        <th style="text-align: center;">Antal</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($display_items as $item): ?>
                                        <?php
                                            $item_name = $item['displayName'] ?? $item['name'] ?? '';
                                            if (empty($item_name)) continue; // Spring navneløse over
                                            $item_price = floatval($item['unitPrice'] ?? 0);
                                            $enhed      = $item['baseUnitOfMeasureCode'] ?? '';
                                            $varianter  = $variant_map[$item['number'] ?? ''] ?? [];
                                            $enheder    = byg_enheds_valg($item, $item_units, $item_unit_codes, $units_map);
                                        ?>
                                        <tr>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 12px;">
                                                    <img src="vare_billede.php?id=<?php echo htmlspecialchars($item['id']); ?>" alt="" loading="lazy"
                                                         style="width: 44px; height: 44px; object-fit: cover; border-radius: 6px; background: rgba(255,255,255,0.05); flex-shrink: 0;"
                                                         onerror="this.style.display='none'">
                                                    <div>
                                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($item_name); ?></div>
                                                        <div style="font-size: 12px; color: var(--text-muted); font-family: monospace;">
                                                            <?php echo htmlspecialchars($item['number'] ?? ''); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <form action="katalog.php" method="POST" class="add-form" style="margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="add">
                                                    <input type="hidden" name="item_id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                                    <div style="display: flex; flex-direction: column; gap: 6px;">
                                                        <?php if (!empty($varianter)): ?>
                                                            <select name="variant_id" class="form-control" style="min-width: 160px; padding: 6px 8px;">
                                                                <?php foreach ($varianter as $v): ?>
                                                                    <option value="<?php echo htmlspecialchars($v['id']); ?>">
                                                                        <?php echo htmlspecialchars($v['label']); ?><?php echo $v['code'] !== '' ? ' (' . htmlspecialchars($v['code']) . ')' : ''; ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        <?php endif; ?>

                                                        <?php if (count($enheder) > 1): ?>
                                                            <select name="unit_id" class="form-control" style="min-width: 120px; padding: 6px 8px;">
                                                                <?php foreach ($enheder as $u): ?>
                                                                    <option value="<?php echo htmlspecialchars($u['id']); ?>"><?php echo htmlspecialchars($u['code']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        <?php elseif (empty($varianter)): ?>
                                                            <span style="color: var(--text-muted); font-size: 13px;"><?php echo htmlspecialchars($enhed ?: '—'); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                            </td>
                                            <td style="text-align: right; white-space: nowrap;">
                                                <?php echo number_format($item_price, 2, ',', '.'); ?> kr.
                                            </td>
                                            <td style="text-align: center;">
                                                <div class="qty-stepper">
                                                    <button type="button" class="qty-btn" onclick="stepQty(this,-1)">−</button>
                                                    <input type="number" name="quantity" value="1" min="1" max="1000" class="qty-input">
                                                    <button type="button" class="qty-btn" onclick="stepQty(this,1)">+</button>
                                                </div>
                                            </td>
                                            <td style="text-align: right;">
                                                    <button type="submit" class="btn" style="padding: 8px 14px; font-size: 13px; white-space: nowrap;">Tilføj</button>
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

            <!-- Indkøbskurv (Sidebar) -->
            <div class="cart-summary">
                <div class="card">
                    <h3 class="card-title">
                        <span>🛒 Indkøbskurv</span>
                        <?php if (!empty($cart_details)): ?>
                            <form action="katalog.php" method="POST" style="display: inline;" onsubmit="return confirm('Vil du tømme din indkøbskurv?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                <input type="hidden" name="action" value="clear">
                                <button type="submit" style="background: none; border: none; color: var(--error); cursor: pointer; font-size: 13px;">Tøm</button>
                            </form>
                        <?php endif; ?>
                    </h3>
                    
                    <?php if (empty($cart_details)): ?>
                        <div class="empty-state" style="padding: 30px 10px;">
                            <p>Din kurv er tom.</p>
                            <small style="color: var(--text-muted); display: block; margin-top: 10px;">Tilføj kaffe fra kataloget til venstre.</small>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 25px;">
                            <?php foreach ($cart_details as $item): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-light); padding-bottom: 10px;">
                                    <div style="flex: 1; padding-right: 10px;">
                                        <div style="font-size: 14px; font-weight: 500;"><?php echo htmlspecialchars($item['name']); ?></div>
                                        <?php if (!empty($item['variant_label']) || !empty($item['unit_label'])): ?>
                                            <div style="font-size: 12px; color: var(--primary); font-weight: 500;">
                                                <?php echo htmlspecialchars(trim($item['variant_label'] . ' ' . ($item['unit_label'] ? '· ' . $item['unit_label'] : ''))); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div style="font-size: 12px; color: var(--text-muted);">
                                            <?php echo htmlspecialchars($item['number']); ?>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <form action="katalog.php" method="POST" style="display: flex; align-items: center;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="cart_key" value="<?php echo htmlspecialchars($item['key']); ?>">
                                            <div class="qty-stepper">
                                                <button type="button" class="qty-btn" onclick="stepQty(this,-1)">−</button>
                                                <input type="number" name="quantity" value="<?php echo $item['qty']; ?>" min="0" class="qty-input" data-autosubmit="1">
                                                <button type="button" class="qty-btn" onclick="stepQty(this,1)">+</button>
                                            </div>
                                        </form>

                                        <form action="katalog.php" method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="cart_key" value="<?php echo htmlspecialchars($item['key']); ?>">
                                            <button type="submit" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 14px;" title="Fjern">❌</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="border-top: 1px solid var(--border-primary); padding-top: 15px; margin-bottom: 25px;">
                            <div style="display: flex; justify-content: space-between; font-size: 15px; color: var(--text-muted); margin-bottom: 8px;">
                                <span>Subtotal (vejl.)</span>
                                <span><?php echo number_format($cart_total, 2, ',', '.'); ?> kr.</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 16px; font-weight: 600; color: var(--primary);">
                                <span>Total pris aftale</span>
                                <span>Beregnes i næste trin</span>
                            </div>
                            <small style="color: var(--text-muted); display: block; margin-top: 8px; font-size: 12px; font-style: italic;">
                                Dine kontraktpriser og mængderabatter beregnes direkte i Business Central ved næste trin.
                            </small>
                        </div>
                        
                        <a href="ordre.php" class="btn btn-block" style="text-align: center; text-shadow: none;">
                            Gå til bestilling ➔
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <footer>
        &copy; <?php echo date('Y'); ?> Kaffeværkstedet ApS. Alle rettigheder forbeholdes.
    </footer>

    <script>
        // Justér antal via +/- knapper. Respekterer min, og sender formularen
        // automatisk hvis inputtet er markeret med data-autosubmit (kurv-opdatering).
        function stepQty(btn, delta) {
            var input = btn.parentNode.querySelector('input[type=number]');
            if (!input) return;
            var min = parseInt(input.getAttribute('min') || '0', 10);
            var val = parseInt(input.value || '0', 10) + delta;
            if (isNaN(val) || val < min) val = min;
            input.value = val;
            if (input.dataset.autosubmit === '1' && input.form) {
                input.form.submit();
            }
        }
    </script>

</body>
</html>
