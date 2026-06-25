<?php
/**
 * Databaseforbindelse og Migreringer — Engros Bestillingsportal
 * 
 * Etablerer forbindelse til SQLite databasen og sikrer, at tabellerne eksisterer.
 */

require_once __DIR__ . '/config.php';

try {
    // Forbind til SQLite (opretter filen hvis den ikke findes)
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Aktiver foreign keys for god dataintegritet
    $db->exec('PRAGMA foreign_keys = ON;');

    // ─── Auto-migrering: Opret tabeller ──────────────────────────────────────
    
    // 1. Engros-brugere
    $db->exec("CREATE TABLE IF NOT EXISTS brugere (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        brugernavn      TEXT UNIQUE NOT NULL,
        password_hash   TEXT NOT NULL,
        bc_kunde_nr     TEXT NOT NULL,
        bc_kunde_id     TEXT DEFAULT '',
        firma_navn      TEXT DEFAULT '',
        aktiv           INTEGER DEFAULT 1,
        oprettet        DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // Index på brugernavn for hurtig login-søgning
    $db->exec("CREATE INDEX IF NOT EXISTS idx_brugere_brugernavn ON brugere(brugernavn);");

    // 2. Lokal ordrelog
    $db->exec("CREATE TABLE IF NOT EXISTS ordre_log (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        bruger_id       INTEGER NOT NULL,
        bc_faktura_nr   TEXT DEFAULT '',
        bc_faktura_id   TEXT DEFAULT '',
        lever_til_type  TEXT DEFAULT '',     -- 'gemt' | 'engang'
        lever_til_navn  TEXT DEFAULT '',
        lever_til_json  TEXT DEFAULT '',     -- fuld adresse som JSON
        linjer_json     TEXT DEFAULT '',     -- ordrelinjer som JSON
        total_beloeb    REAL DEFAULT 0,
        status          TEXT DEFAULT 'oprettet',
        oprettet        DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (bruger_id) REFERENCES brugere(id) ON DELETE CASCADE
    );");

    // 3. Login-forsøg (Brute-force beskyttelse)
    $db->exec("CREATE TABLE IF NOT EXISTS login_forsog (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_adresse      TEXT NOT NULL,
        brugernavn      TEXT DEFAULT '',
        succes          INTEGER DEFAULT 0,
        tidspunkt       DATETIME DEFAULT CURRENT_TIMESTAMP
    );");
    
    // Index på ip + tidspunkt for brute force kontrol
    $db->exec("CREATE INDEX IF NOT EXISTS idx_login_forsog_ip_tid ON login_forsog(ip_adresse, tidspunkt);");

    // 4. Vareadgang — styrer hvilke BC-varer den enkelte bruger må se i kataloget.
    //    Én række pr. (bruger, varenummer). Ingen række = varen er skjult for brugeren.
    $db->exec("CREATE TABLE IF NOT EXISTS bruger_vareadgang (
        bruger_id    INTEGER NOT NULL,
        item_number  TEXT NOT NULL,
        PRIMARY KEY (bruger_id, item_number),
        FOREIGN KEY (bruger_id) REFERENCES brugere(id) ON DELETE CASCADE
    );");

    // Index for hurtigt opslag af en brugers tilladte varer
    $db->exec("CREATE INDEX IF NOT EXISTS idx_vareadgang_bruger ON bruger_vareadgang(bruger_id);");

    // Auto-opret standard admin hvis ingen brugere findes
    $check_users = $db->query("SELECT COUNT(*) as count FROM brugere")->fetch();
    if ($check_users['count'] == 0) {
        $default_pass = 'admin123';
        $hash = password_hash($default_pass, PASSWORD_BCRYPT);
        $stmt = $db->prepare("
            INSERT INTO brugere (brugernavn, password_hash, bc_kunde_nr, bc_kunde_id, firma_navn, aktiv)
            VALUES ('admin', :hash, 'ADMIN', 'ADMIN', 'Administrator', 1)
        ");
        $stmt->execute([':hash' => $hash]);
    }

} catch (PDOException $e) {
    die("Databasefejl: " . $e->getMessage());
}
