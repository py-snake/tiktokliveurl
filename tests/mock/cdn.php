<?php

/**
 * Mock TikTok CDN — routers for the PHP built-in web server.
 *
 * Serves as an HTTP forward proxy so proxy.php's curl calls can be tested
 * fully offline: proxy.php is pointed at this server via CURLOPT_PROXY
 * (config 'proxy'), and its curl then sends absolute-form requests
 * ("GET http://tiktokcdn-eu.com/stage/x.flv HTTP/1.1"). For allowlisted CDN
 * hosts this router answers canned fixtures; for every other host it
 * transparently forwards the request to the real target (the mock TikTok
 * server), preserving the existing api.php tests.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

const CDN_HOSTS = [
    'tiktokcdn.com',
    'tiktokcdn-eu.com',
    'tiktokcdn-in.com',
    'tiktokcdn-us.com',
    'ttwstatic.com',
    'muscdn.com',
];

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function proxyFormTarget(string $uri): ?array
{
    if (!preg_match('#^https?://#i', $uri)) {
        return null;
    }
    $p = parse_url($uri);
    if (!$p || !isset($p['host'])) {
        return null;
    }
    return [
        'host' => $p['host'],
        'port' => $p['port'] ?? (strtolower($p['scheme'] ?? 'http') === 'https' ? 443 : 80),
        'path' => ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : ''),
    ];
}

function isCdnHost(string $host): bool
{
    foreach (CDN_HOSTS as $domain) {
        if ($host === $domain || substr($host, -(strlen($domain) + 1)) === '.' . $domain) {
            return true;
        }
    }
    return false;
}

$fwd = proxyFormTarget($uri);

if ($fwd && isCdnHost($fwd['host'])) {
    // Canned CDN responses.
    $path = $fwd['path'];

    if (strpos($path, 'badredirect') !== false) {
        http_response_code(302);
        header('Location: http://169.254.169.254/latest/meta-data/');
        header('Content-Length: 0');
        exit;
    }

    if (strpos($path, 'redirect') !== false) {
        http_response_code(302);
        header('Location: /stage/ok.flv');
        header('Content-Length: 0');
        exit;
    }

    if (strpos($path, 'denied') !== false) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Access Denied';
        exit;
    }

    if (preg_match('/\.m3u8(\?|$)/i', $path)) {
        $m3u8 = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n"
            . "#EXTINF:6.000,\nhttp://{$fwd['host']}/stage/seg0.ts\n#EXT-X-ENDLIST\n";
        header('Content-Type: application/vnd.apple.mpegurl');
        echo $m3u8;
        exit;
    }

    // FLV stream look-alike.
    $payload = "FLV\x01\x05\x00\x00\x00\x09\x00\x00\x00\x00" . str_repeat("\x00", 6000);
    header('Content-Type: video/x-flv');
    header('Content-Length: ' . strlen($payload));
    echo $payload;
    exit;
}

if (!$fwd || $fwd['host'] !== '127.0.0.1') {
    http_response_code(404);
    echo 'mock cdn: unexpected request ' . htmlspecialchars(substr($uri, 0, 120));
    exit;
}

// Forward through to the real target (mock TikTok server on 127.0.0.1:port),
// preserving the upstream status code and headers (Content-Encoding etc).
$target = 'http://127.0.0.1:' . $fwd['port'] . $fwd['path'];
$ch = curl_init();
$upHeaders = [];
curl_setopt_array($ch, [
    CURLOPT_URL => $target,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$upHeaders) {
        $trimmed = rtrim($line, "\r\n");
        if ($trimmed !== '' && preg_match('#^HTTP/#i', $trimmed) === 0) {
            $upHeaders[] = $trimmed;
        }
        return strlen($line);
    },
    CURLOPT_HTTPHEADER => ['Host: 127.0.0.1:' . $fwd['port']],
]);
$resp = curl_exec($ch);
if ($resp === false) {
    http_response_code(502);
    echo 'mock cdn: forward failed';
    exit;
}
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

http_response_code($code);
foreach ($upHeaders as $h) {
    if (preg_match('#^(transfer-encoding|connection|content-length|keep-alive|date):#i', $h)) {
        continue;
    }
    header($h);
}
echo $resp;
exit;