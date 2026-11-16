<?php
require_once __DIR__ . '/auth.php';

kiosk_no_store();
header('Content-Type: text/html; charset=utf-8');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$next = kiosk_safe_next($_POST['next'] ?? $_GET['next'] ?? 'index.php');
$logout = ($_GET['logout'] ?? null) === '1' || ($_POST['action'] ?? null) === 'logout';
$isConfigured = kiosk_configured_token() !== null;
$error = isset($_GET['error']) ? 'Der Zugang konnte nicht bestätigt werden.' : '';
$csrf = '';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    $error = 'Die Anfrage konnte nicht bestätigt werden.';
} else {
    try {
        if ($method === 'POST') {
            if (!kiosk_csrf_valid()) {
                http_response_code(403);
                $error = 'Die Anfrage konnte nicht bestätigt werden. Bitte lade die Seite neu.';
            } elseif ($logout) {
                kiosk_logout();
                header('Location: zugang.php', true, 303);
                exit;
            } else {
                $provided = $_POST['token'] ?? null;
                if (is_string($provided) && kiosk_login($provided)) {
                    header('Location: ' . $next, true, 303);
                    exit;
                }
                http_response_code(403);
                $error = 'Der Zugang konnte nicht bestätigt werden.';
            }
        } elseif (!$logout && $error === '' && kiosk_is_private()) {
            header('Location: ' . $next, true, 303);
            exit;
        }
        if ($isConfigured || $logout) {
            $csrf = kiosk_csrf_token();
        }
    } catch (Throwable $failure) {
        http_response_code(503);
        $error = 'Der Zugang ist gerade nicht verfügbar. Bitte versuche es später erneut.';
    }
}
function kiosk_access_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $logout ? 'Abmelden' : 'Privater Zugang'; ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 24px; display: grid; place-items: center; font: 17px/1.5 system-ui, sans-serif; background: #111b24; color: #f2f5f7; }
        main { width: min(100%, 430px); padding: 28px; background: #1b2a36; border: 1px solid #344958; border-radius: 14px; }
        h1 { font-size: 1.5rem; margin: 0 0 12px; } p { margin: 0 0 20px; }
        label { display: block; margin-bottom: 6px; }
        input[type=password] { display: block; width: 100%; padding: 12px; margin-bottom: 18px; border: 1px solid #8dabbf; border-radius: 6px; font: inherit; }
        button { padding: 11px 18px; border: 0; border-radius: 6px; background: #a3e2b7; color: #14261c; font: inherit; font-weight: 650; cursor: pointer; }
        a { display: inline-block; margin-top: 20px; color: #c1def4; }
        .error { color: #ffd0cf; }
    </style>
</head>
<body>
<main>
    <h1><?php echo $logout ? 'Abmelden' : 'Privater Zugang'; ?></h1>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo kiosk_access_h($error); ?></p><?php endif; ?>
    <?php if (!$isConfigured): ?><p>Der private Zugang ist auf dem Server noch nicht eingerichtet.</p><?php endif; ?>
    <?php if ($csrf !== ''): ?>
    <?php if ($logout): ?>
        <p>Den privaten Zugang auf diesem Gerät beenden?</p>
        <form method="post" action="zugang.php?logout=1">
            <input type="hidden" name="_kiosk_csrf" value="<?php echo kiosk_access_h($csrf); ?>">
            <button type="submit">Abmelden</button>
        </form>
    <?php else: ?>
        <p>Mit dem Zugangscode werden private Informationen und die Verwaltung freigeschaltet.</p>
        <form method="post" action="zugang.php">
            <input type="hidden" name="_kiosk_csrf" value="<?php echo kiosk_access_h($csrf); ?>">
            <input type="hidden" name="next" value="<?php echo kiosk_access_h($next); ?>">
            <label for="token">Zugangscode</label>
            <input type="password" id="token" name="token" autocomplete="current-password" required>
            <button type="submit">Anmelden</button>
        </form>
    <?php endif; ?>
    <?php endif; ?>
    <a href="index.php">Zur öffentlichen Ansicht</a>
    <nav aria-label="Rechtliche Informationen"><a href="impressum.php">Impressum</a> · <a href="datenschutz.php">Datenschutz</a></nav>
</main>
</body>
</html>
