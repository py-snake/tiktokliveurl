<?php

header('Content-Type: application/json; charset=utf-8');

$config = require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tiktok_codec.php';

const ERR_BAD_REQUEST = 'Bad Request';
const RATE_LIMIT_MAX = 30;      // max requests
const RATE_LIMIT_WINDOW = 60;   // per window (seconds)
const RATE_LIMIT_FILE = __DIR__ . '/../tests/.work/rate_limit.json';

if ($config['cors']['enabled']) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

$token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);

if (!hash_equals($config['master_token'], (string) $token)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Invalid or missing master token',
    ]);
    exit;
}

/**
 * Simple file-based rate limiter with sliding window.
 * Uses file locking for cross-process safety on shared hosting.
 */
function checkRateLimit(string $identifier): bool
{
    // Skip rate limiting in test environment
    if (defined('PHPUNIT_TEST') || isset($_ENV['TEST_MODE']) || (isset($_SERVER['argv']) && in_array('--test', $_SERVER['argv']))) {
        return true;
    }
    
    // Also skip if running from test suite (detect by checking if rate limit file is in test work dir)
    if (str_starts_with(RATE_LIMIT_FILE, __DIR__ . '/../tests/.work/')) {
        return true;
    }
    
    $now = time();
    $windowStart = $now - RATE_LIMIT_WINDOW;
    
    $data = [];
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        $content = stream_get_contents($fp);
        if ($content !== false && $content !== '') {
            $data = json_decode($content, true) ?? [];
        }
        
        // Clean old entries
        if (isset($data[$identifier])) {
            $data[$identifier] = array_filter($data[$identifier], fn($ts) => $ts >= $windowStart);
            $count = count($data[$identifier]);
        } else {
            $count = 0;
        }
        
        if ($count >= RATE_LIMIT_MAX) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }
        
        $data[$identifier][] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return true;
}

// Rate limit by IP
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit('api:' . $clientIp)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Too Many Requests',
        'message' => 'Rate limit exceeded. Please slow down.',
        'retry_after' => RATE_LIMIT_WINDOW,
    ]);
    exit;
}

$token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);

if (!hash_equals($config['master_token'], (string) $token)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Invalid or missing master token',
    ]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'check':
        handleCheck($config);
        break;
    case 'room_info':
        handleRoomInfo($config);
        break;
    case 'chat_token':
        handleChatToken($config);
        break;
    default:
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => ERR_BAD_REQUEST,
            'message' => 'Invalid action. Use "check", "room_info" or "chat_token".',
        ]);
        exit;
}

function handleCheck(array $config): void
{
    $username = $_GET['username'] ?? $_POST['username'] ?? '';
    $username = trim($username);

    if (empty($username)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => ERR_BAD_REQUEST,
            'message' => 'Username is required.',
        ]);
        return;
    }

    $username = extractUsername($username);
    $result = checkLiveStatus($config, $username);
    echo json_encode($result, JSON_PRETTY_PRINT);
}

function handleRoomInfo(array $config): void
{
    $roomId = $_GET['room_id'] ?? $_POST['room_id'] ?? '';
    $roomId = trim($roomId);

    if (empty($roomId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => ERR_BAD_REQUEST,
            'message' => 'Room ID is required.',
        ]);
        return;
    }

    $result = getRoomInfo($config, $roomId);
    echo json_encode($result, JSON_PRETTY_PRINT);
}

function extractUsername(string $input): string
{
    if (preg_match('#tiktok\.com/@([^/?]+)#', $input, $matches)) {
        return $matches[1];
    }
    if (strpos($input, '@') === 0) {
        return substr($input, 1);
    }
    return $input;
}

