<?php
/**
 * Arc Studio Analytics Endpoint — DSGVO-konform
 * Kein IP-Tracking, keine Cookies, keine personenbezogenen Daten.
 *
 * POST /analytics.php
 * Body: {"page": "index.html", "lang": "de"}
 */

// ---------------------------------------------------------------------------
// Datenbank-Konfiguration (Platzhalter — nach DB-Erstellung befüllen)
// ---------------------------------------------------------------------------
define('DB_HOST', 'db5020447776.hosting-data.io');
define('DB_NAME', 'dbs15667754');
define('DB_USER', 'dbu1690689');
define('DB_PASS', 'Arc#Stats2026!');

// ---------------------------------------------------------------------------
// CORS — nur arc-studio.org erlaubt
// ---------------------------------------------------------------------------
$allowedOrigin = 'https://arc-studio.org';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
} else {
    // Kein CORS-Header gesetzt => Browser blockiert die Antwort bei cross-origin
    // Direktaufrufe vom eigenen Server funktionieren trotzdem
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// OPTIONS Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Immer JSON zurückgeben — nie Fehler leaken
function respondOk(): void
{
    echo '{"ok":true}';
    exit;
}

// ---------------------------------------------------------------------------
// Nur POST akzeptieren
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondOk();
}

// ---------------------------------------------------------------------------
// Bot-Filter: bekannte Crawler / Monitoring-Bots ausschließen
// ---------------------------------------------------------------------------
$botPatterns = [
    'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'adsbot',
    'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp',
    'googlebot', 'bingbot', 'yandexbot', 'duckduckbot', 'baiduspider',
    'sogou', 'exabot', 'ia_archiver', 'semrush', 'ahrefs', 'mj12bot',
    'dotbot', 'rogerbot', 'screaming', 'pingdom', 'uptimerobot',
    'headlesschrome', 'phantomjs', 'python-requests', 'curl/', 'wget/',
    'go-http-client', 'java/', 'libwww-perl', 'axios/',
];

$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '') {
    respondOk(); // Kein UA = wahrscheinlich Bot
}
foreach ($botPatterns as $pattern) {
    if (strpos($ua, $pattern) !== false) {
        respondOk();
    }
}

// ---------------------------------------------------------------------------
// Input parsen + validieren
// ---------------------------------------------------------------------------
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 512) {
    respondOk();
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    respondOk();
}

// page: nur alphanumerisch, Bindestrich, Punkt — max 100 Zeichen
$page = trim($data['page'] ?? '');
if ($page === '' || !preg_match('/^[a-zA-Z0-9_\-\.]{1,100}$/', $page)) {
    respondOk();
}

// lang: genau 2 Buchstaben
$lang = strtolower(trim($data['lang'] ?? 'de'));
if (!preg_match('/^[a-z]{2}$/', $lang)) {
    $lang = 'de';
}

// ---------------------------------------------------------------------------
// Rate-Limiting: max 1 Aufruf pro IP + Page pro 60 Sekunden
// Nutzt APCu wenn verfügbar, sonst tmp-File-Fallback.
// DSGVO-Note: Die IP wird NUR für das Rate-Limit genutzt, niemals gespeichert.
// ---------------------------------------------------------------------------
$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '';

// Nur den ersten Teil bei X-Forwarded-For nutzen
if (strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
}

// Anonymisierter Hash — IP selbst wird nicht gespeichert
$rateKey = 'arc_rl_' . hash('sha256', $ip . '|' . $page . '|' . date('Ymd'));
$rateTtl  = 60; // Sekunden

if (function_exists('apcu_fetch')) {
    // APCu-Variante
    $count = apcu_fetch($rateKey, $success);
    if ($success && (int)$count >= 1) {
        respondOk(); // Rate-Limit erreicht — still OK zurückgeben
    }
    apcu_store($rateKey, 1, $rateTtl);
} else {
    // Fallback: tmp-File
    $tmpDir  = sys_get_temp_dir();
    $tmpFile = $tmpDir . '/arc_rl_' . hash('sha256', $rateKey) . '.lock';
    $now     = time();

    if (file_exists($tmpFile)) {
        $ts = (int)file_get_contents($tmpFile);
        if (($now - $ts) < $rateTtl) {
            respondOk(); // Rate-Limit erreicht
        }
    }
    file_put_contents($tmpFile, $now, LOCK_EX);
}

// ---------------------------------------------------------------------------
// Datenbank-Verbindung + Tabelle sicherstellen
// ---------------------------------------------------------------------------
try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_NAME
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 3,
    ]);

    // Tabelle anlegen falls nicht vorhanden
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pageviews (
            id    INT AUTO_INCREMENT PRIMARY KEY,
            page  VARCHAR(100)      NOT NULL,
            lang  CHAR(2)           NOT NULL DEFAULT 'de',
            date  DATE              NOT NULL,
            hour  TINYINT UNSIGNED  NOT NULL,
            views INT UNSIGNED      NOT NULL DEFAULT 1,
            UNIQUE KEY uniq_slot (date, hour, page, lang),
            INDEX idx_date (date),
            INDEX idx_page (page)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Upsert: existierenden Slot inkrementieren oder neuen Slot anlegen
    $stmt = $pdo->prepare("
        INSERT INTO pageviews (page, lang, date, hour, views)
        VALUES (:page, :lang, CURDATE(), HOUR(NOW()), 1)
        ON DUPLICATE KEY UPDATE views = views + 1
    ");
    $stmt->execute([
        ':page' => $page,
        ':lang' => $lang,
    ]);

} catch (Throwable $e) {
    // Fehler niemals an Client leaken
    // Optional: error_log($e->getMessage()); — aktivieren für Server-Log
    respondOk();
}

respondOk();
