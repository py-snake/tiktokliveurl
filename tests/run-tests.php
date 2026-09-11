<?php

/**
 * Zero-dependency test runner + coverage reporter for tiktokliveurl.
 *
 * Steps:
 *   1. Instrument api.php / proxy.php / tiktok_signer.php / config.php with
 *      statement-level hit markers (see lib/instrument.php).
 *   2. Spawn a mock TikTok server and an instrumented webroot served by the
 *      PHP built-in web server.
 *   3. Run in-process unit tests (signer, config) and HTTP tests (api, proxy).
 *   4. Merge the shared coverage log into an LCOV report and write a JUnit XML
 *      report for SonarQube ingestion.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$repo = dirname(__DIR__);
$work = $repo . '/tests/.work';
$results = $repo . '/tests/results';
$log = $work . '/coverage.log';

foreach ([$work, $work . '/webroot', $work . '/gen', $results] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0777, true);
    }
}
foreach (glob($work . '/*.log') ?: [] as $f) {
    @unlink($f);
}
@unlink($log);

require $repo . '/tests/lib/runner.php';
require $repo . '/tests/lib/cov.php';
require $repo . '/tests/lib/instrument.php';

TiktokCoverage\Cov::install();

$covPath = $repo . '/tests/lib/cov.php';
$masterToken = (function () use ($repo): string {
    $cfg = @include $repo . '/config.php';
    if (is_array($cfg) && !empty($cfg['master_token']) && is_string($cfg['master_token'])) {
        return $cfg['master_token'];
    }
    $env = getenv('TT_MASTER_TOKEN');
    if ($env !== false && $env !== '') {
        return $env;
    }
    $local = $repo . '/.test-token';
    if (is_readable($local)) {
        $t = trim((string) file_get_contents($local));
        if ($t !== '') {
            return $t;
        }
    }
    fwrite(STDERR, "Error: no master token. Set TT_MASTER_TOKEN, create .test-token, or populate config.php\n");
    exit(1);
})();

/** Build an instrumented copy of a source file (string) at $outPath. */
function instrumentSource(string $srcContent, string $realLabel, string $outPath, string $logPath, string $covPath): void
{
    $ins = new Instrumenter($srcContent, $realLabel, $logPath, $covPath);
    $ins->generate($outPath);
}

// ---- instrument the real files -------------------------------------------
instrumentSource(file_get_contents($repo . '/api.php'), $repo . '/api.php', $work . '/gen/api.php', $log, $covPath);
instrumentSource(file_get_contents($repo . '/proxy.php'), $repo . '/proxy.php', $work . '/gen/proxy.php', $log, $covPath);
instrumentSource(file_get_contents($repo . '/tiktok_signer.php'), $repo . '/tiktok_signer.php', $work . '/gen/tiktok_signer.php', $log, $covPath);
instrumentSource(file_get_contents($repo . '/config.php'), $repo . '/config.php', $work . '/gen/config.php', $log, $covPath);
instrumentSource(file_get_contents($repo . '/index.php'), $repo . '/index.php', $work . '/gen/index.php', $log, $covPath);
if (is_file($repo . '/tiktok_codec.php')) {
    instrumentSource(file_get_contents($repo . '/tiktok_codec.php'), $repo . '/tiktok_codec.php', $work . '/gen/tiktok_codec.php', $log, $covPath);
}

// ---- start servers -------------------------------------------------------
$mockPort = T::freePort();
$webPort = T::freePort();
$cdnPort = T::freePort();
if ($mockPort === 0 || $webPort === 0 || $cdnPort === 0) {
    fwrite(STDERR, "failed to get free ports\n");
    exit(1);
}

