<?php
// Der Kiosk-Zugang verwendet einen eigenen Token, unabhängig vom HA-Ingest.

const KIOSK_SESSION_NAME = 'hausordnung_kiosk';
const KIOSK_SESSION_LIFETIME = 30 * 24 * 60 * 60;

function kiosk_no_store(): void {
    if (!headers_sent()) {
        header('Cache-Control: private, no-store');
        header('Pragma: no-cache');
        header('Vary: Cookie, Authorization', false);
    }
}

function kiosk_configured_token(): ?string {
    $token = getenv('KIOSK_TOKEN');
    return $token === false || trim($token) === '' ? null : $token;
}

function kiosk_cookie_secure(): bool {
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $actualHttps = ($https !== '' && $https !== 'off' && $https !== '0')
        || strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    // Hinter dem HTTPS-Reverseproxy ist Apache häufig selbst per HTTP erreichbar.
    // Nur eine ausdrückliche lokale Ausnahme darf Secure abschalten.
    return $actualHttps || getenv('KIOSK_COOKIE_SECURE') !== '0';
}

// PHP 8.3 besitzt noch keine native Session-Option für CHIPS.
function kiosk_partition_session_cookie(bool $dropLegacy = false): void {
    if (!kiosk_cookie_secure() || headers_sent()) return;
    $cookies = [];
    foreach (headers_list() as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) $cookies[] = $header;
    }
    header_remove('Set-Cookie');
    if ($dropLegacy) {
        header('Set-Cookie: ' . KIOSK_SESSION_NAME . '=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=None', false);
    }
    foreach ($cookies as $header) {
        if (stripos($header, 'Set-Cookie: ' . KIOSK_SESSION_NAME . '=') === 0
            && stripos($header, '; Partitioned') === false) {
            $header .= '; Partitioned';
        }
        header($header, false);
    }
}

function kiosk_start_session(): bool {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return session_name() === KIOSK_SESSION_NAME;
    }
    if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
        return false;
    }
    session_name(KIOSK_SESSION_NAME);
    $started = @session_start([
        'use_strict_mode' => 1,
        'use_only_cookies' => 1,
        'use_trans_sid' => 0,
        'cookie_lifetime' => KIOSK_SESSION_LIFETIME,
        'cookie_path' => '/',
        'cookie_domain' => '',
        'cookie_secure' => kiosk_cookie_secure(),
        'cookie_httponly' => true,
        'cookie_samesite' => kiosk_cookie_secure() ? 'None' : 'Strict',
        'gc_maxlifetime' => KIOSK_SESSION_LIFETIME,
        'cache_limiter' => '',
    ]);
    kiosk_partition_session_cookie();
    return $started;
}

function kiosk_bearer_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if ($header === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }
    if (!is_string($header) || !preg_match('/^Bearer[\t ]+([^\s]+)[\t ]*$/Di', $header, $matches)) {
        return null;
    }
    return $matches[1];
}

// Eigene, befristete Berechtigung für WebViews ohne Drittanbieter-Cookies.
// Der konfigurierte Haupttoken wird niemals an JavaScript zurückgegeben.
function kiosk_frame_ticket(string $key, ?int $now = null): string {
    $body = 'frame1.' . (($now ?? time()) + KIOSK_SESSION_LIFETIME) . '.' . bin2hex(random_bytes(16));
    return $body . '.' . hash_hmac('sha256', 'hausordnung-frame-access:' . $body, $key);
}

function kiosk_frame_ticket_valid(string $ticket, string $key, ?int $now = null): bool {
    if ($key === '' || !preg_match('/^(frame1\.([0-9]{10})\.[a-f0-9]{32})\.([a-f0-9]{64})$/D', $ticket, $parts)) {
        return false;
    }
    $now = $now ?? time();
    $expires = (int)$parts[2];
    return $expires > $now && $expires <= $now + KIOSK_SESSION_LIFETIME
        && hash_equals(hash_hmac('sha256', 'hausordnung-frame-access:' . $parts[1], $key), $parts[3]);
}

