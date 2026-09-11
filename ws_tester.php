<?php
/**
 * ws_tester.php — serv00 WebSocket capability probe for PHP
 *
 * Deploy to serv00: https://bossking.serv00.net/tiktokliveurl/ws_tester.php?token=YOUR_MASTER_TOKEN
 * CLI:              php ws_tester.php [--json] [--token=xxx]
 *
 * Checks:
 *   1) PHP version / extensions (sockets, zlib, openssl, curl, pcntl)
 *   2) Disabled functions / allow_url_fopen / stream wrappers
 *   3) Composer deps (Ratchet/Pawl + ReactPHP) presence
 *   4) Raw TCP/TLS outbound (stream_socket_client / fsockopen)
 *   5) Real WebSocket HTTP Upgrade handshake to public echo servers
 *   6) Optional Ratchet/Pawl end-to-end echo test (if deps installed)
 *   7) TikTok WSS host TCP reachability (not full sign, just connect)
 *
 * No persistent daemon, no exec(), no inbound bind — pure outbound checks
 * compatible with shared-hosting PHP-FPM constraints.
 *
 * Query params:
 *   token=xxx  — master token (same as api.php) if config.php defines one
 *   format=json|html (default: auto by Accept header, or html)
 *   timeout=5  — per-test timeout seconds (default 5, max 10)
 */

$config = [];
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
    $c = require $configFile;
    if (is_array($c)) $config = $c;
}
$masterToken = $config['master_token'] ?? '';