function checkLiveStatus(array $config, string $username): array
{
    $roomId = getRoomId($config, $username);

    if (!$roomId) {
        return [
            'success' => true,
            'data' => [
                'username' => $username,
                'is_live' => false,
                'room_id' => null,
                'debug' => 'Could not resolve room ID',
            ],
        ];
    }

    $alive = isRoomAlive($config, $roomId);

    if (!$alive) {
        return [
            'success' => true,
            'data' => [
                'username' => $username,
                'is_live' => false,
                'room_id' => $roomId,
            ],
        ];
    }

    $roomInfo = getRoomInfo($config, $roomId);

    if (!$roomInfo['success']) {
        return [
            'success' => true,
            'data' => [
                'username' => $username,
                'is_live' => true,
                'room_id' => $roomId,
                'room' => null,
                'note' => $roomInfo['error'] ?? 'Stream URLs unavailable',
            ],
        ];
    }

    return [
        'success' => true,
        'data' => [
            'username' => $username,
            'is_live' => true,
            'room_id' => $roomId,
            'room' => $roomInfo['data'],
        ],
    ];
}

function getRoomId(array $config, string $username): ?string
{
    $roomId = getRoomIdFromApi($config, $username);
    if ($roomId) {
        return $roomId;
    }

    $roomId = getRoomIdFromHtml($config, $username);
    if ($roomId) {
        return $roomId;
    }

    return null;
}

function getRoomIdFromApi(array $config, string $username): ?string
{
    $params = http_build_query([
        'aid' => '1988',
        'app_name' => 'tiktok_web',
        'device_platform' => 'web_pc',
        'app_language' => 'en',
        'browser_language' => 'en-US',
        'region' => 'US',
        'user_is_login' => 'false',
        'sourceType' => '54',
        'staleTime' => '600000',
        'uniqueId' => $username,
    ]);

    $url = $config['tiktok']['web_url'] . "/api-live/user/room?{$params}";

    $response = httpRequest($config, $url);

    if (!$response) {
        return null;
    }

    $data = json_decode($response, true);

    if (!$data || ($data['statusCode'] ?? -1) !== 0) {
        return null;
    }

    $roomId = $data['data']['user']['roomId'] ?? null;

    if (!$roomId || $roomId === '0') {
        return null;
    }

    return (string) $roomId;
}

function getRoomIdFromHtml(array $config, string $username): ?string
{
    $url = $config['tiktok']['web_url'] . "/@{$username}/live";

    $response = httpRequest($config, $url);

    if (!$response) {
        return null;
    }

    if (preg_match('/<script id="SIGI_STATE" type="application\/json">(.*?)<\/script>/s', $response, $matches)) {
        $sigiState = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if ($sigiState) {
            $roomId = $sigiState['LiveRoom']['liveRoomUserInfo']['liveRoom']['roomId'] ?? null;
            if ($roomId) {
                return (string) $roomId;
            }
        }
    }

    if (preg_match('/(?:"|&quot;)roomId(?:"|&quot;)\s*:\s*(?:"|&quot;)(\d{10,})(?:"|&quot;)/', $response, $matches)) {
        return $matches[1];
    }

    return null;
}

function isRoomAlive(array $config, string $roomId): bool
{
    $params = http_build_query([
        'aid' => '1988',
        'room_ids' => $roomId,
    ]);

    $url = $config['tiktok']['webcast_url'] . "/webcast/room/check_alive/?{$params}";

    $response = httpRequest($config, $url);

    if (!$response) {
        return false;
    }

    $data = json_decode($response, true);

    if (!$data || !isset($data['data'][0])) {
        return false;
    }

    return ($data['data'][0]['alive'] ?? false) === true;
}

