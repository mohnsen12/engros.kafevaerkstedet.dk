<?php
/**
 * Login-side — Engros Bestillingsportal
 * 
 * Behandler login-anmodninger og præsenterer en sikker, smuk login-formular.
 */

require_once __DIR__ . '/auth.php';

// Hvis brugeren allerede er logget ind, send dem videre
if (validate_session()) {
    if ($_SESSION['brugernavn'] === 'admin') {
        header("Location: admin.php");
    } else {
        header("Location: katalog.php");
    }
    exit;
}

$fejl_besked = '';
$brugernavn = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brugernavn = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $fejl_besked = 'Ugyldig session (CSRF token validering fejlede). Prøv igen.';
    } elseif (empty($brugernavn) || empty($password)) {
        $fejl_besked = 'Udfyld venligst både brugernavn og adgangskode.';
    } else {
        $login_result = attempt_login($brugernavn, $password);
        if ($login_result['success']) {
            if ($brugernavn === 'admin') {
                header("Location: admin.php");
            } else {
                header("Location: katalog.php");
            }
            exit;
        } else {
            $fejl_besked = $login_result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log ind — Engros Bestillingsportal</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

    <div class="login-wrapper">
        <div class="card login-card animate-fade-in">
            
            <div class="login-header">
                <div class="logo" style="justify-content: center; font-size: 24px;">
                    ☕ <span>Kaffeværkstedet</span>
                </div>
                <h2 style="margin-top: 15px; font-weight: 500;">Bestillingsportal</h2>
                <p>Log ind for at bestille engros og administrere leveringer</p>
            </div>

            <?php if (!empty($fejl_besked)): ?>
                <div class="alert alert-danger">
                    <span>⚠️</span>
                    <div><?php echo htmlspecialchars($fejl_besked); ?></div>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                
                <div class="form-group">
                    <label for="username" class="form-label">Brugernavn</label>
                    <input type="text" id="username" name="username" class="form-control" 
                           value="<?php echo htmlspecialchars($brugernavn); ?>" 
                           placeholder="Indtast dit brugernavn" required autofocus>
                </div>
                
                <div class="form-group" style="margin-bottom: 30px;">
                    <label for="password" class="form-label">Adgangskode</label>
                    <input type="password" id="password" name="password" class="form-control" 
                           placeholder="••••••••" required>
                </div>
                
                <button type="submit" class="btn btn-block">
                    Log ind ➔
                </button>
            </form>
            
        </div>
    </div>

    <footer>
        &copy; <?php echo date('Y'); ?> Kaffeværkstedet ApS. Alle rettigheder forbeholdes.
    </footer>

</body>
</html>