// --- token gate: CLI always allowed; web requires token if master_token set ---
$isCli = PHP_SAPI === 'cli';
$providedToken = $_GET['token'] ?? $_GET['t'] ?? null;
if (!$isCli && $masterToken !== '' && $masterToken !== 'CHANGE_ME_TO_A_RANDOM_SECRET') {
    // allow token via Authorization header as well (like api.php)
    if (!$providedToken && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $providedToken = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
    }
    if ($providedToken === null || !hash_equals((string)$masterToken, (string)$providedToken)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'Unauthorized',
            'hint' => 'Pass ?token=YOUR_MASTER_TOKEN (same as api.php). See config.php master_token.',
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

$timeout = (int)($_GET['timeout'] ?? 5);
$timeout = max(1, min(10, $timeout));
if ($isCli) {
    foreach ($argv as $a) {
        if (preg_match('/^--timeout=(\d+)/', $a, $m)) $timeout = max(1, min(10, (int)$m[1]));
        if (preg_match('/^--token=(.+)/', $a, $m)) $providedToken = $m[1];
    }
}
$wantJson = isset($_GET['format']) ? $_GET['format'] === 'json' : false;
if ($isCli) {
    foreach ($argv as $a) if ($a === '--json') $wantJson = true;
}
if (!$isCli && !$wantJson && isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
    $wantJson = true;
}

// ------------------------------------------------------------------ helpers
function hsc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function checkExt(string $name): array {
    $loaded = extension_loaded($name);
    $ver = $loaded ? phpversion($name) : null;
    return ['loaded' => $loaded, 'version' => $ver];
}

function wsHandshake(string $host, int $port, string $path, bool $tls, int $timeout): array {
    $scheme = $tls ? 'ssl' : 'tcp';
    $remote = "{$scheme}://{$host}:{$port}";
    $key = base64_encode(random_bytes(16));
    $req = "GET {$path} HTTP/1.1\r\n"
         . "Host: {$host}:{$port}\r\n"
         . "Upgrade: websocket\r\n"
         . "Connection: Upgrade\r\n"
         . "Sec-WebSocket-Key: {$key}\r\n"
         . "Sec-WebSocket-Version: 13\r\n"
         . "Origin: https://www.tiktok.com\r\n"
         . "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
         . "\r\n";
    $start = microtime(true);
    $ctx = stream_context_create([
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
    ]);
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return ['ok' => false, 'error' => "stream_socket_client failed: {$errno} {$errstr}", 'time_ms' => (int)((microtime(true)-$start)*1000)];
    }
    stream_set_timeout($fp, $timeout);
    fwrite($fp, $req);
    $resp = fread($fp, 4096);
    $meta = stream_get_meta_data($fp);
    fclose($fp);
    $elapsed = (int)((microtime(true)-$start)*1000);
    if ($resp === false || $resp === '') {
        return ['ok' => false, 'error' => 'empty response (timeout? firewall?)', 'time_ms' => $elapsed, 'timed_out' => $meta['timed_out'] ?? false];
    }
    $firstLine = strtok($resp, "\r\n");
    $code = 0; if (preg_match('#HTTP/\S+\s+(\d+)#', $firstLine, $m)) $code = (int)$m[1];
    $is101 = $code === 101 && stripos($resp, '101') !== false && stripos($resp, 'Upgrade: websocket') !== false || stripos($resp, 'Sec-WebSocket-Accept') !== false;
    // Some echo servers return 101 with lower-case headers
    if (!$is101 && $code === 101) $is101 = true;
    return [
        'ok' => $is101,
        'http_code' => $code,
        'status_line' => $firstLine,
        'headers_snippet' => substr($resp, 0, 800),
        'time_ms' => $elapsed,
        'error' => $is101 ? null : "expected 101 Switching Protocols, got {$code}",
    ];
}

function tcpProbe(string $host, int $port, bool $tls, int $timeout): array {
    $scheme = $tls ? 'ssl' : 'tcp';
    $remote = "{$scheme}://{$host}:{$port}";
    $start = microtime(true);
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    $elapsed = (int)((microtime(true)-$start)*1000);
    if (!$fp) return ['ok'=>false,'error'=>"{$errno} {$errstr}",'time_ms'=>$elapsed];
    fclose($fp);
    return ['ok'=>true,'time_ms'=>$elapsed];
}

function composerDepCheck(): array {
    $out = [];
    $checks = [
        'ratchet/pawl' => 'Ratchet\\Client\\Connector',
        'ratchet/rfc6455' => 'Ratchet\\RFC6455\\Handshake\\ClientNegotiator',
        'react/event-loop' => 'React\\EventLoop\\Loop',
        'react/socket' => 'React\\Socket\\Connector',
        'guzzlehttp/guzzle' => 'GuzzleHttp\\Client',
        'evenement/evenement' => 'Evenement\\EventEmitter',
    ];
    $vendorAutoload = __DIR__ . '/vendor/autoload.php';
    $autoloadExists = is_file($vendorAutoload);
    foreach ($checks as $pkg => $cls) {
        $out[$pkg] = ['class' => $cls, 'present' => class_exists($cls) || interface_exists($cls)];
    }
    $out['_autoload'] = $autoloadExists;
    if ($autoloadExists && !class_exists('React\\EventLoop\\Loop')) {
        // try loading
        @require_once $vendorAutoload;
        foreach ($checks as $pkg => $cls) {
            $out[$pkg]['present'] = class_exists($cls) || interface_exists($cls);
        }
    }
    return $out;
}

// ------------------------------------------------------------------ collect
$results = [];
$results['meta'] = [
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'os' => PHP_OS_FAMILY . ' ' . php_uname(),
    'time' => date('c'),
    'timeout_s' => $timeout,
    'open_basedir' => ini_get('open_basedir') ?: null,
    'disable_functions' => array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions')))),
    'allow_url_fopen' => ini_get('allow_url_fopen'),
    'max_execution_time' => ini_get('max_execution_time'),
];

$results['extensions'] = [
    'sockets' => checkExt('sockets'),
    'zlib' => checkExt('zlib'),
    'openssl' => checkExt('openssl'),
    'curl' => checkExt('curl'),
    'mbstring' => checkExt('mbstring'),
    'pcntl' => checkExt('pcntl'),
    'json' => checkExt('json'),
    'simplexml' => checkExt('simplexml'),
];

$results['functions'] = [
    'fsockopen' => function_exists('fsockopen'),
    'stream_socket_client' => function_exists('stream_socket_client'),
    'socket_create' => function_exists('socket_create'),
    'stream_get_wrappers' => stream_get_wrappers(),
    'stream_transports' => stream_get_transports(),
];

$results['composer'] = composerDepCheck();
$results['curl'] = [
    'present' => function_exists('curl_version'),
    'version' => function_exists('curl_version') ? curl_version()['version'] ?? null : null,
    'ssl_version' => function_exists('curl_version') ? curl_version()['ssl_version'] ?? null : null,
];

// raw TCP/TLS outbound probes
$results['tcp'] = [
    'google_443_tls' => tcpProbe('google.com', 443, true, $timeout),
    'echo_ws_postman_443' => tcpProbe('ws.postman-echo.com', 443, true, $timeout),
    'echo_websocket_events_443' => tcpProbe('echo.websocket.events', 443, true, $timeout),
    'tiktok_webcast_ws_443' => tcpProbe('webcast-ws.tiktok.com', 443, true, $timeout),
    'tiktok_webcast_443' => tcpProbe('webcast.tiktok.com', 443, true, $timeout),
    'euler_ws_443' => tcpProbe('ws.eulerstream.com', 443, true, $timeout),
];

// WebSocket handshake probes (public echo servers + TikTok)
$results['ws_handshake'] = [
    // wss echo servers (choose 2 reliable)
    'wss_postman_echo' => wsHandshake('ws.postman-echo.com', 443, '/raw', true, $timeout),
    'wss_echo_websocket_events' => wsHandshake('echo.websocket.events', 443, '/', true, $timeout),
    // TikTok WSS (no signing, just see if TCP+HTTP upgrade is reachable — expect 400/403, not 101, but TCP ok)
    'wss_tiktok_webcast_ws' => wsHandshake('webcast-ws.tiktok.com', 443, '/webcast/im/ws_proxy/ws_reuse_supplement/?aid=1988&room_id=1', true, $timeout),
];

// Optional ReactPHP/Pawl end-to-end echo test (only if deps present)
$results['pawl_echo'] = ['skipped' => 'deps not installed'];
if (($results['composer']['ratchet/pawl']['present'] ?? false) && ($results['composer']['react/event-loop']['present'] ?? false)) {
    $vendorAutoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($vendorAutoload)) require_once $vendorAutoload;
    try {
        if (class_exists('React\\EventLoop\\Loop') && class_exists('Ratchet\\Client\\Connector')) {
            $loop = React\EventLoop\Loop::get();
            $connector = new Ratchet\Client\Connector($loop);
            $url = 'wss://ws.postman-echo.com/raw';
            $done = ['ok'=>false,'error'=>'timeout','messages'=>[]];
            $timer = $loop->addTimer($timeout, function() use ($loop, &$done) {
                $done['error'] = 'timeout after ' . $GLOBALS['timeout'] . 's';
                $loop->stop();
            });
            $connector($url)->then(
                function (Ratchet\Client\WebSocket $conn) use (&$done, $loop, $timer) {
                    $loop->cancelTimer($timer);
                    $conn->on('message', function($msg) use (&$done, $conn, $loop) {
                        $done['ok'] = true;
                        $done['messages'][] = (string)$msg;
                        $conn->close();
                        $loop->stop();
                    });
                    $conn->send('hello-from-serv00-tester-' . time());
                    // safety close after 3s if no echo
                    $loop->addTimer(3, function() use ($conn, $loop) { $conn->close(); $loop->stop(); });
                },
                function (\Throwable $e) use (&$done, $loop, $timer) {
                    $loop->cancelTimer($timer);
                    $done['ok'] = false;
                    $done['error'] = $e->getMessage();
                    $loop->stop();
                }
            );
            $start = microtime(true);
            $loop->run();
            $done['time_ms'] = (int)((microtime(true)-$start)*1000);
            $results['pawl_echo'] = $done;
        }
    } catch (\Throwable $e) {
        $results['pawl_echo'] = ['ok'=>false,'error'=>$e->getMessage()];
    }
}