function getRoomInfo(array $config, string $roomId): array
{
    $params = http_build_query([
        'aid' => '1988',
        'room_id' => $roomId,
    ]);

    $url = $config['tiktok']['webcast_url'] . "/webcast/room/info/?{$params}";
    $response = httpRequest($config, $url);

    if (!$response) {
        return [
            'success' => false,
            'error' => 'HTTP request failed',
        ];
    }

    $data = json_decode($response, true);

    if (!$data) {
        return [
            'success' => false,
            'error' => 'Invalid JSON response',
        ];
    }

    $statusCode = $data['status_code'] ?? -1;

    if ($statusCode === 4003110) {
        $fallback = getRoomInfoFromHtml($config, $roomId);
        if ($fallback['success']) {
            return $fallback;
        }
        return [
            'success' => false,
            'error' => 'Access restricted (4003110).',
        ];
    }

    if ($statusCode !== 0) {
        return [
            'success' => false,
            'error' => "API error: {$statusCode}",
        ];
    }

    $roomData = $data['data'] ?? [];
    $streamUrl = $roomData['stream_url'] ?? [];
    $owner = $roomData['owner'] ?? [];
    $stats = $roomData['stats'] ?? [];

    $streamUrls = parseStreamUrls($streamUrl);

    return [
        'success' => true,
        'data' => [
            'room_id' => $roomId,
            'title' => $roomData['title'] ?? '',
            'status' => $roomData['status'] ?? 0,
            'create_time' => $roomData['create_time'] ?? 0,
            'viewer_count' => (int) ($roomData['user_count'] ?? 0),
            'like_count' => (int) ($stats['like_count'] ?? 0),
            'total_user' => (int) ($stats['total_user'] ?? 0),
            'owner' => [
                'nickname' => $owner['nickname'] ?? '',
                'display_id' => $owner['display_id'] ?? '',
                'avatar' => $owner['avatar_thumb']['url_list'][0] ?? '',
                'bio' => $owner['bio_description'] ?? '',
            ],
            'stream_urls' => $streamUrls,
            'cover' => $roomData['cover']['url_list'][0] ?? '',
        ],
    ];
}

function getRoomInfoFromHtml(array $config, string $roomId): array
{
    $params = http_build_query([
        'aid' => '1988',
        'app_name' => 'tiktok_web',
        'device_platform' => 'web_pc',
        'live_id' => '1',
        'room_id' => $roomId,
        'resp_type' => '2',
    ]);

    $url = $config['tiktok']['webcast_url'] . "/webcast/room/push/flow/?{$params}";

    $response = httpRequest($config, $url);

    if (!$response) {
        return ['success' => false, 'error' => 'push/flow request failed'];
    }

    $data = json_decode($response, true);

    if (!$data || ($data['status_code'] ?? -1) !== 0) {
        return ['success' => false, 'error' => 'push/flow returned error'];
    }

    $roomData = $data['data'] ?? [];
    $streamUrl = $roomData['stream_url'] ?? [];

    if (empty($streamUrl)) {
        return ['success' => false, 'error' => 'No stream data in push/flow response'];
    }

    $owner = $roomData['owner'] ?? [];
    $stats = $roomData['stats'] ?? [];
    $streamUrls = parseStreamUrls($streamUrl);

    return [
        'success' => true,
        'data' => [
            'room_id' => $roomId,
            'title' => $roomData['title'] ?? '',
            'status' => $roomData['status'] ?? 0,
            'create_time' => $roomData['create_time'] ?? 0,
            'viewer_count' => (int) ($roomData['user_count'] ?? 0),
            'like_count' => (int) ($stats['like_count'] ?? 0),
            'total_user' => (int) ($stats['total_user'] ?? 0),
            'owner' => [
                'nickname' => $owner['nickname'] ?? '',
                'display_id' => $owner['display_id'] ?? '',
                'avatar' => $owner['avatar_thumb']['url_list'][0] ?? '',
                'bio' => $owner['bio_description'] ?? '',
            ],
            'stream_urls' => $streamUrls,
            'cover' => $roomData['cover']['url_list'][0] ?? '',
        ],
    ];
}