[$mockProc, $mockReady] = T::startServer($repo . '/tests/mock/mock.php', $mockPort, true);
[$webProc, $webReady] = T::startServer($work . '/webroot', $webPort, false);
[$cdnProc, $cdnReady] = T::startServer($repo . '/tests/mock/cdn.php', $cdnPort, true);
if (!$mockReady || !$webReady || !$cdnReady) {
    fwrite(STDERR, "server(s) failed to start\n");
    exit(1);
}

$mockBase = "http://127.0.0.1:$mockPort";
$webBase = "http://127.0.0.1:$webPort";

// webroot files MUST be generated AFTER the mock port is known
@unlink($work . '/webroot/api.php');
@unlink($work . '/webroot/proxy.php');
@unlink($work . '/webroot/config.php');
@unlink($work . '/webroot/index.php');

$cfg = file_get_contents($repo . '/config.php');
$cfg = str_replace("'webcast_url' => 'https://webcast.tiktok.com'", "'webcast_url' => '$mockBase'", $cfg);
$cfg = str_replace("'web_url' => 'https://www.tiktok.com'", "'web_url' => '$mockBase'", $cfg);
$cfg = preg_replace("#'master_token' => '\K[^']*'#", $masterToken . "'", $cfg, 1);
$cfg = str_replace("'enabled' => false,", "'enabled' => true,", $cfg);
$cfg = str_replace("'proxy' => '', // e.g., 'http://user:pass@host:port'", "'proxy' => '127.0.0.1:$cdnPort',", $cfg);
instrumentSource($cfg, $repo . '/config.php', $work . '/webroot/config.php', $log, $covPath);
copy($work . '/gen/api.php', $work . '/webroot/api.php');
copy($work . '/gen/proxy.php', $work . '/webroot/proxy.php');
copy($work . '/gen/index.php', $work . '/webroot/index.php');
if (is_file($work . '/gen/tiktok_codec.php')) copy($work . '/gen/tiktok_codec.php', $work . '/webroot/tiktok_codec.php');
elseif (is_file($repo . '/tiktok_codec.php')) copy($repo . '/tiktok_codec.php', $work . '/webroot/tiktok_codec.php');

// sanity: generated webroot must pass php -l before we run anything
foreach (['api.php', 'proxy.php', 'config.php', 'index.php', 'tiktok_codec.php'] as $f) {
    if (!is_file($work . '/webroot/' . $f) && !is_file($work . '/gen/' . $f)) continue;
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($work . '/webroot/' . $f) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, 'generated ' . $f . ' invalid: ' . implode("\n", $out) . "\n");
        exit(1);
    }
}

$api = $webBase . '/api.php';
$proxy = $webBase . '/proxy.php';

try {
    echo "servers up: mock=:$mockPort web=:$webPort\n";
    runApiTests($api, $masterToken);
    echo "api tests done\n";
    runProxyTests($proxy, $masterToken);
    echo "proxy tests done\n";

    T::case('index: login page renders without a token');
    [$s, $b] = T::http('GET', $webBase . '/index.php');
    T::assertSame(200, $s, 'status');
    T::assertContains('TikTok Live Monitor', $b, 'branding');
    T::assertContains('Access token', $b, 'login prompt');

    T::case('index: dashboard renders with ?token auto-login');
    [$s, $b] = T::http('GET', $webBase . '/index.php?token=' . urlencode($masterToken));
    T::assertSame(200, $s, 'status');
    T::assertContains('cards-container', $b, 'app shell');

    T::case('index: dashboard renders with valid cookie');
    [$s, $b] = T::http('GET', $webBase . '/index.php', [
        'Cookie: tt_token=' . urlencode($masterToken),
    ]);
    T::assertSame(200, $s, 'status');
    T::assertContains('cards-container', $b, 'app shell');
    echo "index tests done\n";

    runInProcessTests($repo, $work, $log, $covPath, $masterToken);
    echo "in-process tests done\n";
    TiktokCoverage\Cov::dump();
} finally {
    T::stopServer($mockProc);
    T::stopServer($webProc);
    T::stopServer($cdnProc);
}

