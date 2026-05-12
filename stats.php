<?php
/**
 * Arc Studio Analytics Dashboard
 * Passwortgeschützt via HTTP Basic Auth.
 * Dark-Theme, passt zu arc-studio.org.
 */

// ---------------------------------------------------------------------------
// Datenbank-Konfiguration (identisch zu analytics.php)
// ---------------------------------------------------------------------------
define('DB_HOST', 'db5020447776.hosting-data.io');
define('DB_NAME', 'dbs15667754');
define('DB_USER', 'dbu1690689');
define('DB_PASS', 'Arc#Stats2026!');

// ---------------------------------------------------------------------------
// HTTP Basic Auth
// ---------------------------------------------------------------------------
define('AUTH_USER', 'admin');
define('AUTH_PASS', 'arcstudio2026');

$authUser = $_SERVER['PHP_AUTH_USER'] ?? '';
$authPass = $_SERVER['PHP_AUTH_PW']   ?? '';

if (
    !hash_equals(AUTH_USER, $authUser) ||
    !hash_equals(AUTH_PASS, $authPass)
) {
    header('WWW-Authenticate: Basic realm="Arc Studio Stats"');
    header('HTTP/1.1 401 Unauthorized');
    echo '<!DOCTYPE html><html><body style="background:#0a0a0f;color:#f0f0f5;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0"><p>401 Unauthorized</p></body></html>';
    exit;
}