/** Gibt session, bearer oder null zurück. Queryparameter autorisieren hier nie. */
function kiosk_authentication_mode(): ?string {
    kiosk_no_store();
    $configured = kiosk_configured_token();
    if ($configured === null) {
        if (session_status() === PHP_SESSION_ACTIVE && session_name() === KIOSK_SESSION_NAME
            && isset($_SESSION['kiosk_auth'])) {
            unset($_SESSION['kiosk_auth'], $_SESSION['kiosk_csrf']);
        }
        return null;
    }
    $bearer = kiosk_bearer_token();
    if ($bearer !== null && (hash_equals($configured, $bearer) || kiosk_frame_ticket_valid($bearer, $configured))) {
        return 'bearer';
    }
    $hasCookie = isset($_COOKIE[KIOSK_SESSION_NAME]) && is_string($_COOKIE[KIOSK_SESSION_NAME])
        && $_COOKIE[KIOSK_SESSION_NAME] !== '';
    if (!$hasCookie && session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    if (!kiosk_start_session()) {
        return null;
    }
    $auth = $_SESSION['kiosk_auth'] ?? null;
    if ($auth === null) {
        return null;
    }
    if (!is_array($auth) || !is_string($auth['fingerprint'] ?? null)
        || !is_int($auth['expires_at'] ?? null) || $auth['expires_at'] <= time()
        || !hash_equals(hash('sha256', $configured), $auth['fingerprint'])) {
        unset($_SESSION['kiosk_auth'], $_SESSION['kiosk_csrf']);
        return null;
    }
    return 'session';
}

function kiosk_is_private(): bool {
    return kiosk_authentication_mode() !== null;
}

function kiosk_csrf_token(): string {
    kiosk_no_store();
    if (!kiosk_start_session()) {
        throw new RuntimeException('Der Zugang konnte nicht vorbereitet werden.');
    }
    if (!is_string($_SESSION['kiosk_csrf'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D', $_SESSION['kiosk_csrf'])) {
        $_SESSION['kiosk_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['kiosk_csrf'];
}

function kiosk_csrf_valid(): bool {
    if (!kiosk_start_session()) {
        return false;
    }
    $expected = $_SESSION['kiosk_csrf'] ?? null;
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_kiosk_csrf'] ?? null;
    return is_string($expected) && $expected !== '' && is_string($provided)
        && hash_equals($expected, $provided);
}

function kiosk_request_changes_state(): bool {
    return !in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD', 'OPTIONS'], true);
}

function kiosk_private_api_error(int $status): void {
    kiosk_no_store();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($status === 401) {
        header('WWW-Authenticate: Bearer realm="Hausordnung"');
    }
    echo json_encode(['error' => 'Zugriff nicht erlaubt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function kiosk_require_private_api(): void {
    $mode = kiosk_authentication_mode();
    if ($mode === null) {
        kiosk_private_api_error(401);
    }
    if ($mode === 'session' && kiosk_request_changes_state() && !kiosk_csrf_valid()) {
        kiosk_private_api_error(403);
    }
}

function kiosk_safe_next($value): string {
    return is_string($value) && in_array($value, ['index.php', 'admin.php'], true) ? $value : 'index.php';
}

function kiosk_require_private_page(): void {
    $mode = kiosk_authentication_mode();
    if ($mode === null) {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['GET', 'HEAD'], true)) {
            $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
            header('Location: zugang.php?next=' . rawurlencode(kiosk_safe_next($page)), true, 303);
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Zugriff nicht erlaubt.';
        exit;
    }
    if ($mode === 'session' && kiosk_request_changes_state() && !kiosk_csrf_valid()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Zugriff nicht erlaubt.';
        exit;
    }
}

/** Das Geheimnis bleibt ausschließlich im ENV; die Sitzung enthält einen Hash. */
function kiosk_login(string $provided): bool {
    $configured = kiosk_configured_token();
    if ($configured === null || !hash_equals($configured, $provided) || !kiosk_start_session()) {
        return false;
    }
    if (!session_regenerate_id(true)) {
        return false;
    }
    $_SESSION = [
        'kiosk_auth' => [
            'fingerprint' => hash('sha256', $configured),
            'expires_at' => time() + KIOSK_SESSION_LIFETIME,
        ],
        'kiosk_csrf' => bin2hex(random_bytes(32)),
    ];
    kiosk_partition_session_cookie(true);
    return true;
}

function kiosk_logout(): void {
    if (!kiosk_start_session()) {
        return;
    }
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(KIOSK_SESSION_NAME, '', [
        'expires' => time() - 42000, 'path' => '/', 'domain' => '',
        'secure' => (bool)$params['secure'], 'httponly' => true,
        'samesite' => kiosk_cookie_secure() ? 'None' : 'Strict',
    ]);
    kiosk_partition_session_cookie(true);
    session_destroy();
}

/** Nur index.php ruft diesen ausdrücklich gewünschten Bookmark-Einstieg auf. */
function kiosk_handle_entry_token(): ?string {
    if (!array_key_exists('TOKEN', $_GET)) {
        return null;
    }
    kiosk_no_store();
    header('Referrer-Policy: no-referrer');
    $token = $_GET['TOKEN'];
    $isGet = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET';
    $configured = kiosk_configured_token();
    if ($isGet && ($_GET['FRAME'] ?? null) === '1' && is_string($token)
        && $configured !== null && hash_equals($configured, $token)) {
        return kiosk_frame_ticket($configured);
    }
    if ($isGet && is_string($token) && kiosk_login($token)) {
        header('Location: index.php', true, 303);
    } else {
        header('Location: zugang.php?error=1', true, 303);
    }
    exit;
}
