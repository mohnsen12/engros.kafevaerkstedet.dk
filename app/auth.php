<?php
/**
 * Sikkerhed og Autentificering — Engros Bestillingsportal
 * 
 * Håndterer sessions, login, logout, brute-force beskyttelse (lockout) og CSRF-validering.
 * Denne fil er standalone og deler IKKE kode med andre projekter.
 */

require_once __DIR__ . '/db.php';

// Start session med sikre indstillinger (hvis den ikke allerede er startet)
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 0,
        'cookie_path'     => '/',
        'cookie_secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax'
    ]);
}

/**
 * Henter klientens sande IP-adresse (tager højde for Cloudflare / proxy).
 */
function get_client_ip() {
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Kontrollerer om den aktuelle IP er låst ude pga. for mange fejlslagne login-forsøg.
 */
function is_ip_locked_out($brugernavn) {
    global $db;
    $ip = get_client_ip();
    $cutoff = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_SEKUNDER);
    
    // Tjek antal fejlslagne forsøg i træk uden et succesfuldt forsøg indimellem
    $stmt = $db->prepare("
        SELECT COUNT(*) as fejl_antal FROM login_forsog 
        WHERE ip_adresse = :ip 
          AND tidspunkt > :cutoff 
          AND succes = 0
    ");
    $stmt->execute([':ip' => $ip, ':cutoff' => $cutoff]);
    $res = $stmt->fetch();
    
    return ($res['fejl_antal'] >= MAX_LOGIN_FORSOG);
}

/**
 * Registrerer et loginforsøg (succes eller fiasko).
 */
function log_login_forsog($brugernavn, $succes) {
    global $db;
    $ip = get_client_ip();
    $stmt = $db->prepare("
        INSERT INTO login_forsog (ip_adresse, brugernavn, succes) 
        VALUES (:ip, :username, :success)
    ");
    $stmt->execute([
        ':ip'       => $ip,
        ':username' => $brugernavn,
        ':success'  => $succes ? 1 : 0
    ]);
}

/**
 * Udfører login af en bruger.
 */
function attempt_login($brugernavn, $password) {
    global $db;
    
    if (is_ip_locked_out($brugernavn)) {
        return ['success' => false, 'error' => 'For mange mislykkede loginforsøg. Din IP er spærret i 15 minutter.'];
    }
    
    $stmt = $db->prepare("SELECT * FROM brugere WHERE brugernavn = :username AND aktiv = 1 LIMIT 1");
    $stmt->execute([':username' => $brugernavn]);
    $bruger = $stmt->fetch();
    
    if ($bruger && password_verify($password, $bruger['password_hash'])) {
        // Login succes
        log_login_forsog($brugernavn, true);
        
        // Forebyg session fixation: generer nyt session id
        session_regenerate_id(true);
        
        // Gem brugeroplysninger i session
        $_SESSION['bruger_id']     = $bruger['id'];
        $_SESSION['brugernavn']    = $bruger['brugernavn'];
        $_SESSION['bc_kunde_nr']   = $bruger['bc_kunde_nr'];
        $_SESSION['bc_kunde_id']   = $bruger['bc_kunde_id'];
        $_SESSION['firma_navn']    = $bruger['firma_navn'];
        $_SESSION['sidste_aktiv']  = time();
        
        // Generer CSRF token hvis ikke eksisterer
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return ['success' => true];
    } else {
        // Login fiasko
        log_login_forsog($brugernavn, false);
        return ['success' => false, 'error' => 'Ugyldigt brugernavn eller password.'];
    }
}

/**
 * Sikrer at sessionen ikke er forældet (inaktivitet timeout).
 */
function validate_session() {
    if (!isset($_SESSION['bruger_id'])) {
        return false;
    }
    
    if (time() - $_SESSION['sidste_aktiv'] > SESSION_TIMEOUT) {
        // Session timeout
        auth_logout();
        return false;
    }
    
    // Opdater sidste aktivitetstidspunkt
    $_SESSION['sidste_aktiv'] = time();
    return true;
}

/**
 * Kræver at brugeren er logget ind (bruges på alle beskyttede sider).
 */
function require_login() {
    if (!validate_session()) {
        header("Location: login.php");
        exit;
    }
}

/**
 * Kræver at admin er logget ind. Admin er brugeren med brugernavn 'admin'.
 */
function require_admin() {
    require_login();
    if ($_SESSION['brugernavn'] !== 'admin') {
        http_response_code(403);
        die("Adgang nægtet: Kræver administratorrettigheder.");
    }
}

/**
 * Foretager logout af den aktuelle bruger.
 */
function auth_logout() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Opretter eller returnerer det eksisterende CSRF token.
 */
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verificerer at et modtaget CSRF token er gyldigt.
 */
function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
