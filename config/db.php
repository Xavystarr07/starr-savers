<?php
declare(strict_types=1);

/**
 * Starr Savers — /config/db.php
 * ---------------------------------------------------------------------------
 * PDO database connection (MySQL / MariaDB) for InfinityFree shared hosting.
 *
 * USAGE (from any page or API file):
 *     require_once __DIR__ . '/../config/db.php';   // adjust the path
 *     $pdo = db();
 *     $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
 *     $stmt->execute([':id' => $id]);
 *
 * SETUP:
 *   1. Replace the placeholder credentials below with the ones shown in your
 *      InfinityFree control panel (MySQL Databases). The host is NOT localhost —
 *      it looks like sql123.infinityfree.com.
 *   2. Better: create /config/db.local.php (same folder) that returns an array
 *      with the keys host, name, user, pass. It overrides the values here and
 *      can be excluded from version control.
 *   3. Protect this folder from the web with /config/.htaccess containing:
 *          Require all denied
 *      (On older Apache: "Order allow,deny" + "Deny from all".)
 */

// Never let this file be requested directly.
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------------
// 1. CREDENTIALS
// ---------------------------------------------------------------------------
$starrDbConfig = [
    'host' => 'sqlXXX.infinityfree.com',   // <-- from the InfinityFree panel
    'name' => 'if0_XXXXXXXX_starrsavers',   // <-- database name
    'user' => 'if0_XXXXXXXX',               // <-- database username
    'pass' => 'CHANGE_ME',                  // <-- database password
    'port' => 3306,
];

$starrLocalConfig = __DIR__ . '/db.local.php';
if (is_file($starrLocalConfig)) {
    $override = require $starrLocalConfig;
    if (is_array($override)) {
        $starrDbConfig = array_merge($starrDbConfig, $override);
    }
}

if (!defined('STARR_DB_HOST')) { define('STARR_DB_HOST', (string) $starrDbConfig['host']); }
if (!defined('STARR_DB_NAME')) { define('STARR_DB_NAME', (string) $starrDbConfig['name']); }
if (!defined('STARR_DB_USER')) { define('STARR_DB_USER', (string) $starrDbConfig['user']); }
if (!defined('STARR_DB_PASS')) { define('STARR_DB_PASS', (string) $starrDbConfig['pass']); }
if (!defined('STARR_DB_PORT')) { define('STARR_DB_PORT', (int) $starrDbConfig['port']); }
unset($starrDbConfig, $starrLocalConfig, $override);

// ---------------------------------------------------------------------------
// 2. APPLICATION-WIDE DEFAULTS
// ---------------------------------------------------------------------------
date_default_timezone_set('Africa/Johannesburg');   // SAST, UTC+2, no daylight saving
mb_internal_encoding('UTF-8');

// Set to true ONLY while developing. In production errors go to the log, never the browser.
if (!defined('STARR_DEBUG')) { define('STARR_DEBUG', false); }

ini_set('display_errors', STARR_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------------------
// 3. CONNECTION (lazy singleton)
// ---------------------------------------------------------------------------

/**
 * Returns the shared PDO connection, creating it on first use.
 * On failure it logs the real reason and sends a safe, generic response.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        STARR_DB_HOST,
        STARR_DB_PORT,
        STARR_DB_NAME
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // throw on every SQL error
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,                    // real prepared statements
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::ATTR_TIMEOUT            => 8,                        // seconds
    ];

    try {
        $pdo = new PDO($dsn, STARR_DB_USER, STARR_DB_PASS, $options);
        // Keep MySQL timestamps in South African time to match PHP.
        $pdo->exec("SET time_zone = '+02:00'");
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        starr_db_fail($e);
    }

    return $pdo;
}

/**
 * Logs a database failure and ends the request with a friendly message.
 * Returns JSON for API / AJAX calls and a simple HTML page for normal pages.
 */
function starr_db_fail(Throwable $e): void
{
    error_log('[StarrSavers][DB] ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 30');
    }

    $message = 'We could not reach our store right now. Please try again in a moment.';
    $detail  = STARR_DEBUG ? $e->getMessage() : null;

    $wantsJson =
        (isset($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['SCRIPT_NAME']) && str_contains((string) $_SERVER['SCRIPT_NAME'], '/php/'));

    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $message, 'debug' => $detail], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeDetail  = $detail !== null ? '<pre style="white-space:pre-wrap;color:#b91c1c">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>' : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Starr Savers — Back soon</title></head>'
       . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#F4F7FB;'
       . 'font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#0B1F44;text-align:center;padding:24px">'
       . '<div style="max-width:420px"><h1 style="color:#0B3D91;margin:0 0 8px">Back in a moment</h1>'
       . '<p style="margin:0 0 16px">' . $safeMessage . '</p>' . $safeDetail
       . '<a href="/" style="display:inline-block;background:#1E64F0;color:#fff;text-decoration:none;'
       . 'padding:12px 20px;border-radius:12px;font-weight:600">Reload</a></div></body></html>';
    exit;
}

// ---------------------------------------------------------------------------
// 4. SMALL HELPERS USED ACROSS THE SITE
// ---------------------------------------------------------------------------

/**
 * Reads a value from site_settings (cached per request).
 * Pass no key to receive every setting as an associative array.
 *
 * @return string|array|null
 */
function setting(?string $key = null, ?string $default = null)
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            error_log('[StarrSavers][settings] ' . $e->getMessage());
        }
    }

    if ($key === null) {
        return $cache;
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null ? $cache[$key] : $default;
}

/** Formats a number as South African Rand, e.g. R1 299.99 */
function zar(float|int|string $amount): string
{
    return 'R' . number_format((float) $amount, 2, '.', ' ');
}

/** Escapes text for safe HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
