<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/auth-handler.php
 * ---------------------------------------------------------------------------
 * Accounts for customers (and admin login).
 *
 *   GET  ?action=csrf             -> {ok, csrf, user}
 *   GET  ?action=me               -> {ok, user}
 *   POST action=register          full_name, email, phone, password, popia_consent, marketing_opt_in
 *   POST action=login             email, password
 *   POST action=logout
 *   POST action=forgot            email
 *   POST action=reset             email, token, password
 *   POST action=update_profile    full_name, phone, address fields, marketing_opt_in
 *   POST action=change_password   current_password, new_password
 *
 * Works two ways so the site also functions without JavaScript:
 *   - AJAX / JSON requests get JSON back.
 *   - Normal <form method="post"> requests get a flash message and a redirect
 *     (hidden fields "redirect" and "redirect_error", same-site paths only).
 * All POSTs need the CSRF token (hidden field "csrf_token" or X-CSRF-Token header).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail-helper.php';

const MAX_FAILED_LOGINS = 5;
const LOCK_MINUTES      = 15;

start_session();

try {
    $action = (string) ($_GET['action'] ?? request_data()['action'] ?? '');
    $isGet  = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';

    if ($isGet) {
        if ($action === 'csrf' || $action === 'me') {
            json_out(['ok' => true, 'csrf' => csrf_token(), 'user' => public_user(current_user())]);
        }
        fail('Unknown action.', 404);
    }

    api_guard('POST');

    switch ($action) {
        case 'register':        do_register();        break;
        case 'login':           do_login();           break;
        case 'logout':          do_logout();          break;
        case 'forgot':          do_forgot();          break;
        case 'reset':           do_reset();           break;
        case 'update_profile':  do_update_profile();  break;
        case 'change_password':do_change_password(); break;
        default:                fail('Unknown action.', 404);
    }
} catch (ApiError $e) {
    respond(false, $e->getMessage(), $e->extra, null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('[StarrSavers][auth] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond(false, 'Something went wrong on our side. Please try again.', [], null, 500);
}

// ---------------------------------------------------------------------------
// Response helper: JSON for AJAX, flash + redirect for plain form posts
// ---------------------------------------------------------------------------
function respond(bool $ok, string $message, array $data = [], ?string $redirect = null, int $status = 200): void
{
    if (wants_json()) {
        $payload = ['ok' => $ok] + ($ok ? ['message' => $message] : ['error' => $message]) + $data;
        json_out($payload, $ok ? 200 : $status);
    }
    if (!$ok && !empty($data['fields']) && is_array($data['fields'])) {
        $message = implode(' ', array_values($data['fields']));      // e.g. "Your password must be at least 8 characters long."
    }
    flash_set($ok ? 'success' : 'error', $message);
    $d = request_data();
    $target = $ok
        ? safe_redirect_target($redirect ?? ($d['redirect'] ?? null), 'account.php')
        : safe_redirect_target($d['redirect_error'] ?? ($d['redirect'] ?? null), 'account.php');
    header('Location: ' . $target);
    exit;
}

function public_user(?array $u): ?array
{
    if (!$u) { return null; }
    return [
        'id'    => (int) $u['id'],
        'name'  => $u['full_name'],
        'email' => $u['email'],
        'phone' => $u['phone'],
        'role'  => $u['role'],
    ];
}

function clean_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function password_problem(string $pw): ?string
{
    if (strlen($pw) < 8) { return 'Your password must be at least 8 characters long.'; }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) { return 'Your password must include at least one letter and one number.'; }
    if (strlen($pw) > 200) { return 'That password is too long.'; }
    return null;
}

function log_user_in(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf']    = bin2hex(random_bytes(32));   // fresh token after privilege change
    db()->prepare('UPDATE users SET last_login_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = :id')
        ->execute([':id' => $user['id']]);
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
function do_register(): void
{
    if (!rate_limit('register', 5, 3600)) {
        fail('Too many sign-up attempts. Please try again later.', 429);
    }
    $d = request_data();

    $name  = trim((string) ($d['full_name'] ?? ''));
    $email = clean_email((string) ($d['email'] ?? ''));
    $phone = normalize_sa_phone((string) ($d['phone'] ?? ''));
    $pw    = (string) ($d['password'] ?? '');

    $errors = [];
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120)        { $errors['full_name'] = 'Please enter your full name.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))            { $errors['email'] = 'Please enter a valid email address.'; }
    if ($phone === null)                                       { $errors['phone'] = 'Please enter a valid South African phone number.'; }
    if (($p = password_problem($pw)) !== null)                 { $errors['password'] = $p; }
    if (empty($d['popia_consent']))                            { $errors['popia_consent'] = 'Please accept the privacy notice to create an account.'; }
    if ($errors) {
        fail('Please fix the highlighted fields.', 422, ['fields' => $errors]);
    }

    $pdo = db();
    $exists = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
    $exists->execute([':e' => $email]);
    if ($exists->fetch()) {
        fail('An account with that email already exists. Try logging in instead.', 409, ['fields' => ['email' => 'This email is already registered.']]);
    }

    $pdo->prepare(
        'INSERT INTO users (full_name, email, phone, password_hash, role, popia_consent_at, marketing_opt_in)
         VALUES (:n, :e, :p, :h, \'customer\', NOW(), :m)'
    )->execute([
        ':n' => $name,
        ':e' => $email,
        ':p' => $phone,
        ':h' => password_hash($pw, PASSWORD_DEFAULT),
        ':m' => !empty($d['marketing_opt_in']) ? 1 : 0,
    ]);

    $user = ['id' => (int) $pdo->lastInsertId(), 'full_name' => $name, 'email' => $email, 'phone' => $phone, 'role' => 'customer'];
    log_user_in($user);
    send_welcome_email($email, $name);

    respond(true, 'Welcome to Starr Savers! Your account is ready.', ['user' => public_user($user), 'csrf' => csrf_token()]);
}

function do_login(): void
{
    if (!rate_limit('login', 15, 900)) {
        fail('Too many login attempts. Please wait a few minutes and try again.', 429);
    }
    $d     = request_data();
    $email = clean_email((string) ($d['email'] ?? ''));
    $pw    = (string) ($d['password'] ?? '');
    $generic = 'That email and password do not match.';

    if ($email === '' || $pw === '') {
        fail('Enter your email and password.', 422);
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
    $stmt->execute([':e' => $email]);
    $user = $stmt->fetch();

    if ($user && !empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
        $mins = max(1, (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60));
        fail('Too many failed attempts. Try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . ' or reset your password.', 429);
    }

    if (!$user || !(int) $user['is_active'] || !password_verify($pw, (string) $user['password_hash'])) {
        if ($user) {
            $fails = (int) $user['failed_logins'] + 1;
            if ($fails >= MAX_FAILED_LOGINS) {
                $pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = (NOW() + INTERVAL ' . LOCK_MINUTES . ' MINUTE) WHERE id = :id')
                    ->execute([':id' => $user['id']]);
            } else {
                $pdo->prepare('UPDATE users SET failed_logins = :f WHERE id = :id')->execute([':f' => $fails, ':id' => $user['id']]);
            }
        } else {
            password_verify($pw, '$2y$10$usesomesillystringforsaltandfakehashxxxxxxxxxxxxxxxxxxxxx'); // keep timing similar
        }
        fail($generic, 401);
    }

    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
            ->execute([':h' => password_hash($pw, PASSWORD_DEFAULT), ':id' => $user['id']]);
    }

    log_user_in($user);
    $default = $user['role'] === 'admin' && !empty($d['admin']) ? 'admin/index.php' : 'account.php';
    respond(true, 'Welcome back, ' . explode(' ', trim((string) $user['full_name']))[0] . '!',
        ['user' => public_user($user), 'csrf' => csrf_token()], $d['redirect'] ?? $default);
}

function do_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => (bool) $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
    start_session();               // fresh anonymous session so a new CSRF token can be issued
    respond(true, 'You have been logged out.', ['csrf' => csrf_token()], 'index.php');
}

function do_forgot(): void
{
    if (!rate_limit('forgot', 4, 3600)) {
        fail('Too many reset requests. Please try again later.', 429);
    }
    $email = clean_email((string) (request_data()['email'] ?? ''));
    $generic = 'If that email is registered, we have sent a reset link. It is valid for 1 hour.';

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE email = :e AND is_active = 1 LIMIT 1');
        $stmt->execute([':e' => $email]);
        if ($user = $stmt->fetch()) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = :u')->execute([':u' => $user['id']]);
            $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:u, :t, (NOW() + INTERVAL 1 HOUR))')
                ->execute([':u' => $user['id'], ':t' => hash('sha256', $token)]);
            $url = rtrim((string) APP_URL, '/') . '/account.php?reset=' . $token . '&e=' . rawurlencode($email);
            send_password_reset($user['email'], $user['full_name'], $url);
        }
    }
    respond(true, $generic);   // same answer whether or not the account exists
}

