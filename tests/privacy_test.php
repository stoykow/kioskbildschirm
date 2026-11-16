<?php
declare(strict_types=1);

// Aufruf: php tests/privacy_test.php
// Echte HTTP-Endpunkte mit ausschließlich künstlichen Lesedaten und TEMP-Sitzungen.
if (!function_exists('proc_open')) {
    fwrite(STDERR, 'proc_open wird für den isolierten HTTP-Test benötigt.' . PHP_EOL);
    exit(1);
}

$failures = [];
$testCount = 0;
$server = null;
require_once dirname(__DIR__) . '/auth.php';
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hausordnung_privacy_' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);
mkdir($temporary . '/sessions', 0700);
mkdir($temporary . '/web', 0700);

function privacy_same($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
    }
}

function privacy_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function privacy_case(string $name, callable $test): void
{
    global $failures, $testCount;
    $testCount++;
    try {
        $test();
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        $failures[] = $name . ': ' . $error->getMessage();
        echo '[FEHLER] ' . $name . PHP_EOL;
    }
}

function privacy_stop(): void
{
    global $server;
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $server = null;
}

function privacy_start(string $token, string $secure = '0'): void
{
    global $server, $temporary, $baseUrl;
    privacy_stop();
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if (!$socket) throw new RuntimeException('Lokalen Testport nicht verfügbar.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $baseUrl = 'http://' . $address;
    $environment = [
        'KIOSK_TOKEN' => $token, 'HO_TOKEN' => 'fixture-ingest-only', 'KIOSK_COOKIE_SECURE' => $secure,
        'SystemRoot' => getenv('SystemRoot') ?: '', 'TEMP' => $temporary, 'TMP' => $temporary,
    ];
    $server = proc_open([
        PHP_BINARY, '-d', 'display_errors=0', '-d', 'session.save_path=' . $temporary . '/sessions',
        '-S', $address, '-t', $temporary . '/web',
    ], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a']], $pipes, $temporary, $environment);
    if (!is_resource($server)) throw new RuntimeException('Isolierter PHP-Server konnte nicht starten.');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
        if ($connection) { fclose($connection); return; }
        usleep(50000);
    }
    throw new RuntimeException('Isolierter PHP-Server antwortet nicht.');
}

function privacy_request(string $path, string $method = 'GET', array $headers = [], ?array $form = null, string $cookie = ''): array
{
    global $baseUrl;
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    $content = $form !== null ? http_build_query($form) : '';
    if ($form !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
        'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => 4,
    ]]);
    $body = file_get_contents($baseUrl . '/' . ltrim($path, '/'), false, $context);
    if ($body === false) throw new RuntimeException('Lokale Testanfrage fehlgeschlagen.');
    $responseHeaders = [];
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+ ([0-9]{3})~', $line, $match)) $status = (int)$match[1];
        elseif (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower($key)][] = trim($value);
        }
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
}

function privacy_json(array $response): array
{
    $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Keine JSON-Struktur erhalten.');
    return $data;
}

function privacy_cookie(array $response, string $previous = ''): string
{
    $cookies = [];
    foreach (array_filter(explode('; ', $previous)) as $part) {
        [$name, $value] = explode('=', $part, 2);
        $cookies[$name] = $value;
    }
    foreach ($response['headers']['set-cookie'] ?? [] as $line) {
        [$name, $value] = explode('=', explode(';', $line, 2)[0], 2);
        $cookies[$name] = $value;
    }
    return implode('; ', array_map(fn($name, $value) => $name . '=' . $value, array_keys($cookies), $cookies));
}