function parseStreamUrls(array $streamUrl): array
{
    $urls = [
        'hls' => null,
        'rtmp' => null,
        'flv' => [],
        'qualities' => [],
    ];

    $urls['hls'] = $streamUrl['hls_pull_url'] ?? null;
    $urls['rtmp'] = $streamUrl['rtmp_pull_url'] ?? null;

    $flvPullUrl = $streamUrl['flv_pull_url'] ?? [];
    $qualityMap = [
        'FULL_HD1' => '1080p',
        'HD1' => '720p',
        'SD2' => '480p',
        'SD1' => '360p',
    ];

    foreach ($qualityMap as $key => $label) {
        if (!empty($flvPullUrl[$key])) {
            $urls['flv'][$label] = is_array($flvPullUrl[$key])
                ? ($flvPullUrl[$key][0] ?? null)
                : $flvPullUrl[$key];
        }
    }

    $sdkDataStr = $streamUrl['live_core_sdk_data']['pull_data']['stream_data'] ?? null;
    if ($sdkDataStr) {
        $sdkData = json_decode($sdkDataStr, true);
        if ($sdkData && isset($sdkData['data'])) {
            $qualities = $streamUrl['live_core_sdk_data']['pull_data']['options']['qualities'] ?? [];
            $levelMap = [];
            foreach ($qualities as $q) {
                $levelMap[$q['sdk_key']] = [
                    'level' => $q['level'] ?? 0,
                    'gear' => $q['vGear'] ?? '',
                    'codec' => $q['vCodec'] ?? '',
                    'bitrate' => $q['vBitrate'] ?? 0,
                ];
            }

            foreach ($sdkData['data'] as $sdkKey => $entry) {
                $main = $entry['main'] ?? [];
                $flvUrl = $main['flv'] ?? null;
                $hlsUrl = $main['hls'] ?? $main['m3u8'] ?? null;

                $info = $levelMap[$sdkKey] ?? ['level' => 0, 'gear' => $sdkKey, 'codec' => '', 'bitrate' => 0];

                $urls['qualities'][] = [
                    'key' => $sdkKey,
                    'level' => $info['level'],
                    'gear' => $info['gear'],
                    'codec' => $info['codec'],
                    'bitrate' => $info['bitrate'],
                    'flv' => $flvUrl,
                    'hls' => $hlsUrl,
                ];
            }

            usort($urls['qualities'], fn($a, $b) => $b['level'] <=> $a['level']);
        }
    }

    return $urls;
}