function do_reset(): void
{
    $d     = request_data();
    $email = clean_email((string) ($d['email'] ?? ''));
    $token = (string) ($d['token'] ?? '');
    $pw    = (string) ($d['password'] ?? '');

    if (($p = password_problem($pw)) !== null) {
        fail($p, 422, ['fields' => ['password' => $p]]);
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        fail('This reset link is not valid. Please request a new one.', 400);
    }

    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT pr.id AS reset_id, u.id AS user_id
           FROM password_resets pr JOIN users u ON u.id = pr.user_id
          WHERE pr.token_hash = :t AND u.email = :e AND pr.used_at IS NULL AND pr.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([':t' => hash('sha256', $token), ':e' => $email]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('This reset link has expired or was already used. Please request a new one.', 400);
    }

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET password_hash = :h, failed_logins = 0, locked_until = NULL WHERE id = :id')
        ->execute([':h' => password_hash($pw, PASSWORD_DEFAULT), ':id' => $row['user_id']]);
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')->execute([':id' => $row['reset_id']]);
    $pdo->commit();

    respond(true, 'Your password has been changed. You can log in now.', [], 'account.php');
}

function do_update_profile(): void
{
    $user = require_login();
    $d    = request_data();

    $name  = trim((string) ($d['full_name'] ?? ''));
    $phone = normalize_sa_phone((string) ($d['phone'] ?? ''));
    $errors = [];
    if (mb_strlen($name) < 2)  { $errors['full_name'] = 'Please enter your full name.'; }
    if ($phone === null)       { $errors['phone'] = 'Please enter a valid South African phone number.'; }
    if ($errors) {
        fail('Please fix the highlighted fields.', 422, ['fields' => $errors]);
    }

    $trim = static fn (string $k, int $max): ?string => ($v = mb_substr(trim((string) ($d[$k] ?? '')), 0, $max)) === '' ? null : $v;
    if (isset($d['lat'], $d['lng']) && is_numeric($d['lat']) && is_numeric($d['lng'])) {
        $lat = round((float) $d['lat'], 6);
        $lng = round((float) $d['lng'], 6);
    } else {
        // No new coordinates supplied: keep the saved ones only if the address is unchanged.
        $same = (string) $user['address_line1'] === (string) $trim('address_line1', 190)
             && (string) $user['suburb'] === (string) $trim('suburb', 100)
             && (string) $user['city'] === (string) $trim('city', 100)
             && (string) $user['postal_code'] === (string) $trim('postal_code', 10);
        $lat = $same ? $user['latitude'] : null;
        $lng = $same ? $user['longitude'] : null;
    }

    db()->prepare(
        'UPDATE users SET full_name = :n, phone = :p, address_line1 = :a1, address_line2 = :a2, suburb = :s,
                city = :c, province = :pr, postal_code = :pc, latitude = :lat, longitude = :lng, marketing_opt_in = :m
          WHERE id = :id'
    )->execute([
        ':n' => $name, ':p' => $phone,
        ':a1' => $trim('address_line1', 190), ':a2' => $trim('address_line2', 190),
        ':s' => $trim('suburb', 100), ':c' => $trim('city', 100), ':pr' => $trim('province', 60), ':pc' => $trim('postal_code', 10),
        ':lat' => $lat, ':lng' => $lng,
        ':m' => !empty($d['marketing_opt_in']) ? 1 : 0,
        ':id' => $user['id'],
    ]);

    respond(true, 'Your details have been saved.');
}

function do_change_password(): void
{
    $user = require_login();
    $d    = request_data();

    $row = db()->prepare('SELECT password_hash FROM users WHERE id = :id');
    $row->execute([':id' => $user['id']]);
    $hash = (string) ($row->fetchColumn() ?: '');

    if (!password_verify((string) ($d['current_password'] ?? ''), $hash)) {
        fail('Your current password is not correct.', 422, ['fields' => ['current_password' => 'Incorrect password.']]);
    }
    $new = (string) ($d['new_password'] ?? '');
    if (($p = password_problem($new)) !== null) {
        fail($p, 422, ['fields' => ['new_password' => $p]]);
    }

    db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
        ->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => $user['id']]);
    session_regenerate_id(true);
    respond(true, 'Your password has been updated.');
}
