<?php
/**
 * Arc Studio — Kontaktformular Backend
 * Empfängt JSON POST-Requests und sendet E-Mails via PHP mail().
 */

// ── CORS ─────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: https://arc-studio.org');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Nur POST erlauben ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// ── JSON Body lesen ───────────────────────────────────────────
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// ── Honeypot-Schutz ───────────────────────────────────────────
if (!empty($data['website'])) {
    // Stiller Erfolg für Bots
    echo json_encode(['ok' => true]);
    exit;
}

// ── Felder extrahieren & sanitisieren ─────────────────────────
$name    = isset($data['name'])    ? trim((string) $data['name'])    : '';
$email   = isset($data['email'])   ? trim((string) $data['email'])   : '';
$message = isset($data['message']) ? trim((string) $data['message']) : '';

// ── Validierung ───────────────────────────────────────────────
$errors = [];

if ($name === '') {
    $errors[] = 'Name ist erforderlich.';
} elseif (mb_strlen($name) > 100) {
    $errors[] = 'Name darf maximal 100 Zeichen lang sein.';
}

if ($email === '') {
    $errors[] = 'E-Mail-Adresse ist erforderlich.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'E-Mail-Adresse ist ungültig.';
} elseif (mb_strlen($email) > 254) {
    $errors[] = 'E-Mail-Adresse ist zu lang.';
}

if ($message === '') {
    $errors[] = 'Nachricht ist erforderlich.';
} elseif (mb_strlen($message) > 2000) {
    $errors[] = 'Nachricht darf maximal 2000 Zeichen lang sein.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ── Rate-Limiting ─────────────────────────────────────────────
$ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$safeIp    = preg_replace('/[^a-zA-Z0-9._:-]/', '_', $ip);
$rlFile    = '/tmp/arc_rl_' . $safeIp . '.txt';
$maxPerHr  = 3;
$window    = 3600; // 1 Stunde in Sekunden
$now       = time();

$timestamps = [];
if (file_exists($rlFile)) {
    $lines = file($rlFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $ts = (int) $line;
        if ($ts > ($now - $window)) {
            $timestamps[] = $ts;
        }
    }
}

if (count($timestamps) >= $maxPerHr) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Zu viele Anfragen. Bitte versuche es in einer Stunde erneut.']);
    exit;
}

$timestamps[] = $now;
file_put_contents($rlFile, implode("\n", $timestamps) . "\n", LOCK_EX);

// ── E-Mail versenden ──────────────────────────────────────────
$to      = 'arc-studio@arc-studio.org';
$subject = '=?UTF-8?B?' . base64_encode('Neue Kontaktanfrage von ' . $name) . '?=';

// Felder für die Mail absichern (Header-Injection verhindern)
$safeName  = str_replace(["\r", "\n"], '', $name);
$safeEmail = filter_var($email, FILTER_SANITIZE_EMAIL);

$body  = "Name:    {$safeName}\n";
$body .= "E-Mail:  {$safeEmail}\n";
$body .= "IP:      {$ip}\n";
$body .= "Zeit:    " . date('Y-m-d H:i:s T') . "\n";
$body .= "\n--- Nachricht ---\n\n";
$body .= $message . "\n";

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "From: noreply@arc-studio.org\r\n";
$headers .= "Reply-To: {$safeEmail}\r\n";
$headers .= "X-Mailer: Arc-Studio-Feedback/1.0\r\n";

$sent = mail($to, $subject, $body, $headers);

if (!$sent) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'E-Mail konnte nicht gesendet werden. Bitte versuche es später erneut.']);
    exit;
}

// ── Erfolg ────────────────────────────────────────────────────
http_response_code(200);
echo json_encode(['ok' => true]);