// ---------------------------------------------------------------------------
// Seitennamen-Mapping
// ---------------------------------------------------------------------------
function pageName(string $page): string
{
    $map = [
        'index.html'                  => 'Homepage',
        'voltiq.html'                 => 'VOLTIQ',
        'VOLTECH-ampere.html'         => 'AMPERE',
        'VOLTECH-torque.html'         => 'TORQUE',
        'VOLTECH-lern-basis.html'     => 'Lernbasis',
        'VOLTECH-berichtsheft.html'   => 'Berichtsheft',
        'VOLTECH-mechatronik-pro.html'=> 'Mechatronik Pro',
        'VOLTECH-sps.html'            => 'SPS-Trainer',
        'VOLTECH-tools2.html'         => 'Lerntools',
        'VOLTECH-ki.html'             => 'KI-Assistent',
        'VOLTECH-suite.html'          => 'Suite',
        '404.html'                    => '404-Seite',
    ];
    return $map[$page] ?? htmlspecialchars($page, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Datenbank-Verbindung
// ---------------------------------------------------------------------------
$error = null;
$pdo   = null;

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_NAME
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
} catch (Throwable $e) {
    $error = 'Datenbankverbindung fehlgeschlagen.';
}

// ---------------------------------------------------------------------------
// Daten abrufen
// ---------------------------------------------------------------------------
$today     = [];
$yesterday = [];
$week      = [];
$month     = [];
$topPages  = [];
$dailyData = [];
$langData  = [];
$totalAll  = 0;

if ($pdo !== null) {
    try {
        // Tabelle anlegen falls noch nicht vorhanden
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

        // Heute
        $stmt = $pdo->query("SELECT COALESCE(SUM(views),0) AS v FROM pageviews WHERE date = CURDATE()");
        $today = (int)($stmt->fetchColumn() ?: 0);

        // Gestern
        $stmt = $pdo->query("SELECT COALESCE(SUM(views),0) AS v FROM pageviews WHERE date = CURDATE() - INTERVAL 1 DAY");
        $yesterday = (int)($stmt->fetchColumn() ?: 0);

        // Diese Woche (Mo–heute)
        $stmt = $pdo->query("SELECT COALESCE(SUM(views),0) AS v FROM pageviews WHERE date >= DATE(DATE_SUB(NOW(), INTERVAL WEEKDAY(NOW()) DAY))");
        $week = (int)($stmt->fetchColumn() ?: 0);

        // Dieser Monat
        $stmt = $pdo->query("SELECT COALESCE(SUM(views),0) AS v FROM pageviews WHERE date >= DATE_FORMAT(NOW(),'%Y-%m-01')");
        $month = (int)($stmt->fetchColumn() ?: 0);

        // Top-Seiten (letzte 30 Tage)
        $stmt = $pdo->query("
            SELECT page, SUM(views) AS total
            FROM pageviews
            WHERE date >= CURDATE() - INTERVAL 30 DAY
            GROUP BY page
            ORDER BY total DESC
            LIMIT 20
        ");
        $topPages = $stmt->fetchAll();

        // Tagesverlauf: letzte 30 Tage
        $stmt = $pdo->query("
            SELECT date, SUM(views) AS total
            FROM pageviews
            WHERE date >= CURDATE() - INTERVAL 29 DAY
            GROUP BY date
            ORDER BY date ASC
        ");
        $dailyData = $stmt->fetchAll();

        // Sprachverteilung (letzte 30 Tage)
        $stmt = $pdo->query("
            SELECT lang, SUM(views) AS total
            FROM pageviews
            WHERE date >= CURDATE() - INTERVAL 30 DAY
            GROUP BY lang
            ORDER BY total DESC
        ");
        $langData = $stmt->fetchAll();

        // Gesamtaufrufe
        $stmt = $pdo->query("SELECT COALESCE(SUM(views),0) FROM pageviews WHERE date >= CURDATE() - INTERVAL 30 DAY");
        $totalAll = (int)($stmt->fetchColumn() ?: 0);

    } catch (Throwable $e) {
        $error = 'Datenbankabfrage fehlgeschlagen.';
    }
}

// ---------------------------------------------------------------------------
// Balken-Chart: maximalen Tageswert ermitteln
// ---------------------------------------------------------------------------
$maxDaily = 1;
foreach ($dailyData as $row) {
    if ((int)$row['total'] > $maxDaily) {
        $maxDaily = (int)$row['total'];
    }
}

// Letzte 30 Tage als Index (für lückenlose Darstellung)
$chartDays = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartDays[$d] = 0;
}
foreach ($dailyData as $row) {
    if (isset($chartDays[$row['date']])) {
        $chartDays[$row['date']] = (int)$row['total'];
    }
}

?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arc Studio — Analytics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:       #0a0a0f;
            --surface:  #12121a;
            --border:   #1e1e2e;
            --accent:   #6366f1;
            --accent2:  #818cf8;
            --text:     #f0f0f5;
            --muted:    #8888aa;
            --success:  #22c55e;
            --warn:     #f59e0b;
            --danger:   #ef4444;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 14px;
            line-height: 1.6;
            min-height: 100vh;
        }

        /* ---- Layout ---- */
        .wrapper {
            max-width: 1100px;
            margin: 0 auto;
            padding: 32px 20px 64px;
        }

        /* ---- Header ---- */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 40px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }
        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-logo {
            width: 36px;
            height: 36px;
            background: var(--accent);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .header-logo svg { display: block; }
        .header h1 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text);
        }
        .header-sub {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }
        .header-badge {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--muted);
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 20px;
        }

        /* ---- Error ---- */
        .alert {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 32px;
            font-size: 13px;
        }

        /* ---- Summary Cards ---- */
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 40px;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            transition: border-color 0.2s;
        }
        .card:hover { border-color: var(--accent); }
        .card-label {
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin-bottom: 10px;
        }
        .card-value {
            font-size: 34px;
            font-weight: 700;
            color: var(--accent);
            line-height: 1;
        }
        .card-sub {
            font-size: 11px;
            color: var(--muted);
            margin-top: 6px;
        }

        /* ---- Section Titles ---- */
        .section-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 16px;
        }

        /* ---- Chart ---- */
        .chart-section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 32px;
        }
        .chart-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 3px;
            height: 120px;
            width: 100%;
            overflow-x: auto;
        }
        .chart-bar-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            min-width: 20px;
            height: 100%;
            justify-content: flex-end;
        }
        .chart-bar {
            width: 100%;
            background: var(--accent);
            border-radius: 3px 3px 0 0;
            opacity: 0.85;
            transition: opacity 0.2s;
            min-height: 2px;
            cursor: default;
            position: relative;
        }
        .chart-bar:hover { opacity: 1; }
        .chart-bar-today {
            background: var(--accent2);
        }
        .chart-tooltip {
            display: none;
            position: absolute;
            bottom: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            background: #1e1e2e;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 11px;
            white-space: nowrap;
            z-index: 10;
        }
        .chart-bar:hover .chart-tooltip { display: block; }
        .chart-labels {
            display: flex;
            gap: 3px;
            margin-top: 6px;
        }
        .chart-label-item {
            flex: 1;
            min-width: 20px;
            text-align: center;
            font-size: 9px;
            color: var(--muted);
            overflow: hidden;
        }

        /* ---- Grid (Top Pages + Lang) ---- */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 24px;
            margin-bottom: 32px;
        }
        @media (max-width: 768px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

        /* ---- Table ---- */
        .table-section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }
        .table-header {
            padding: 20px 20px 16px;
            border-bottom: 1px solid var(--border);
        }
        .table-title {
            font-size: 15px;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead th {
            padding: 10px 20px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            background: rgba(255,255,255,0.02);
            border-bottom: 1px solid var(--border);
        }
        tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background 0.15s;
        }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: rgba(255,255,255,0.03); }
        tbody td {
            padding: 12px 20px;
            font-size: 13px;
        }
        .rank {
            color: var(--muted);
            font-size: 11px;
            width: 32px;
        }
        .page-name {
            font-weight: 500;
        }
        .views-cell {
            text-align: right;
            font-weight: 600;
            color: var(--accent2);
        }
        .bar-cell { width: 140px; }
        .mini-bar-bg {
            background: rgba(99, 102, 241, 0.1);
            border-radius: 3px;
            height: 6px;
            overflow: hidden;
        }
        .mini-bar-fill {
            background: var(--accent);
            height: 100%;
            border-radius: 3px;
        }

        /* ---- Lang Distribution ---- */
        .lang-section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
        }
        .lang-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .lang-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }
        .lang-item:last-child { margin-bottom: 0; }
        .lang-code {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text);
            width: 24px;
        }
        .lang-bar-bg {
            flex: 1;
            background: rgba(99, 102, 241, 0.1);
            border-radius: 4px;
            height: 8px;
            overflow: hidden;
        }
        .lang-bar-fill {
            background: var(--accent);
            height: 100%;
            border-radius: 4px;
        }
        .lang-count {
            font-size: 12px;
            color: var(--muted);
            width: 48px;
            text-align: right;
        }

        /* ---- Empty State ---- */
        .empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        /* ---- Footer ---- */
        .footer {
            margin-top: 48px;
            padding-top: 24px;
            border-top: 1px solid var(--border);
            text-align: center;
            font-size: 11px;
            color: var(--muted);
        }
        .footer a {
            color: var(--accent);
            text-decoration: none;
        }
        .footer a:hover { text-decoration: underline; }

        .tag {
            display: inline-block;
            background: rgba(99, 102, 241, 0.12);
            color: var(--accent2);
            font-size: 10px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 4px;
            margin-left: 6px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
    </style>
</head>
<body>
<div class="wrapper">

    <!-- Header -->
    <div class="header">
        <div class="header-brand">
            <div class="header-logo">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M11 3L5 11H9L7 17L15 9H11L13 3Z" fill="white" stroke="white" stroke-width="0.5" stroke-linejoin="round"/>
                </svg>
            </div>
            <div>
                <h1>Arc Studio <span class="tag">Analytics</span></h1>
                <div class="header-sub">Letzte 30 Tage — DSGVO-konform, kein Cookie-Tracking</div>
            </div>
        </div>
        <div class="header-badge">
            <?= date('d.m.Y, H:i') ?> Uhr
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        — Stelle sicher, dass die Datenbank-Konstanten oben in stats.php korrekt befüllt sind.
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="cards">
        <div class="card">
            <div class="card-label">Heute</div>
            <div class="card-value"><?= number_format($today, 0, ',', '.') ?></div>
            <div class="card-sub">Seitenaufrufe</div>
        </div>
        <div class="card">
            <div class="card-label">Gestern</div>
            <div class="card-value"><?= number_format($yesterday, 0, ',', '.') ?></div>
            <div class="card-sub">Seitenaufrufe</div>
        </div>
        <div class="card">
            <div class="card-label">Diese Woche</div>
            <div class="card-value"><?= number_format($week, 0, ',', '.') ?></div>
            <div class="card-sub">Mo bis heute</div>
        </div>
        <div class="card">
            <div class="card-label">Dieser Monat</div>
            <div class="card-value"><?= number_format($month, 0, ',', '.') ?></div>
            <div class="card-sub"><?= date('F Y') ?></div>
        </div>
        <div class="card">
            <div class="card-label">30-Tage-Total</div>
            <div class="card-value"><?= number_format($totalAll, 0, ',', '.') ?></div>
            <div class="card-sub">Alle Seiten</div>
        </div>
    </div>

    <!-- Tages-Chart -->
    <div class="chart-section">
        <div class="chart-title">Tagesverlauf — letzte 30 Tage</div>
        <?php if (empty($chartDays) || $totalAll === 0): ?>
            <div class="empty">Noch keine Daten vorhanden.</div>
        <?php else: ?>
            <div class="chart-bars">
                <?php
                $todayStr = date('Y-m-d');
                foreach ($chartDays as $d => $v):
                    $pct    = $maxDaily > 0 ? max(2, round(($v / $maxDaily) * 100)) : 2;
                    $isToday = ($d === $todayStr);
                    $label  = date('d.m', strtotime($d));
                ?>
                <div class="chart-bar-wrap">
                    <div class="chart-bar <?= $isToday ? 'chart-bar-today' : '' ?>"
                         style="height:<?= $pct ?>%">
                        <span class="chart-tooltip"><?= $label ?>: <?= number_format($v, 0, ',', '.') ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="chart-labels">
                <?php
                $i = 0;
                foreach ($chartDays as $d => $v):
                    $show = ($i % 5 === 0 || $d === $todayStr);
                ?>
                <div class="chart-label-item"><?= $show ? date('d.', strtotime($d)) : '' ?></div>
                <?php $i++; endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Top Pages + Sprachverteilung -->
    <div class="grid-2">

        <!-- Top Pages -->
        <div class="table-section">
            <div class="table-header">
                <div class="table-title">Top-Seiten <span class="tag">30 Tage</span></div>
            </div>
            <?php if (empty($topPages)): ?>
                <div class="empty">Noch keine Daten vorhanden.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:32px">#</th>
                            <th>Seite</th>
                            <th style="width:100px">Aufrufe</th>
                            <th class="bar-cell">Anteil</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $maxViews = (int)($topPages[0]['total'] ?? 1);
                        foreach ($topPages as $rank => $row):
                            $pct = $maxViews > 0 ? round(($row['total'] / $maxViews) * 100) : 0;
                        ?>
                        <tr>
                            <td class="rank"><?= $rank + 1 ?></td>
                            <td class="page-name"><?= pageName($row['page']) ?></td>
                            <td class="views-cell"><?= number_format((int)$row['total'], 0, ',', '.') ?></td>
                            <td class="bar-cell">
                                <div class="mini-bar-bg">
                                    <div class="mini-bar-fill" style="width:<?= $pct ?>%"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Sprachverteilung -->
        <div class="lang-section">
            <div class="lang-title">Sprachverteilung</div>
            <?php if (empty($langData)): ?>
                <div class="empty">Keine Daten.</div>
            <?php else:
                $langTotal = array_sum(array_column($langData, 'total'));
                foreach ($langData as $row):
                    $pct = $langTotal > 0 ? round(($row['total'] / $langTotal) * 100) : 0;
                ?>
                <div class="lang-item">
                    <span class="lang-code"><?= htmlspecialchars(strtoupper($row['lang']), ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="lang-bar-bg">
                        <div class="lang-bar-fill" style="width:<?= $pct ?>%"></div>
                    </div>
                    <span class="lang-count"><?= number_format((int)$row['total'], 0, ',', '.') ?></span>
                    <span class="lang-count" style="color:#6366f1;font-weight:600"><?= $pct ?>%</span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <hr style="border:none;border-top:1px solid var(--border);margin:24px 0">

            <div style="font-size:11px;color:var(--muted);line-height:1.7">
                <strong style="color:var(--text)">Datenschutz-Hinweis</strong><br>
                Kein Cookie-Tracking &middot; Keine IP-Speicherung &middot; Keine personenbezogenen Daten &middot; DSGVO-konform
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        Arc Studio Analytics &mdash; <a href="https://arc-studio.org" target="_blank" rel="noopener">arc-studio.org</a>
        &nbsp;&middot;&nbsp; Nur intern zugänglich (HTTP Basic Auth)
        &nbsp;&middot;&nbsp; Daten: letzte 30 Tage
    </div>

</div>
</body>
</html>