function handleChatToken(array $config): void
{
    // separate rate limit for chat_token (more strict)
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!checkRateLimit('chat_token:' . $ip)) {
        http_response_code(429);
        echo json_encode(['success'=>false,'error'=>'Too Many Requests','message'=>'Rate limit for chat_token exceeded']);
        return;
    }

    $username = trim($_GET['username'] ?? $_POST['username'] ?? '');
    $roomId = trim($_GET['room_id'] ?? $_POST['room_id'] ?? '');

    if ($roomId === '' && $username !== '') {
        $username = extractUsername($username);
        $roomId = getRoomId($config, $username);
        if (!$roomId) {
            http_response_code(404);
            echo json_encode(['success'=>false,'error'=>'Not Found','message'=>'Could not resolve roomId for @'.$username.' (offline or invalid username)']);
            return;
        }
    }
    if ($roomId === '') {
        // also allow uniqueId param as fallback
        $uniqueId = trim($_GET['uniqueId'] ?? $_POST['uniqueId'] ?? '');
        if ($uniqueId !== '') {
            $username = extractUsername($uniqueId);
            $roomId = null; // will use uniqueId mode for Euler
        } else {
            http_response_code(400);
            echo json_encode(['success'=>false,'error'=>ERR_BAD_REQUEST,'message'=>'username or room_id required']);
            return;
        }
    } else {
        $uniqueId = null;
    }

    // if username still empty but roomId given, keep it
    $uniqueIdForEuler = $roomId ? null : ($uniqueId ?? $username);
    $roomIdForEuler = $roomId ?: null;

    // live check before minting (avoid handing out fallback for offline rooms) — return 200 to avoid console 404 noise
    if ($roomId) {
        $alive = isRoomAlive($config, $roomId);
        if (!$alive) {
            echo json_encode(['success'=>false,'error'=>'Not live','message'=>'Room '.$roomId.' is not currently live — chat unavailable (offline)']);
            return;
        }
    }

    $euler = chatEulerFetch($config, $roomIdForEuler, $uniqueIdForEuler);
    // fallback host means Euler could not mint a real TikTok WS (offline / rate-limit / invalid id) — retry via uniqueId if we have username
    if ($euler['success'] && isset($euler['fetch']['wsUrl']) && str_contains($euler['fetch']['wsUrl'], 'ws-fallback')) {
        if ($username && $roomIdForEuler !== null) {
            $retry = chatEulerFetch($config, null, $username);
            if ($retry['success'] && !str_contains($retry['fetch']['wsUrl'] ?? '', 'ws-fallback')) {
                $euler = $retry;
            } else {
                echo json_encode(['success'=>false,'error'=>'Not live','message'=>'Live chat unavailable — streamer offline or sign server returned fallback. Try again when live or set chat.sign_api_key.','details'=> $euler['fetch']['wsUrl']]);
                return;
            }
        } else {
            echo json_encode(['success'=>false,'error'=>'Not live','message'=>'Live chat unavailable — sign server returned fallback. Room offline or Euler rate-limited (free tier ~10/day/IP).','details'=> $euler['fetch']['wsUrl']]);
            return;
        }
    }
    if (!$euler['success']) {
        // return 200 to keep console quiet; frontend checks success flag
        echo json_encode(['success'=>false,'error'=>$euler['error'],'message'=>$euler['message'] ?? 'Euler sign server error','details'=>$euler['details'] ?? null]);
        return;
    }

    $fetch = $euler['fetch'];
    $history = [];
    foreach ($fetch['messages'] as $m) {
        if ($m['type'] === 'WebcastChatMessage') {
            try { $c = TikTokCodec::decodeChat($m['payload']); $history[] = ['type'=>'chat','user'=>$c['user'],'comment'=>$c['comment']]; } catch (Throwable $e) {}
        } elseif ($m['type'] === 'WebcastGiftMessage') {
            try { $g = TikTokCodec::decodeGift($m['payload']); $history[] = ['type'=>'gift','giftId'=>$g['giftId'],'repeatCount'=>$g['repeatCount'],'repeatEnd'=>$g['repeatEnd'],'user'=>$g['user']]; } catch (Throwable $e) {}
        } elseif ($m['type'] === 'WebcastLikeMessage') {
            try { $l = TikTokCodec::decodeLike($m['payload']); $history[] = ['type'=>'like','likeCount'=>$l['likeCount'],'totalLikeCount'=>$l['totalLikeCount'],'user'=>$l['user']]; } catch (Throwable $e) {}
        }
    }

    // Build finalWsUrl — host-aware: Euler fallback/managed hosts need only their own params
    $isEulerHost = str_contains($fetch['wsUrl'], 'eulerstream.com');
    if ($isEulerHost) {
        $wsParams = array_merge($fetch['wsParams'], [
            'room_id' => $fetch['roomId'] ?? $roomId ?? '',
            'cursor' => $fetch['cursor'],
            'internal_ext' => $fetch['internalExt'],
        ]);
    } else {
        $wsParams = array_merge(chatDefaultWsParams($config), $fetch['wsParams'], [
            'room_id' => $fetch['roomId'] ?? $roomId ?? '',
            'cursor' => $fetch['cursor'],
            'internal_ext' => $fetch['internalExt'],
            'compress' => '', // empty = no gzip, simpler for browser JS (no pako needed)
        ]);
    }
    $finalUrl = $fetch['wsUrl'];
    if ($finalUrl) {
        $sep = str_contains($finalUrl, '?') ? '&' : '?';
        $finalUrl .= $sep . http_build_query($wsParams, '', '&', PHP_QUERY_RFC3986);
        if (!$isEulerHost) $finalUrl .= '&version_code=270000';
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'username' => $username ?: $uniqueIdForEuler,
            'room_id' => $fetch['roomId'] ?? $roomId,
            'wsUrl' => $fetch['wsUrl'],
            'wsParams' => $fetch['wsParams'],
            'cursor' => $fetch['cursor'],
            'internalExt' => $fetch['internalExt'],
            'heartBeatDuration' => $fetch['heartBeatDuration'],
            'needsAck' => $fetch['needsAck'],
            'finalWsUrl' => $finalUrl,
            'history' => $history,
        ]
    ], JSON_PRETTY_PRINT);
}