// Verdict
$hasSockets = $results['extensions']['sockets']['loaded'];
$hasZlib = $results['extensions']['zlib']['loaded'];
$hasOpenssl = $results['extensions']['openssl']['loaded'];
$hasStreams = in_array('ssl', $results['functions']['stream_transports'] ?? [], true);
$tcpOk = ($results['tcp']['echo_ws_postman_443']['ok'] ?? false) || ($results['tcp']['echo_websocket_events_443']['ok'] ?? false);
$wsOk = ($results['ws_handshake']['wss_postman_echo']['ok'] ?? false) || ($results['ws_handshake']['wss_echo_websocket_events']['ok'] ?? false);
$pawlOk = ($results['pawl_echo']['ok'] ?? false);

$verdict = 'UNKNOWN';
$reason = '';
if (!$hasSockets && !$results['functions']['stream_socket_client']) {
    $verdict = 'NO_WEBSOCKET';
    $reason = 'No sockets/stream_socket_client — outbound TCP impossible.';
} elseif (!$hasOpenssl || !$hasStreams) {
    $verdict = 'NO_WSS';
    $reason = 'OpenSSL/ssl transport missing — wss:// will fail (ws:// plain may work).';
} elseif (!$tcpOk) {
    $verdict = 'NO_OUTBOUND';
    $reason = 'TCP to public WSS hosts blocked (firewall).';
} elseif (!$wsOk) {
    $verdict = 'PARTIAL';
    $reason = 'TCP ok but WebSocket 101 handshake failed — firewall/MITM or WSS blocked; ws:// may still work but tiktok/euler need wss.';
} elseif ($pawlOk) {
    $verdict = 'WSS_OK';
    $reason = 'Full Ratchet/Pawl WSS echo succeeded — outbound wss works and deps present.';
} elseif ($wsOk && !$results['composer']['ratchet/pawl']['present']) {
    $verdict = 'WSS_OK_NO_DEPS';
    $reason = 'Raw WSS handshake succeeded; composer deps (ratchet/pawl + react/event-loop) not installed but would work if installed.';
} else {
    $verdict = 'WSS_OK';
    $reason = 'Raw WSS handshake succeeded.';
}
$results['verdict'] = ['code'=>$verdict, 'reason'=>$reason, 'human'=>[
    'WSS_OK' => '✅ serv00 PHP can open outbound wss:// — live comments via managed WSS (Euler/TikTool) or raw TikTok WSS are feasible (outbound). Long-running daemons still not viable on FPM — use client-side JS WSS or short poll.',
    'WSS_OK_NO_DEPS' => '✅ WSS works but composer deps missing — run: composer require ratchet/pawl react/event-loop react/socket evenement/evenement guzzlehttp/guzzle',
    'PARTIAL' => '⚠️ TCP ok but WS Upgrade rejected — likely firewall blocks 101 or echo server down. Try again or test ws:// (non-TLS). TikTok wss may still be blocked.',
    'NO_WSS' => '❌ No TLS — wss:// impossible. TikTok/Euler require wss.',
    'NO_OUTBOUND' => '❌ Outbound TCP blocked — no WebSocket possible from PHP.',
    'NO_WEBSOCKET' => '❌ No socket support at all.',
    'UNKNOWN' => 'Unknown state.',
][$verdict] ?? $reason];