function privacy_login(string $token): array
{
    $form = privacy_request('zugang.php');
    $cookie = privacy_cookie($form);
    preg_match_all('~<input\b[^>]*>~i', $form['body'], $inputs);
    $csrf = '';
    foreach ($inputs[0] as $input) {
        if (preg_match('~name=["\']_kiosk_csrf["\']~i', $input) && preg_match('~value=["\']([^"\']*)["\']~i', $input, $match)) {
            $csrf = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    privacy_check($csrf !== '', 'Loginformular enthält keinen CSRF-Schutz.');
    $login = privacy_request('zugang.php', 'POST', [], ['token' => $token, '_kiosk_csrf' => $csrf], $cookie);
    $cookie = privacy_cookie($login, $cookie);
    return ['response' => $login, 'cookie' => $cookie, 'old_cookie' => privacy_cookie($form), 'old_csrf' => $csrf];
}

function privacy_cleanup(string $directory): void
{
    $resolved = realpath($directory);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporaryRoot === false || !str_starts_with(strtolower($resolved), strtolower($temporaryRoot) . DIRECTORY_SEPARATOR)
        || !str_starts_with(basename($resolved), 'hausordnung_privacy_')) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isLink()) { unlink($path); continue; }
        $target = realpath($path);
        if ($target === false || !str_starts_with(strtolower($target), strtolower($resolved) . DIRECTORY_SEPARATOR)) continue;
        if ($entry->isDir()) rmdir($path); else unlink($path);
    }
    rmdir($resolved);
}