// ---- write reports -------------------------------------------------------
writeCoverageLcov($repo, $work, $results, $log);

$xml = T::junit('tiktokliveurl', $repo);
file_put_contents($results . '/tests.xml', $xml);

$total = T::total();
$failures = T::failures();
echo "====================================\n";
echo "tests: $total  failures: $failures\n";
if ($failures > 0) {
    foreach (T::cases() as $c) {
        if ($c['status'] === 'FAIL') {
            echo '  FAIL ' . $c['name'] . " — " . $c['detail'] . "\n";
        }
    }
    exit(1);
}
echo "all green.\n";
exit(0);

// ---------------------------------------------------------------------------

function runApiTests(string $api, string $token): void
{
    $base = $api . '?token=' . urlencode($token);

    T::case('api: 401 without token');
    [$s, $b] = T::http('GET', $api . '?action=check&username=liveuser');
    T::assertSame(401, $s, 'status');
    T::assertContains('Unauthorized', $b, 'body');

    T::case('api: 400 for unknown action');
    [$s, $b] = T::http('GET', $base . '&action=fly');
    T::assertSame(400, $s, 'status');
    T::assertContains('Bad Request', $b, 'body');
    T::assertContains('Invalid action', $b, 'body');

    T::case('api: check username missing -> 400');
    [$s, $b] = T::http('GET', $base . '&action=check');
    T::assertSame(400, $s, 'status');
    T::assertContains('Username is required', $b, 'body');

    T::case('api: room_info room_id missing -> 400');
    [$s, $b] = T::http('GET', $base . '&action=room_info');
    T::assertSame(400, $s, 'status');
    T::assertContains('Room ID is required', $b, 'body');

    T::case('api: check live user');
    [$s, $b] = T::http('GET', $base . '&action=check&username=liveuser');
    T::assertSame(200, $s, 'status');
    $j = json_decode($b, true) ?? [];
    T::assertTrue(($j['success'] ?? false) === true, 'success');
    T::assertTrue(($j['data']['is_live'] ?? false) === true, 'is_live');
    T::assertSame('1111111111111', $j['data']['room_id'] ?? '', 'room_id');
    T::assertContains('pull-hls.example.com', $b, 'hls url present');
    T::assertTrue(!empty($j['data']['room']['stream_urls']['qualities']), 'qualities');

    T::case('api: check offline user (room known, not alive)');
    [$s, $b] = T::http('GET', $base . '&action=check&username=offlineuser');
    $j = json_decode($b, true) ?? [];
    T::assertSame(200, $s, 'status');
    T::assertTrue(($j['data']['is_live'] ?? true) === false, 'is_live');
    T::assertSame('2222222222222', $j['data']['room_id'] ?? '', 'room_id');

    T::case('api: check ghost-active user (check_alive data empty)');
    [$s, $b] = T::http('GET', $base . '&action=check&username=ghostactive');
    $j = json_decode($b, true) ?? [];
    T::assertTrue(($j['data']['is_live'] ?? true) === false, 'is_live');

    T::case('api: check unknown user (no room anywhere)');
    [$s, $b] = T::http('GET', $base . '&action=check&username=apilonely');
    $j = json_decode($b, true) ?? [];
    T::assertTrue(($j['data']['is_live'] ?? true) === false, 'is_live');
    T::assertSame(null, $j['data']['room_id'] ?? null, 'room_id null');
    T::assertContains('Could not resolve room ID', $b, 'debug');

    T::case('api: check user found only via SIGI_STATE html');
    [$s, $b] = T::http('GET', $base . '&action=check&username=obscured');
    $j = json_decode($b, true) ?? [];
    T::assertSame('3333333333333', $j['data']['room_id'] ?? '', 'room_id');
    T::assertTrue(($j['data']['is_live'] ?? true) === false, 'is_live');

    T::case('api: check user found via plain roomId regex fallback');
    [$s, $b] = T::http('GET', $base . '&action=check&username=htmlonly');
    $j = json_decode($b, true) ?? [];
    T::assertSame('4444444444444', $j['data']['room_id'] ?? '', 'room_id');

    T::case('api: extractUsername — full URL input');
    [$s, $b] = T::http('GET', $base . '&action=check&username=' . urlencode('https://www.tiktok.com/@liveuser'));
    $j = json_decode($b, true) ?? [];
    T::assertSame('1111111111111', $j['data']['room_id'] ?? '', 'room_id');

    T::case('api: extractUsername — @-prefixed input');
    [$s, $b] = T::http('GET', $base . '&action=check&username=' . urlencode('@liveuser'));
    $j = json_decode($b, true) ?? [];
    T::assertSame('1111111111111', $j['data']['room_id'] ?? '', 'room_id');

    T::case('api: check user with api error status (nosource)');
    [$s, $b] = T::http('GET', $base . '&action=check&username=nosource');
    $j = json_decode($b, true) ?? [];
    T::assertTrue(($j['data']['is_live'] ?? true) === false, 'is_live');

    T::case('api: check user with malformed api json (badjson)');
    [$s, $b] = T::http('GET', $base . '&action=check&username=badjson');
    $j = json_decode($b, true) ?? [];
    T::assertTrue(($j['data']['is_live'] ?? true) === false, 'is_live');

    T::case('api: check user with api http 500 (err500)');
    [$s, $b] = T::http('GET', $base . '&action=check&username=err500');
    $j = json_decode($b, true) ?? [];
    T::assertTrue(($j['data']['is_live'] ?? true) === false, 'is_live');

    T::case('api: room_info full payload');
    [$s, $b] = T::http('GET', $base . '&action=room_info&room_id=1111111111111');
    $j = json_decode($b, true) ?? [];
    T::assertSame(200, $s, 'status');
    T::assertTrue(($j['success'] ?? false) === true, 'success');
    T::assertSame('Test Live', $j['data']['title'] ?? '', 'title');
    T::assertSame(1234, $j['data']['viewer_count'] ?? -1, 'viewer_count');
    T::assertSame(10, $j['data']['stream_urls']['qualities'][0]['level'] ?? -1, 'qualities sorted');
    T::assertSame('https://pull-f5.example.com/hd.flv', $j['data']['stream_urls']['flv']['720p'] ?? '', 'flv 720p');

    T::case('api: room_info 4003110 -> push/flow fallback ok');
    [$s, $b] = T::http('GET', $base . '&action=room_info&room_id=5555555555555');
    $j = json_decode($b, true) ?? [];
    T::assertTrue(($j['success'] ?? false) === true, 'success');
    T::assertSame('https://pull-f5.example.com/sd2.flv', $j['data']['stream_urls']['flv']['480p'] ?? '', 'flv 480p');

    T::case('api: room_info 4003110 -> push/flow empty -> restricted');
    [$s, $b] = T::http('GET', $base . '&action=room_info&room_id=6666666666666');
    $j = json_decode($b, true) ?? [];
    T::assertTrue(($j['success'] ?? false) === false, 'success false');
    T::assertContains('Access restricted (4003110)', $b, 'error');

    T::case('api: room_info api error status');
    [$s, $b] = T::http('GET', $base . '&action=room_info&room_id=7777777777777');
    $j = json_decode($b, true) ?? [];
    T::assertTrue(($j['success'] ?? false) === false, 'success false');
    T::assertContains('API error', $b, 'error');

    T::case('api: room_info invalid json');
    [$s, $b] = T::http('GET', $base . '&action=room_info&room_id=8888888888888');
    $j = json_decode($b, true) ?? [];
    T::assertTrue(($j['success'] ?? false) === false, 'success false');
    T::assertContains('Invalid JSON', $b, 'error');

    T::case('api: room_info upstream http failure');
    [$s, $b] = T::http('GET', $base . '&action=room_info&room_id=9999999999999');
    $j = json_decode($b, true) ?? [];
    T::assertTrue(($j['success'] ?? false) === false, 'success false');
    T::assertContains('HTTP request failed', $b, 'error');

    T::case('api: OPTIONS preflight with cors enabled');
    [$s, $b] = T::http('OPTIONS', $api . '?action=check&username=liveuser');
    T::assertSame(200, $s, 'status');

    T::case('api: Authorization Bearer header accepted');
    [$s, $b] = T::http('GET', $api . '?action=check&username=liveuser', ['Authorization: Bearer ' . $token]);
    if (str_contains($b, 'Unauthorized')) {
        // PHP built-in server may not surface HTTP_AUTHORIZATION; skip quietly
        T::fail('bearer header not surfaced by SAPI (skip)');
    } else {
        $j = json_decode($b, true) ?? [];
        T::assertTrue(($j['success'] ?? false) === true, 'success');
    }
}