function chatDefaultWsParams(array $config): array
{
    return [
        'version_code' => '180800',
        'aid' => '1988',
        'app_language' => 'en',
        'app_name' => 'tiktok_web',
        'browser_platform' => 'Win32',
        'browser_language' => 'en-DE',
        'browser_name' => 'Mozilla',
        'browser_version' => '5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'browser_online' => 'true',
        'cookie_enabled' => 'true',
        'tz_name' => 'Europe/Berlin',
        'device_platform' => 'web',
        'identity' => 'audience',
        'live_id' => '12',
        'webcast_language' => 'en',
        'ws_direct' => '0',
        'sup_ws_ds_opt' => '1',
        'update_version_code' => '2.0.0',
        'did_rule' => '3',
        'screen_height' => '1080',
        'screen_width' => '1920',
        'heartbeat_duration' => '0',
        'resp_content_type' => 'protobuf',
        'history_comment_count' => '6',
        'client_enter' => '1',
        'last_rtt' => (string)(100 + random_int(0, 100)),
    ];
}

function chatEulerFetch(array $config, ?string $roomId, ?string $uniqueId): array
{
    $signApiKey = $config['chat']['sign_api_key'] ?? '';
    $signApiBase = $config['chat']['sign_api_base'] ?? 'https://tiktok.eulerstream.com';
    // env overrides
    $envKey = getenv('SIGN_API_KEY');
    if (is_string($envKey) && $envKey !== '') $signApiKey = $envKey;
    $envBase = getenv('SIGN_API_URL');
    if (is_string($envBase) && $envBase !== '') $signApiBase = $envBase;

    $params = [
        'client' => 'ttlive-php',
        'user_agent' => $config['tiktok']['user_agent'] ?? 'Mozilla/5.0',
        'client_enter' => 'true',
        'platform' => 'web',
    ];
    if ($roomId !== null && $roomId !== '') $params['room_id'] = $roomId;
    elseif ($uniqueId !== null && $uniqueId !== '') $params['unique_id'] = $uniqueId;
    else return ['success'=>false,'error'=>'Bad Request','message'=>'room_id or uniqueId required','http_code'=>400];
    if (!empty($signApiKey)) $params['apiKey'] = $signApiKey;

    $url = rtrim($signApiBase, '/') . '/webcast/fetch?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $headers = [
        'User-Agent: tiktok-live-php/0.1.0 php-' . PHP_VERSION,
        'Accept: application/protobuf, application/octet-stream, */*',
        'Referer: https://www.tiktok.com/',
        'Origin: https://www.tiktok.com',
        'Accept-Encoding: gzip, deflate',
    ];
    if (!empty($signApiKey)) $headers[] = 'x-api-key: ' . $signApiKey;

    $ch = curl_init();
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => $config['tiktok']['timeout'] ?? 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => ($config['ssl']['verify_peer'] ?? false),
        CURLOPT_SSL_VERIFYHOST => ($config['ssl']['verify_host'] ?? false) ? 2 : 0,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$respHeaders) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $k = strtolower(trim($parts[0]));
                $v = trim($parts[1]);
                $respHeaders[$k][] = $v;
            }
            return $len;
        },
    ]);
    if (!empty($config['ssl']['ca_bundle'])) curl_setopt($ch, CURLOPT_CAINFO, $config['ssl']['ca_bundle']);
    if (!empty($config['proxy'])) curl_setopt($ch, CURLOPT_PROXY, $config['proxy']);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['success'=>false,'error'=>'Upstream error','message'=>'Euler fetch failed: '.$curlErr,'http_code'=>502];
    }
    if ($httpCode === 429) {
        $msg = 'Sign-server rate-limited (429). Set chat.sign_api_key in config.php or SIGN_API_KEY env.';
        // try to extract json message
        $j = json_decode($body, true);
        if (isset($j['message'])) $msg = $j['message'];
        return ['success'=>false,'error'=>'Rate limited','message'=>$msg,'http_code'=>429,'details'=>substr($body,0,400)];
    }
    if ($httpCode === 402) {
        return ['success'=>false,'error'=>'Payment required','message'=>'Euler premium required (402)','http_code'=>402,'details'=>substr($body,0,400)];
    }
    if ($httpCode !== 200) {
        $snippet = substr($body, 0, 500);
        // if body is json, show message
        $j = json_decode($body, true);
        $msg = $j['message'] ?? "Sign-server returned HTTP $httpCode";
        return ['success'=>false,'error'=>'Upstream error','message'=>$msg,'http_code'=>$httpCode,'details'=>$snippet];
    }
    // body should be protobuf; if it looks like json error, handle
    if (str_starts_with(ltrim($body), '{')) {
        $j = json_decode($body, true);
        if (isset($j['message'])) {
            return ['success'=>false,'error'=>'Upstream error','message'=>$j['message'],'http_code'=>502,'details'=>substr($body,0,500)];
        }
    }
    if (substr($body, 0, 3) === "\x1f\x8b\x08") {
        $decoded = @gzdecode($body);
        if ($decoded !== false) $body = $decoded;
    }
    // x-room-id header may contain canonical roomId
    $roomIdHeader = $respHeaders['x-room-id'][0] ?? $respHeaders['x-room_id'][0] ?? null;
    // x-set-tt-cookie ignored for browser WS (cookies are for TikTok domain)

    try {
        $fetch = TikTokCodec::decodeFetchResult($body);
    } catch (Throwable $e) {
        return ['success'=>false,'error'=>'Decode error','message'=>'Failed to decode Euler protobuf: '.$e->getMessage(),'http_code'=>502,'details'=>bin2hex(substr($body,0,32))];
    }
    if ($roomIdHeader) $fetch['roomId'] = $roomIdHeader;

    // sanity: wsUrl must be present
    if (empty($fetch['wsUrl'])) {
        return ['success'=>false,'error'=>'Upstream error','message'=>'Euler returned empty wsUrl (room offline or invalid id)','http_code'=>502,'details'=>json_encode($fetch)];
    }

    return ['success'=>true,'fetch'=>$fetch,'headers'=>$respHeaders,'http_code'=>200];
}