try {
    foreach (glob(dirname(__DIR__) . '/*.php') as $source) {
        if (basename($source) !== 'config.php') copy($source, $temporary . '/web/' . basename($source));
    }
    if (!is_file($temporary . '/web/auth.php') || !is_file($temporary . '/web/zugang.php')) throw new RuntimeException('auth.php und zugang.php müssen vorhanden sein.');
    file_put_contents($temporary . '/web/probe.php', <<<'PHP'
<?php
require_once __DIR__ . '/auth.php';
if (($_GET['action'] ?? '') === 'api') kiosk_require_private_api();
if (($_GET['action'] ?? '') === 'page') kiosk_require_private_page();
header('Content-Type: application/json');
echo json_encode(['private' => kiosk_is_private(), 'csrf' => kiosk_csrf_token()]);
PHP);
    file_put_contents($temporary . '/web/config.php', <<<'PHP'
<?php
$showMouse = true;
function departure_limit(string $type): int { return 4; }
function env_value($key, $default = null) { $value = getenv($key); return $value === false || $value === '' ? $default : $value; }
function app_config_get(?PDO $pdo = null) { return ['termine_abfall_days' => 14, 'termine_sonstige_days' => 14]; }
function db_connect() { return new PrivacyFixturePDO(); }
class PrivacyFixturePDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new PrivacyFixtureStatement($query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new PrivacyFixtureStatement($query); }
}
class PrivacyFixtureStatement extends PDOStatement {
    private string $queryText;
    public function __construct(string $query) { $this->queryText = $query; }
    public function execute(?array $params = null): bool { return true; }
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        if (str_contains($this->queryText, 'geraete_daten')) {
            $payload = [];
            foreach (['sensor.geiger_temperatur' => '21.3', 'sensor.geiger_luftfeuchte' => '53', 'sensor.geiger_luftdruck' => '1013', 'sensor.multigeiger_geiger_dosisleistung' => '0.12'] as $key => $value) {
                $payload[$key] = ['state' => $value, 'attributes' => ['unit_of_measurement' => $key === 'sensor.geiger_temperatur' ? '°C' : 'hPa', 'friendly_name' => 'PRIVAT-PERSON', 'door_debug' => 'PRIVAT-TÜR']];
            }
            $payload['lock.haustur'] = ['state' => 'PRIVAT-TÜR', 'attributes' => ['owner' => 'PRIVAT-PERSON']];
            $payload['sensor.private_presence'] = ['state' => 'PRIVAT-ZUHAUSE'];
            return [['zeit' => $today . ' 12:00:00', 'typ' => 'homeassistant', 'payload_json' => json_encode($payload)]];
        }
        if (str_contains($this->queryText, 'FROM termine_abfall t')) return [[
            'id' => 1, 'datum' => $today, 'summary' => 'Restmüll', 'start_time' => '07:00:00', 'end_time' => null,
            'erledigt_timestamp' => 1794643200, 'erledigt_namen' => 'PRIVAT-PERSON',
            'reingestellt_timestamp' => 1794646800, 'reingestellt_namen' => 'PRIVAT-REIN',
        ]];
        if (str_contains($this->queryText, 'FROM termine_sonstige t')) return [[
            'id' => 2, 'datum' => $today, 'titel' => 'Heizungswartung', 'hinweis' => 'Zugang freihalten.',
            'start_time' => '10:00:00', 'end_time' => '11:00:00', 'requires_home' => 1,
            'zuhause_namen' => 'PRIVAT-ZUHAUSE', 'zuhause_ids' => '77,88',
        ]];
        if (str_contains($this->queryText, 'FROM benutzer')) return [['id' => 77, 'name' => 'PRIVAT-PERSON']];
        return [];
    }
}
PHP);

    privacy_start('fixture-viewer-one');
    privacy_case('Anonym und falscher Bearer bleiben öffentlich, Ingest-Token schaltet nichts frei', function (): void {
        foreach ([[], ['Authorization: Bearer falsch'], ['Authorization: Bearer fixture-ingest-only']] as $headers) {
            $response = privacy_request('probe.php?action=api', 'GET', $headers);
            privacy_same(401, $response['status']);
            privacy_json($response);
        }
        privacy_same(false, privacy_json(privacy_request('probe.php'))['private']);
    });
    privacy_case('Richtiger Bearer erlaubt private API ohne Cookie-CSRF', function (): void {
        foreach (['GET', 'POST'] as $method) {
            $response = privacy_request('probe.php?action=api', $method, ['Authorization: Bearer fixture-viewer-one']);
            privacy_same(200, $response['status']);
            privacy_same(true, privacy_json($response)['private']);
        }
    });
    privacy_case('URL-Tokens authentifizieren keine API', function (): void {
        $response = privacy_request('probe.php?action=api&TOKEN=fixture-viewer-one&token=fixture-viewer-one');
        privacy_same(401, $response['status']);
        privacy_check(!str_contains($response['body'], 'fixture-viewer-one'), 'URL-Token wurde ausgegeben.');
    });
    privacy_case('Alle geschützten Daten- und Mutationsendpunkte verlangen Zugriff vor DB-Zugriff', function (): void {
        foreach (['aufgaben_api.php', 'reinigungsplan_api.php', 'abfall_users.php', 'device_register.php', 'aufgaben_done.php', 'abfall_done.php', 'termine_sonstige_zuhause.php'] as $endpoint) {
            $response = privacy_request($endpoint, str_contains($endpoint, '_api') || $endpoint === 'abfall_users.php' ? 'GET' : 'POST');
            privacy_same(401, $response['status']);
            privacy_json($response);
        }
    });
    privacy_case('Adminseiten leiten GET zur Anmeldung und weisen anonymes POST ab', function (): void {
        foreach (['admin.php', 'config_admin.php', 'reinigungsplan_admin.php', 'termine_sonstige_admin.php'] as $endpoint) {
            $get = privacy_request($endpoint);
            privacy_check(in_array($get['status'], [302, 303], true), $endpoint . ' leitet nicht um.');
            privacy_check(str_contains(implode('', $get['headers']['location'] ?? []), 'zugang.php'), 'Keine Anmeldung als Redirectziel.');
            privacy_same(403, privacy_request($endpoint, 'POST')['status']);
        }
    });
    privacy_case('Öffentliche Termine enthalten weder Personen noch Aktivitätsfelder', function (): void {
        foreach (['abfall_api.php', 'termine_sonstige_api.php'] as $endpoint) {
            $response = privacy_request($endpoint);
            privacy_same(200, $response['status']);
            $event = privacy_json($response)['events'][0];
            privacy_check(isset($event['date']), 'Öffentlicher Termin fehlt.');
            foreach (array_keys($event) as $key) privacy_check(!preg_match('/^(?:done|rein_done|zuhause)_|^requires_home$/', $key), 'Privates Feld öffentlich: ' . $key);
            privacy_check(!str_contains($response['body'], 'PRIVAT-'), 'Private Terminwerte öffentlich.');
        }
    });
    privacy_case('Private Terminantworten behalten Personen- und Statusfelder', function (): void {
        foreach (['abfall_api.php', 'termine_sonstige_api.php'] as $endpoint) {
            $response = privacy_request($endpoint, 'GET', ['Authorization: Bearer fixture-viewer-one']);
            privacy_same(200, $response['status']);
            privacy_check(str_contains($response['body'], 'PRIVAT-'), 'Private Felder gingen verloren.');
        }
    });
    privacy_case('Öffentlicher Multigeiger entfernt Tür, Anwesenheit und zusätzliche Attribute', function (): void {
        $response = privacy_request('geraet_status.php?geraet=multigeiger');
        privacy_same(200, $response['status']);
        privacy_check(!str_contains($response['body'], 'PRIVAT-'), 'Gemischtes Sensorpayload verrät private Werte.');
        $payload = privacy_json($response)[0]['payload'];
        $expected = ['sensor.geiger_temperatur', 'sensor.geiger_luftfeuchte', 'sensor.geiger_luftdruck', 'sensor.multigeiger_geiger_dosisleistung'];
        $keys = array_keys($payload); sort($keys); sort($expected);
        privacy_same($expected, $keys);
        foreach ($payload as $sensor) {
            privacy_check(is_numeric($sensor['state']), 'Sensorzustand ist nicht numerisch.');
            privacy_same(['unit_of_measurement'], array_keys($sensor['attributes']));
        }
    });
    privacy_case('Haustürgerät ist anonym gesperrt und privat vollständig verfügbar', function (): void {
        $public = privacy_request('geraet_status.php?geraet=nukihub5');
        privacy_check(in_array($public['status'], [401, 403], true), 'Türgerät ist öffentlich erreichbar.');
        privacy_check(!str_contains($public['body'], 'PRIVAT-'), 'Türgerät verrät Daten.');
        $private = privacy_request('geraet_status.php?geraet=nukihub5', 'GET', ['Authorization: Bearer fixture-viewer-one']);
        privacy_same(200, $private['status']);
        privacy_check(str_contains($private['body'], 'PRIVAT-'), 'Private Sensordaten fehlen.');
    });

    $cookie = '';
    $csrf = '';
    privacy_case('Falsches Login authentifiziert keine Sitzung', function (): void {
        $login = privacy_login('falsch');
        privacy_same(false, privacy_json(privacy_request('probe.php', 'GET', [], null, $login['cookie']))['private']);
        privacy_check(!str_contains($login['response']['body'], 'falsch'), 'Fehlgeschlagenes Token wurde wieder ausgegeben.');
    });
    privacy_case('Login erneuert die Sitzung und erstellt CSRF für private POSTs', function () use (&$cookie, &$csrf): void {
        $login = privacy_login('fixture-viewer-one');
        privacy_check(in_array($login['response']['status'], [302, 303], true), 'Login leitet nicht um.');
        $cookie = $login['cookie'];
        privacy_check($cookie !== $login['old_cookie'], 'Login hat die Sitzungs-ID nicht erneuert.');
        $probe = privacy_request('probe.php', 'GET', [], null, $cookie);
        $data = privacy_json($probe);
        privacy_same(true, $data['private']);
        $csrf = $data['csrf'];
        privacy_check($csrf !== '' && $csrf !== $login['old_csrf'], 'CSRF wurde nach Login nicht erneuert.');
        $setCookie = implode('; ', $login['response']['headers']['set-cookie'] ?? []);
        privacy_check(str_contains(strtolower($setCookie), 'httponly') && str_contains(strtolower($setCookie), 'samesite=strict'), 'Sitzungscookie ist unzureichend geschützt.');
    });
    privacy_case('Cookie-Mutationen benötigen CSRF, ungültiger Bearer umgeht es nicht', function () use (&$cookie, &$csrf): void {
        privacy_same(403, privacy_request('probe.php?action=api', 'POST', [], [], $cookie)['status']);
        privacy_same(403, privacy_request('probe.php?action=api', 'POST', ['Authorization: Bearer falsch'], [], $cookie)['status']);
        privacy_same(403, privacy_request('probe.php?action=api', 'POST', ['X-CSRF-Token: falsch'], [], $cookie)['status']);
        privacy_same(200, privacy_request('probe.php?action=api', 'POST', ['X-CSRF-Token: ' . $csrf], [], $cookie)['status']);
        privacy_same(200, privacy_request('probe.php?action=api', 'POST', [], ['_kiosk_csrf' => $csrf], $cookie)['status']);
    });
    privacy_case('Logout erfolgt nur per POST mit passendem CSRF', function () use (&$cookie, &$csrf): void {
        privacy_request('zugang.php?logout=1', 'GET', [], null, $cookie);
        privacy_same(true, privacy_json(privacy_request('probe.php', 'GET', [], null, $cookie))['private']);
        privacy_same(403, privacy_request('zugang.php?logout=1', 'POST', [], [], $cookie)['status']);
        privacy_request('zugang.php?logout=1', 'POST', [], ['_kiosk_csrf' => $csrf], $cookie);
        privacy_same(false, privacy_json(privacy_request('probe.php', 'GET', [], null, $cookie))['private']);
    });
    privacy_case('Einstiegslink tauscht TOKEN gegen Cookie und bereinigt die URL', function () use (&$cookie): void {
        $response = privacy_request('index.php?TOKEN=fixture-viewer-one');
        privacy_same(303, $response['status']);
        $location = implode('', $response['headers']['location'] ?? []);
        privacy_check(str_contains($location, 'index.php') && !str_contains($location, '?'), 'Einstiegs-URL wurde nicht bereinigt.');
        privacy_check(!str_contains($response['body'] . $location, 'fixture-viewer-one'), 'Einstieg gibt Token aus.');
        $cookie = privacy_cookie($response);
        privacy_same(true, privacy_json(privacy_request('probe.php', 'GET', [], null, $cookie))['private']);
    });
    privacy_case('Falscher oder als Array gelieferter Einstiegs-TOKEN bleibt gesperrt', function (): void {
        foreach (['TOKEN=falsch', 'TOKEN%5B%5D=fixture-viewer-one'] as $query) {
            $response = privacy_request('index.php?' . $query);
            privacy_check(in_array($response['status'], [302, 303, 400, 403], true), 'Ungültiger Einstiegslink wird nicht abgewiesen.');
            privacy_check(!str_contains($response['body'] . implode('', $response['headers']['location'] ?? []), 'fixture-viewer-one'), 'Ungültiger Einstieg gibt Token aus.');
            privacy_same(false, privacy_json(privacy_request('probe.php', 'GET', [], null, privacy_cookie($response)))['private']);
        }
    });
    $frameTicket = '';
    privacy_case('Tablet-Einstieg erlaubt private Lese- und Schreibaufrufe ohne Cookies', function () use (&$frameTicket): void {
        $entry = privacy_request('index.php?TOKEN=fixture-viewer-one&FRAME=1');
        privacy_same(200, $entry['status']);
        privacy_same([], $entry['headers']['set-cookie'] ?? []);
        privacy_check(!str_contains($entry['body'], 'fixture-viewer-one'), 'Haupttoken gelangt ins HTML.');
        privacy_check(preg_match('/frame1\.[0-9]{10}\.[a-f0-9]{32}\.[a-f0-9]{64}/', $entry['body'], $match) === 1, 'Signiertes Ticket fehlt.');
        $frameTicket = $match[0];
        foreach (['GET', 'POST'] as $method) {
            privacy_same(200, privacy_request('probe.php?action=api', $method, ['Authorization: Bearer ' . $frameTicket])['status']);
        }
        privacy_same(200, privacy_request('geraet_status.php?geraet=nukihub5', 'GET', ['Authorization: Bearer ' . $frameTicket])['status']);
        privacy_same(401, privacy_request('probe.php?action=api&access=' . $frameTicket)['status']);
        privacy_same(401, privacy_request('probe.php?action=api', 'GET', ['Authorization: Bearer ' . substr($frameTicket, 0, -1) . 'x'])['status']);
        privacy_same(303, privacy_request('index.php?TOKEN=falsch&FRAME=1')['status']);
    });
    privacy_case('Signatur, Ablaufzeit und maximale Laufzeit werden geprüft', function (): void {
        $now = 1794830400;
        $ticket = kiosk_frame_ticket('test-key', $now);
        privacy_same(true, kiosk_frame_ticket_valid($ticket, 'test-key', $now));
        privacy_same(false, kiosk_frame_ticket_valid($ticket, 'other-key', $now));
        privacy_same(false, kiosk_frame_ticket_valid($ticket, 'test-key', $now + KIOSK_SESSION_LIFETIME));
        privacy_same(false, kiosk_frame_ticket_valid($ticket, 'test-key', $now - 1));
        privacy_same(false, kiosk_frame_ticket_valid($ticket . '.extra', 'test-key', $now));
        privacy_same(false, kiosk_frame_ticket_valid($ticket, '', $now));
    });
    privacy_start('fixture-viewer-two');
    privacy_case('Tokenrotation invalidiert alte Cookies, Frame-Tickets und alte Bearer', function () use (&$cookie, &$frameTicket): void {
        privacy_same(401, privacy_request('probe.php?action=api', 'GET', ['Authorization: Bearer ' . $frameTicket])['status']);
        privacy_same(false, privacy_json(privacy_request('probe.php', 'GET', [], null, $cookie))['private']);
        privacy_same(401, privacy_request('probe.php?action=api', 'GET', ['Authorization: Bearer fixture-viewer-one'])['status']);
        privacy_same(200, privacy_request('probe.php?action=api', 'GET', ['Authorization: Bearer fixture-viewer-two'])['status']);
    });
    privacy_start('fixture-viewer-two', '1');
    privacy_case('Frame-Anmeldung setzt Secure SameSite=None und Partitioned mit gültiger Sitzung', function (): void {
        $response = privacy_request('index.php?TOKEN=fixture-viewer-two');
        privacy_same(303, $response['status']);
        $partitioned = array_values(array_filter($response['headers']['set-cookie'] ?? [],
            fn($header) => stripos($header, 'Partitioned') !== false && !str_contains($header, 'Max-Age=0')));
        privacy_check(count($partitioned) > 0, 'Partitioniertes Cookie fehlt.');
        $header = end($partitioned);
        foreach (['Secure', 'HttpOnly', 'SameSite=None'] as $attribute) {
            privacy_check(stripos($header, $attribute) !== false, $attribute . ' fehlt.');
        }
        $frameCookie = explode(';', $header, 2)[0];
        privacy_same(true, privacy_json(privacy_request('probe.php', 'GET', [], null, $frameCookie))['private']);
        privacy_same(403, privacy_request('probe.php?action=api', 'POST', [], [], $frameCookie)['status']);
    });
    privacy_start('');
    privacy_case('Ohne konfiguriertes Secret bleibt jeder Zugang öffentlich', function () use (&$cookie): void {
        foreach ([[], ['Authorization: Bearer fixture-viewer-one'], ['Authorization: Bearer fixture-viewer-two'], ['Authorization: Bearer fixture-ingest-only']] as $headers) {
            privacy_same(false, privacy_json(privacy_request('probe.php', 'GET', $headers, null, $cookie))['private']);
        }
        privacy_same(401, privacy_request('probe.php?action=api', 'POST')['status']);
    });
} catch (Throwable $error) {
    $failures[] = 'Testumgebung: ' . $error->getMessage();
} finally {
    privacy_stop();
    privacy_cleanup($temporary);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    fwrite(STDERR, count($failures) . ' Fehler bei ' . $testCount . ' Prüfungen.' . PHP_EOL);
    exit(1);
}
echo 'Alle ' . $testCount . ' Prüfungen erfolgreich.' . PHP_EOL;
