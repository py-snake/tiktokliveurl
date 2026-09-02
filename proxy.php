<?php

/**
 * Stream proxy for TikTok CDN URLs.
 *
 * Relays TikTok CDN streams with proper Referer/User-Agent headers.
 * Rewrites HLS segment URLs to also go through the proxy.
 *
 * Usage: proxy.php?url=<encoded_tiktok_cdn_url>
 */

$config = require_once __DIR__ . '/config.php';

$token = $_GET['token'] ?? $_COOKIE['tt_token'] ?? '';
if (!hash_equals($config['master_token'], (string) $token)) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

function tiktokRequestHeaders(string $userAgent): array
{
    return [
        'User-Agent: ' . $userAgent,
        'Accept: */*',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.tiktok.com/',
        'Origin: https://www.tiktok.com',
    ];
}

const CORS_HEADER = 'Access-Control-Allow-Origin: *';

$url = $_GET['url'] ?? '';
$url = trim($url);

if (empty($url)) {
    http_response_code(400);
    echo 'Missing url parameter';
    exit;
}

$url = urldecode($url);

const ALLOWED_CDN_HOSTS = [
    'tiktokcdn.com',
    'tiktokcdn-eu.com',
    'tiktokcdn-in.com',
    'tiktokcdn-us.com',
    'ttwstatic.com',
    'muscdn.com',
];

$parsed = parse_url($url);
if (!$parsed || !isset($parsed['host'])) {
    http_response_code(400);
    echo 'Invalid URL';
    exit;
}

$host = $parsed['host'];
$allowed = false;
foreach (ALLOWED_CDN_HOSTS as $domain) {
    if ($host === $domain || substr($host, -(strlen($domain) + 1)) === '.' . $domain) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
    http_response_code(403);
    echo 'URL host not in allowed list';
    exit;
}

/**
 * Only allow redirect hops whose target host is on the CDN allowlist
 * (relative Locations resolve against the already-allowlisted host).
 * The allowlist is checked on the initial URL above, but with
 * CURLOPT_FOLLOWLOCATION curl will otherwise follow Location headers to any
 * host — SSRF back into the shared-hosting LAN / cloud metadata.
 */
function cdnRedirectAllowed(string $locationHeader): bool
{
    $target = trim(substr($locationHeader, strlen('location:')));
    $p = parse_url($target);
    if (!$p || !isset($p['host'])) {
        return true;
    }
    $h = strtolower($p['host']);
    foreach (ALLOWED_CDN_HOSTS as $domain) {
        if ($h === $domain || substr($h, -(strlen($domain) + 1)) === '.' . $domain) {
            return true;
        }
    }
    return false;
}

// Lightweight availability check (used by the UI to color the Play button).
// This deliberately issues a real GET, not a HEAD/CURLOPT_NOBODY request:
// Akamai's block on TikTok's HLS edges only triggers on GET (it fetches from
// origin), so a HEAD sails through with 200 even when the real stream fetch
// below would get a 403 — that mismatch is exactly what made every button
// show green regardless of actual availability. We abort right after the
// final response headers are in (before any body/video bytes are requested),
// since a live origin can take several seconds to start pushing payload —
// waiting on a body byte here would make the check itself slow.
//
// Note: TikTok's live CDN can take several seconds to send anything at all
// (even headers) while it bootstraps a new viewer session server-side — a
// 403 rejection is near-instant, but a genuinely-live/working stream is not.
// The timeout below has to be generous enough to not misreport a slow-but-
// working stream as unavailable.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    $sawLocation = false;
    $redirectBlocked = false;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$sawLocation, &$redirectBlocked) {
        $trimmed = rtrim($headerLine, "\r\n");
        if ($trimmed === '' && !$sawLocation) {
            return 0; // final response headers are complete — abort before any body
        }
        if (stripos($trimmed, 'location:') === 0 && !cdnRedirectAllowed($trimmed)) {
            $redirectBlocked = true;
            return 0; // redirect target not allowlisted — abort before following
        }
        if ($trimmed === '') {
            $sawLocation = false; // this blank line ends a redirect hop — let curl follow it
        } elseif (stripos($trimmed, 'location:') === 0) {
            $sawLocation = true;
        }
        return strlen($headerLine);
    });
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => tiktokRequestHeaders($config['tiktok']['user_agent']),
        CURLOPT_WRITEFUNCTION => function (): int {
            return 0; // safety net in case the header-based abort above doesn't fire
        },
    ]);
    if (!empty($config['proxy'])) {
        curl_setopt($ch, CURLOPT_PROXY, $config['proxy']);
    }
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    header(CORS_HEADER);
    http_response_code($redirectBlocked || !$httpCode ? 502 : $httpCode);
    exit;
}

$isHlsGuess = preg_match('/\.m3u8(\?|$)/i', $url) || preg_match('/\.m3u8(\?|$)/i', $parsed['path'] ?? '');