// ------------------------------------------------------------------ output
if ($wantJson || $isCli) {
    if (!$isCli) header('Content-Type: application/json; charset=utf-8');
    $cliPretty = $isCli && !in_array('--json', $argv ?? [], true);
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($isCli) echo "\n";
    // CLI human summary
    if ($isCli) {
        fwrite(STDERR, "\n=== VERDICT: {$verdict} ===\n{$reason}\n{$results['verdict']['human']}\n");
    }
    exit;
}

// HTML
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>serv00 WebSocket Tester — tiktokliveurl</title>
<style>
*{box-sizing:border-box}body{font-family:ui-monospace,Menlo,Consolas,monospace;margin:0;background:#0f1115;color:#e6e6e6;padding:24px;line-height:1.5}
h1{font-size:18px;margin:0 0 12px}h2{font-size:14px;color:#9aa3b2;margin:22px 0 8px;border-bottom:1px solid #222;padding-bottom:6px}
table{width:100%;border-collapse:collapse;font-size:13px}th,td{border:1px solid #2a2f3a;padding:6px 8px;text-align:left}
th{background:#171a21;color:#9aa3b2}td.ok{color:#3dd68c}td.fail{color:#ff5c5c}td.warn{color:#ffb86b}
code{background:#171a21;padding:2px 6px;border-radius:4px;font-size:12px} .pill{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:700;font-size:13px}
.pill-WSS_OK{background:#0f2e1f;color:#3dd68c;border:1px solid #1a5a35}.pill-WSS_OK_NO_DEPS{background:#2a2e0f;color:#d6c93d;border:1px solid #5a551a}
.pill-PARTIAL{background:#2e1f0f;color:#ffb86b;border:1px solid #5a3a1a}.pill-NO_WSS,.pill-NO_OUTBOUND,.pill-NO_WEBSOCKET{background:#2e0f0f;color:#ff5c5c;border:1px solid #5a1a1a}
pre{white-space:pre-wrap;word-break:break-all;background:#171a21;padding:10px;border-radius:6px;font-size:12px}
a{color:#6aa9ff}
</style>
</head><body>
<h1>serv00 WebSocket Tester <small style="color:#9aa3b2">tiktokliveurl</small></h1>
<p>Token-gated like <code>api.php:token</code> · <code>?format=json</code> for JSON · <code>?timeout=5</code> · CLI: <code>php ws_tester.php --json</code></p>

<div style="margin:12px 0">
  <span class="pill pill-<?=hsc($verdict)?>"><?=hsc($verdict)?></span>
  <span style="margin-left:10px;color:#9aa3b2"><?=hsc($reason)?></span>
  <div style="margin-top:8px;color:#cbd5e1"><?=hsc($results['verdict']['human'])?></div>
</div>

<h2>0. Meta</h2>
<table><tr><th>PHP</th><td><?=hsc($results['meta']['php_version'])?> (<?=hsc($results['meta']['sapi'])?>)</td><th>OS</th><td><?=hsc($results['meta']['os'])?></td></tr>
<tr><th>Time</th><td><?=hsc($results['meta']['time'])?></td><th>max_execution_time</th><td><?=hsc($results['meta']['max_execution_time'])?></td></tr>
<tr><th>allow_url_fopen</th><td class="<?= $results['meta']['allow_url_fopen']? 'ok':'fail' ?>"><?=hsc((string)$results['meta']['allow_url_fopen'])?></td><th>open_basedir</th><td><?=hsc((string)($results['meta']['open_basedir'] ?? '—'))?></td></tr>
<tr><th>disable_functions</th><td colspan="3" style="font-size:11px"><?=hsc(implode(', ', $results['meta']['disable_functions']) ?: '— none —')?></td></tr>
</table>

<h2>1. Extensions</h2>
<table><tr><th>ext</th><th>loaded</th><th>version</th></tr>
<?php foreach($results['extensions'] as $k=>$v): ?>
<tr><th><?=hsc($k)?></th><td class="<?= $v['loaded']?'ok':'fail' ?>"><?= $v['loaded']?'YES':'NO' ?></td><td><?=hsc((string)($v['version']??'—'))?></td></tr>
<?php endforeach; ?>
</table>

<h2>2. Functions / Transports</h2>
<table>
<tr><th>fsockopen</th><td class="<?= $results['functions']['fsockopen']?'ok':'fail' ?>"><?= $results['functions']['fsockopen']?'YES':'NO' ?></td><th>stream_socket_client</th><td class="<?= $results['functions']['stream_socket_client']?'ok':'fail' ?>"><?= $results['functions']['stream_socket_client']?'YES':'NO' ?></td></tr>
<tr><th>socket_create</th><td class="<?= $results['functions']['socket_create']?'ok':'fail' ?>"><?= $results['functions']['socket_create']?'YES':'NO' ?></td><th>curl</th><td class="<?= ($results['curl']['present']??false)?'ok':'fail' ?>"><?=hsc(($results['curl']['version']??'—').' / '.($results['curl']['ssl_version']??''))?></td></tr>
<tr><th>stream wrappers</th><td colspan="3" style="font-size:11px"><?=hsc(implode(', ', $results['functions']['stream_get_wrappers']??[]))?></td></tr>
<tr><th>stream transports</th><td colspan="3" style="font-size:11px"><?=hsc(implode(', ', $results['functions']['stream_transports']??[]))?></td></tr>
</table>

<h2>3. Composer deps (for Pawl/ReactPHP daemon)</h2>
<table><tr><th>package</th><th>class</th><th>present</th></tr>
<?php foreach($results['composer'] as $pkg=>$info): if($pkg==='_autoload') continue; ?>
<tr><th><?=hsc($pkg)?></th><td style="font-size:11px"><?=hsc($info['class'])?></td><td class="<?= $info['present']?'ok':'fail' ?>"><?= $info['present']?'YES':'NO' ?></td></tr>
<?php endforeach; ?>
<tr><th>vendor/autoload.php</th><td colspan="2" class="<?= $results['composer']['_autoload']?'ok':'fail' ?>"><?= $results['composer']['_autoload']?'YES':'NO' ?></td></tr>
</table>
<p style="font-size:12px;color:#9aa3b2">If NO, install on VPS (not needed for client-side JS WSS): <code>composer require ratchet/pawl react/event-loop react/socket evenement/evenement guzzlehttp/guzzle</code></p>

<h2>4. Raw TCP/TLS outbound</h2>
<table><tr><th>target</th><th>TLS</th><th>ok</th><th>ms</th><th>error</th></tr>
<?php foreach($results['tcp'] as $k=>$v): ?>
<tr><th><?=hsc($k)?></th><td>443/TLS</td><td class="<?= ($v['ok']??false)?'ok':'fail' ?>"><?= ($v['ok']??false)?'YES':'NO' ?></td><td><?=hsc((string)($v['time_ms']??'—'))?></td><td style="font-size:11px"><?=hsc((string)($v['error']??'—'))?></td></tr>
<?php endforeach; ?>
</table>

<h2>5. WebSocket HTTP Upgrade (real 101 handshake)</h2>
<table><tr><th>target</th><th>ok (101?)</th><th>code</th><th>ms</th><th>status</th></tr>
<?php foreach($results['ws_handshake'] as $k=>$v): ?>
<tr><th><?=hsc($k)?></th><td class="<?= ($v['ok']??false)?'ok':'fail' ?>"><?= ($v['ok']??false)?'YES':'NO' ?></td><td><?=hsc((string)($v['http_code']??'—'))?></td><td><?=hsc((string)($v['time_ms']??'—'))?></td><td style="font-size:11px"><?=hsc((string)($v['error'] ?? $v['status_line'] ?? ''))?><?php if(!empty($v['headers_snippet'])): ?><br><code style="font-size:10px"><?=hsc(substr($v['headers_snippet'],0,220))?></code><?php endif; ?></td></tr>
<?php endforeach; ?>
</table>
<p style="font-size:12px;color:#9aa3b2">TikTok entry is expected to <b>not</b> return 101 without signing — its TCP ok + non-101 here still means host reachable; only the two echo servers matter for WSS verdict.</p>

<h2>6. Ratchet/Pawl live echo (wss://ws.postman-echo.com/raw)</h2>
<pre><?=hsc(json_encode($results['pawl_echo'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre>

<h2>7. How to deploy on serv00</h2>
<pre>scp ws_tester.php bossking@serv00.net:~/domains/bossking.serv00.net/tiktokliveurl/
# web:
curl "https://bossking.serv00.net/tiktokliveurl/ws_tester.php?token=YOUR_MASTER_TOKEN&format=json" | jq
# ssh on serv00:
php ~/domains/bossking.serv00.net/tiktokliveurl/ws_tester.php --json
php ~/domains/bossking.serv00.net/tiktokliveurl/ws_tester.php   # human</pre>

<p style="margin-top:18px"><a href="?token=<?=hsc((string)$providedToken)?>&format=json">View as JSON</a> · <a href="?token=<?=hsc((string)$providedToken)?>&timeout=8">Retry 8s timeout</a></p>
</body></html>
