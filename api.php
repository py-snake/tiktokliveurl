<?php

header('Content-Type: application/json; charset=utf-8');

$config = require_once __DIR__ . '/config.php';

const ERR_BAD_REQUEST = 'Bad Request';

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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'check':
        handleCheck($config);
        break;
    case 'room_info':
        handleRoomInfo($config);
        break;
    default:
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => ERR_BAD_REQUEST,
            'message' => 'Invalid action. Use "check" or "room_info".',
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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

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