function runProxyTests(string $proxy, string $token): void
{
    $base = $proxy . '?token=' . urlencode($token);

    T::case('proxy: 401 without token');
    [$s, $b] = T::http('GET', $proxy . '?url=' . urlencode('https://lf16.example.com/x.flv'));
    T::assertSame(401, $s, 'status');
    T::assertContains('Unauthorized', $b, 'body');

    T::case('proxy: 400 missing url');
    [$s, $b] = T::http('GET', $base);
    T::assertSame(400, $s, 'status');
    T::assertContains('Missing url', $b, 'body');

    T::case('proxy: 400 invalid url');
    [$s, $b] = T::http('GET', $base . '&url=' . urlencode('not a url'));
    T::assertSame(400, $s, 'status');
    T::assertContains('Invalid URL', $b, 'body');

    T::case('proxy: 403 host not in allowlist');
    [$s, $b] = T::http('GET', $base . '&url=' . urlencode('https://evil.example.com/x.flv'));
    T::assertSame(403, $s, 'status');
    T::assertContains('not in allowed list', $b, 'body');

    // The remaining tests route CDN traffic to tests/mock/cdn.php through
    // CURLOPT_PROXY (config 'proxy'), so they exercise the real HEAD, HLS and
    // FLV branches without any network access.

    $cdn = 'http://tiktokcdn-eu.com/stage/stream-111.flv?x=1';

    T::case('proxy: HEAD relays live CDN as 200');
    [$s] = T::http('HEAD', $base . '&url=' . urlencode($cdn));
    T::assertSame(200, $s, 'status');

    T::case('proxy: HEAD follows upstream 302 redirect to 200');
    [$s] = T::http('HEAD', $base . '&url=' . urlencode('http://tiktokcdn-eu.com/stage/redirect?r=1'));
    T::assertSame(200, $s, 'status');

    T::case('proxy: HEAD blocks redirect to non-allowlisted host');
    [$s] = T::http('HEAD', $base . '&url=' . urlencode('http://tiktokcdn-eu.com/stage/badredirect'));
    T::assertSame(502, $s, 'status');

    T::case('proxy: FLV blocks redirect to non-allowlisted host');
    [$s] = T::http('GET', $base . '&url=' . urlencode('http://tiktokcdn-eu.com/stage/badredirect.flv'));
    T::assertSame(502, $s, 'status');

    T::case('proxy: HEAD relays CDN 403 (Access Denied)');
    [$s] = T::http('HEAD', $base . '&url=' . urlencode('http://tiktokcdn-eu.com/stage/denied.flv?x=2'));
    T::assertSame(403, $s, 'status');

    T::case('proxy: FLV GET relays stream bytes');
    [$s, $b, $h] = T::http('GET', $base . '&url=' . urlencode($cdn));
    T::assertSame(200, $s, 'status');
    T::assertSame('video/x-flv', $h['content-type'] ?? '', 'content-type');
    T::assertTrue(strlen($b) > 100, 'payload bytes');
    T::assertSame('FLV', substr($b, 0, 3), 'FLV magic');

    T::case('proxy: FLV GET maps upstream 403 to 502');
    [$s] = T::http('GET', $base . '&url=' . urlencode('http://tiktokcdn-eu.com/stage/denied.flv?x=3'));
    T::assertSame(502, $s, 'status');

    T::case('proxy: HLS playlist is rewritten to proxy URLs');
    [$s, $b, $h] = T::http('GET', $base . '&url=' . urlencode('http://tiktokcdn-eu.com/stage/media.m3u8'));
    T::assertSame(200, $s, 'status');
    T::assertSame('application/vnd.apple.mpegurl', $h['content-type'] ?? '', 'content-type');
    T::assertContains('proxy.php?url=', $b, 'segment rewrite');
    T::assertContains('http%3A%2F%2Ftiktokcdn-eu.com', $b, 'segment proxied');

    T::case('proxy: m3u8 bad upstream maps to 502');
    [$s] = T::http('GET', $base . '&url=' . urlencode('http://tiktokcdn-eu.com/stage/denied.m3u8'));
    T::assertSame(502, $s, 'status');

    T::case('proxy: bad upstream plain FLV maps to 502');
    [$s] = T::http('GET', $base . '&url=' . urlencode('http://tiktokcdn-eu.com/stage/denied.flv?x=4'));
    T::assertSame(502, $s, 'status');
}

