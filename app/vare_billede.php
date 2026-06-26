<?php
/**
 * Varebillede-proxy — Engros Bestillingsportal
 *
 * Henter en vares billede fra Business Central (kræver token), cacher det på disk,
 * og serverer det til kataloget. Returnerer 404 hvis varen ikke har et billede,
 * så <img onerror> kan skjule billedet pænt.
 *
 * Brug: <img src="vare_billede.php?id=<vare-GUID>">
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bc_api.php';

require_login();

$id = $_GET['id'] ?? '';
// Kun gyldigt GUID-format tillades
if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id)) {
    http_response_code(400);
    exit;
}

$cache_dir = __DIR__ . '/billede_cache';
if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0775, true);
}
$img_file  = "$cache_dir/$id.img";
$none_file = "$cache_dir/$id.none";
$lifetime  = 86400; // 24 timer

/** Gæt billedtype ud fra de første bytes og send billedet. */
function send_image($bytes) {
    $mime = 'image/png';
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0)                       $mime = 'image/jpeg';
    elseif (strncmp($bytes, "GIF8", 4) === 0)                            $mime = 'image/gif';
    elseif (substr($bytes, 0, 4) === "RIFF" && substr($bytes, 8, 4) === "WEBP") $mime = 'image/webp';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400');
    echo $bytes;
    exit;
}

// 1. Frisk billede i cache?
if (is_file($img_file) && (time() - filemtime($img_file) < $lifetime)) {
    send_image(file_get_contents($img_file));
}
// 2. Vi ved allerede (frisk), at varen intet billede har?
if (is_file($none_file) && (time() - filemtime($none_file) < $lifetime)) {
    http_response_code(404);
    exit;
}

// 3. Hent fra Business Central
$res = bc_get_item_picture($id);
if ($res['code'] === 200 && strlen($res['body']) > 0) {
    @file_put_contents($img_file, $res['body']);
    send_image($res['body']);
} else {
    // Intet billede (typisk HTTP 204) — husk det, så vi ikke spørger BC hver gang
    @touch($none_file);
    http_response_code(404);
    exit;
}