if ($isHlsGuess) {
    // Playlists are small, finite text — buffering the whole thing to rewrite
    // segment URLs is fine here.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine): int {
        $trimmed = trim($headerLine);
        if (stripos($trimmed, 'location:') === 0 && !cdnRedirectAllowed($trimmed)) {
            return 0; // redirect target not allowlisted — abort
        }
        return strlen($headerLine);
    });
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => 'gzip, deflate',
        CURLOPT_HTTPHEADER => tiktokRequestHeaders($config['tiktok']['user_agent']),
    ]);

    if (!empty($config['proxy'])) {
        curl_setopt($ch, CURLOPT_PROXY, $config['proxy']);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($response === false || $httpCode >= 400) {
        http_response_code(502);
        echo "Upstream error: HTTP $httpCode";
        exit;
    }

    $body = $response;

    if (strlen($body) > 3 && ord($body[0]) === 0x1f && ord($body[1]) === 0x8b && ord($body[2]) === 0x08) {
        $decoded = @gzdecode($body);
        if ($decoded !== false) {
            $body = $decoded;
        }
    }

    $baseUrl = dirname($parsed['path']);
    if (substr($baseUrl, -1) !== '/') {
        $baseUrl .= '/';
    }

    $body = preg_replace_callback('/^(?!#)(.+\.ts.*)$/m', function ($m) use ($parsed, $baseUrl, $token) {
        $segment = trim($m[1]);
        if (preg_match('#^https?://#i', $segment)) {
            $fullUrl = $segment;
        } else {
            $fullUrl = $parsed['scheme'] . '://' . $parsed['host'] . $baseUrl . $segment;
        }
        return 'proxy.php?url=' . urlencode($fullUrl) . '&token=' . urlencode($token);
    }, $body);

    header('Content-Type: application/vnd.apple.mpegurl');
    header(CORS_HEADER);
    header('Cache-Control: no-cache');
    echo $body;
    exit;
}

// FLV/segment data: a live stream never "completes", so buffering the whole
// response (as the HLS branch above does) would just stall until our own
// timeout fires and the player never receives a single byte. Stream it
// through chunk-by-chunk as curl receives it instead.
//
// This branch is unbounded BY DESIGN (long-lived streams, CURLOPT_TIMEOUT=0),
// which makes it the classic shared-hosting zombie-worker factory: if a
// player aborts mid-stream or the CDN stalls, the worker keeps looping until
// a connection_aborted() check or the low-speed stall guard trips. Without
// those guards a few closed players permanently eat every PHP-FPM worker
// and the whole site (even index.php) hangs with 0 bytes — which is exactly
// the outage that led to them being added.
set_time_limit(0);
ignore_user_abort(false);
while (ob_get_level() > 0) {
    ob_end_flush();
}

$statusCode = 200;
$upstreamContentType = null;
$headersSent = false;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_BUFFERSIZE => 262144, // larger chunks = fewer PHP write-events per byte
    CURLOPT_LOW_SPEED_LIMIT => 1,
    CURLOPT_LOW_SPEED_TIME => 20,
    CURLOPT_NOSIGNAL => true,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => tiktokRequestHeaders($config['tiktok']['user_agent']),
]);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$statusCode, &$upstreamContentType) {
    $trimmed = trim($headerLine);
    if (preg_match('#^HTTP/\S+\s+(\d+)#', $trimmed, $m)) {
        $statusCode = (int) $m[1];
        $upstreamContentType = null; // reset in case this is a redirect hop
    } elseif (stripos($trimmed, 'location:') === 0) {
        if (!cdnRedirectAllowed($trimmed)) {
            return 0; // redirect target not allowlisted — abort
        }
    } elseif (stripos($trimmed, 'content-type:') === 0) {
        $upstreamContentType = trim(substr($trimmed, strlen('content-type:')));
    }
    return strlen($headerLine);
});
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$statusCode, &$upstreamContentType, &$headersSent) {
    if (connection_aborted()) {
        return 0; // client disconnected — abort the transfer and free the worker
    }
    if (!$headersSent) {
        http_response_code($statusCode >= 400 ? 502 : $statusCode);
        header('Content-Type: ' . ($upstreamContentType ?: 'video/x-flv'));
        header(CORS_HEADER);
        header('Cache-Control: no-cache');
        $headersSent = true;
    }
    echo $chunk;
    flush();
    if (connection_aborted()) {
        return 0;
    }
    return strlen($chunk);
});

if (!empty($config['proxy'])) {
    curl_setopt($ch, CURLOPT_PROXY, $config['proxy']);
}

curl_exec($ch);
$curlError = curl_error($ch);

if (!$headersSent) {
    http_response_code(502);
    echo 'Upstream error' . ($curlError ? ": $curlError" : '');
}