function runInProcessTests(string $repo, string $work, string $log, string $covPath, string $masterToken): void
{
    require $work . '/gen/tiktok_signer.php';

    T::case('config: loads and exposes expected shape');
    $cfg = require $work . '/gen/config.php';
    T::assertTrue(is_array($cfg), 'array');
    T::assertSame($masterToken, $cfg['master_token'] ?? '', 'token roundtrip');
    T::assertContains('https://webcast.tiktok.com', $cfg['tiktok']['webcast_url'] ?? '', 'webcast_url');
    T::assertTrue(is_array($cfg['cors'] ?? null), 'cors block');

    T::case('signer: produces base64 alphabet string');
    $ua = 'Mozilla/5.0 test';
    $sig = TikTokSigner::encode('aid=1988&room_id=123', '', $ua);
    T::assertTrue(is_string($sig) && $sig !== '', 'nonempty');
    T::assertSame(0, strlen($sig) % 4, 'base64 length multiple of 4');
    T::assertSame(0, preg_match('/[^A-Za-z0-9\+\/\-=]/', $sig), 'alphabet only');
    T::assertTrue(strlen($sig) >= 64, 'reasonable length');

    T::case('signer: different queries give different signatures');
    $sigA = TikTokSigner::encode('aid=1988&room_id=1', '', $ua);
    $sigB = TikTokSigner::encode('aid=1988&room_id=2', '', $ua);
    T::assertTrue($sigA !== $sigB, 'different inputs');

    T::case('signer: encodes without crashing on edge inputs');
    $sigC = TikTokSigner::encode('', '', '', ['totalXHR' => 1, 'interceptedXHR' => 1], ['ubcode' => 1]);
    T::assertTrue(is_string($sigC) && $sigC !== '', 'nonempty');

    T::case('signer: payload encoding tolerates missing optional fields');
    $encPayload = new \ReflectionMethod(TikTokSigner::class, 'encodePayload');
    $payload = $encPayload->invoke(null, [1 => 65]);
    T::assertTrue(is_array($payload) && $payload !== [], 'bytes array');

    T::case('signer: payload encoding rejects float field values');
    try {
        $encPayload->invoke(null, [1 => 65, 2 => 1.5]);
        T::assertTrue(false, 'expected InvalidArgumentException');
    } catch (\InvalidArgumentException) {
        T::assertTrue(true, 'threw as expected');
    }

    T::case('signer: intToBytes rejects negatives');
    $intBytes = new \ReflectionMethod(TikTokSigner::class, 'intToBytes');
    try {
        $intBytes->invoke(null, -5);
        T::assertTrue(false, 'expected InvalidArgumentException');
    } catch (\InvalidArgumentException) {
        T::assertTrue(true, 'threw as expected');
    }

    T::case('signer: base64 tail handles remainder 2 bytes');
    $encB64 = new \ReflectionMethod(TikTokSigner::class, 'encodeBase64');
    $tail = $encB64->invoke(null, array_fill(0, 233, 0));
    T::assertTrue(is_string($tail) && $tail !== '', 'nonempty');
    T::assertSame('=', substr($tail, -1), 'single pad char');
}