function httpRequest(array $config, string $url, ?string $method = 'GET', ?string $cookieHeader = null): ?string
{
    $ch = curl_init();

    $headers = [
        'User-Agent: ' . $config['tiktok']['user_agent'],
        'Accept: application/json, text/html, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate',
        'Referer: https://www.tiktok.com/',
        'Origin: https://www.tiktok.com',
        'Sec-Ch-Ua: "Not/A)Brand";v="8", "Chromium";v="126"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "Windows"',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Dest: empty',
    ];

    if ($cookieHeader) {
        $headers[] = 'Cookie: ' . $cookieHeader;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => $config['tiktok']['timeout'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => ($config['ssl']['verify_peer'] ?? false),
        CURLOPT_SSL_VERIFYHOST => ($config['ssl']['verify_host'] ?? false) ? 2 : 0,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if (!empty($config['ssl']['ca_bundle'])) {
        curl_setopt($ch, CURLOPT_CAINFO, $config['ssl']['ca_bundle']);
    }

    if (!empty($config['proxy'])) {
        curl_setopt($ch, CURLOPT_PROXY, $config['proxy']);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);

    if ($response === false || $httpCode >= 400) {
        return null;
    }

    if (substr($response, 0, 3) === "\x1f\x8b\x08") {
        $response = gzdecode($response);
    }

    return $response;
}
