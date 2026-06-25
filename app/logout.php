<?php
/**
 * Logout — Engros Bestillingsportal
 * 
 * Logger brugeren ud af portalen og rydder sessionen.
 */

require_once __DIR__ . '/auth.php';

// Log ud
auth_logout();

// Omdiriger til login
header("Location: login.php");
exit;