/**
 * Merge the shared coverage log into a PHPUnit Clover XML report (the format
 * the Sonar PHP analyzer's PHPUnit report sensor imports) plus an LCOV file.
 * Statement lines are those present as \TiktokCoverage\Cov::hit('...', N)
 * markers in the instrumented copies.
 */
function writeCoverageLcov(string $repo, string $work, string $results, string $log): void
{
    $hits = [];
    if (file_exists($log)) {
        foreach (preg_split('/\R/', file_get_contents($log)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strrpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $file = substr($line, 0, $pos);
            $num = (int) substr($line, $pos + 1);
            if ($file !== '') {
                $hits[$file][$num] = 1;
            }
        }
    }

    $labelToHitMap = [];
    foreach ($hits as $real => $lines) {
        $labelToHitMap[$real] = $lines;
    }

    $clover = [];
    $clover[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $clover[] = '<coverage generated="' . time() . '">';
    $clover[] = '  <project timestamp="' . time() . '">';

    $lcov = ["TN:"];
    $files = ['api.php', 'proxy.php', 'tiktok_signer.php', 'config.php'];
    foreach ($files as $f) {
        $real = $repo . '/' . $f;
        $gen = $work . '/gen/' . $f;
        if (!is_file($gen)) {
            continue;
        }
        $statements = [];
        $genSrc = file_get_contents($gen);
        if (preg_match_all("/Cov::hit\('(?:[^'\\\\]|\\\\'|\\\\\\\\)*', (\d+)\);/", $genSrc, $m)) {
            foreach ($m[1] as $n) {
                $statements[(int) $n] = 1;
            }
        }
        ksort($statements);
        $hit = $labelToHitMap[$real] ?? [];
        $covered = count(array_intersect_key($hit, $statements));

        $clover[] = '    <file name="' . $repo . '/' . $f . '">';
        foreach ($statements as $line => $_) {
            $count = isset($hit[$line]) ? 1 : 0;
            $clover[] = '      <line num="' . $line . '" type="stmt" count="' . $count . '"/>';
        }
        $clover[] = '      <metrics statements="' . count($statements) . '" coveredstatements="' . $covered . '"/>';
        $clover[] = '    </file>';

        $lcov[] = '';
        $lcov[] = 'SF:' . $f;
        foreach ($statements as $line => $_) {
            $count = isset($hit[$line]) ? 1 : 0;
            $lcov[] = 'DA:' . $line . ',' . $count;
        }
        $lcov[] = 'LF:' . count($statements);
        $lcov[] = 'LH:' . $covered;
        $lcov[] = 'end_of_record';
    }

    $clover[] = '    <metrics files="' . count($files) . '"/>';
    $clover[] = '  </project>';
    $clover[] = '</coverage>';

    file_put_contents($results . '/clover.xml', implode("\n", $clover) . "\n");
    file_put_contents($results . '/coverage.lcov', implode("\n", $lcov) . "\n");
    echo 'coverage: ' . implode(',', array_map(fn ($f) => "$f:" . count($labelToHitMap[$repo . '/' . $f] ?? []), $files)) . " hits\n";
}