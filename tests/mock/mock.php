<?php

/**
 * Mock TikTok server for HTTP-level tests of api.php / proxy.php.
 *
 * Serves deterministic fixtures for the /api-live/user/room, /webcast/room/*
 * and /@<user>/live endpoints. Responses are keyed off the query parameters,
 * so different usernames/room ids exercise different api.php branches.
 */

function js(array $data, int $status = 200, bool $gz = false): never
{
    http_response_code($status);
    $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($gz) {
        header('Content-Type: application/json');
        header('Content-Encoding: gzip');
        echo gzencode($body);
        exit;
    }
    header('Content-Type: application/json');
    echo $body;
    exit;
}

function notFoundHtml(): never
{
    header('Content-Type: text/html; charset=utf-8');
    echo "<html><body>not live</body></html>";
    exit;
}

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$query = $_GET;

$path = parse_url($uri, PHP_URL_PATH);
$path = $path === false ? '/' : $path;

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    http_response_code(200);
    exit;
}

if ($path === '/api-live/user/room') {
    $uniqueId = $query['uniqueId'] ?? '';
    switch ($uniqueId) {
        case 'liveuser':
            // gzip-encoded to exercise the gzdecode branch in httpRequest()
            js(['statusCode' => 0, 'data' => ['user' => ['roomId' => '1111111111111']]], 200, true);
        case 'offlineuser':
            js(['statusCode' => 0, 'data' => ['user' => ['roomId' => '2222222222222']]]);
        case 'ghostactive':
            js(['statusCode' => 0, 'data' => ['user' => ['roomId' => '7777777777770']]]);
        case 'obscured':
            // roomId "0" -> treated as no room, falls through to HTML lookup
            js(['statusCode' => 0, 'data' => ['user' => ['roomId' => '0']]]);
        case 'htmlonly':
            js(['statusCode' => 0, 'data' => ['user' => ['roomId' => '0']]]);
        case 'apilonely':
            js(['statusCode' => 0, 'data' => ['user' => ['roomId' => '0']]]);
        case 'nosource':
            js(['statusCode' => 100003, 'msg' => 'no result']);
        case 'badjson':
            header('Content-Type: application/json');
            echo '{this is not valid json';
            exit;
        case 'err500':
            js(['error' => 'boom'], 500);
        default:
            js(['statusCode' => -1]);
    }
}

if ($path === '/webcast/room/check_alive/') {
    $roomId = $query['room_ids'] ?? '';
    if ($roomId === '1111111111111') {
        js(['data' => [['alive' => true]]]);
    }
    if ($roomId === '2222222222222') {
        js(['data' => [['alive' => false]]]);
    }
    if ($roomId === '7777777777770' || $roomId === '3333333333333' || $roomId === '4444444444444') {
        js(['data' => []]);
    }
    js(['data' => []]);
}

if ($path === '/webcast/room/info/') {
    $roomId = $query['room_id'] ?? '';
    switch ($roomId) {
        case '5555555555555':
            // 4003110 -> triggers getRoomInfoFromHtml -> push/flow success
            js(['status_code' => 4003110, 'data' => []]);
        case '6666666666666':
            // 4003110 -> push/flow returns empty stream -> restricted error
            js(['status_code' => 4003110, 'data' => []]);
        case '7777777777777':
            js(['status_code' => -2, 'msg' => 'api error']);
        case '8888888888888':
            header('Content-Type: application/json');
            echo 'garbage{';
            exit;
        case '9999999999999':
            js(['error' => 'upstream down'], 500);
        case '1111111111111':
        case '3333333333333':
        case '4444444444444':
            js(roomDataPayload($roomId));
        default:
            js(['status_code' => 0, 'data' => []]);
    }
}

if ($path === '/webcast/room/push/flow/') {
    $roomId = $query['room_id'] ?? '';
    if ($roomId === '6666666666666') {
        js(['status_code' => 0, 'data' => ['stream_url' => []]]);
    }
    if ($roomId === '5555555555555') {
        js(['status_code' => 0, 'data' => ['stream_url' => flvOnlyStream()]]);
    }
    js(['status_code' => 0, 'data' => ['stream_url' => []]]);
}

// user live pages
if (preg_match('#^/@([^/]+)/live$#', $path, $m)) {
    $user = $m[1];
    switch ($user) {
        case 'obscured':
            $sigi = htmlspecialchars(json_encode(['LiveRoom' => ['liveRoomUserInfo' => ['liveRoom' => ['roomId' => '3333333333333']]]]), ENT_QUOTES);
            echo "<html><script id=\"SIGI_STATE\" type=\"application/json\">{$sigi}</script></html>";
            exit;
        case 'htmlonly':
            // SIGI body that fails json_decode but still contains a roomId for
            // the plain-text regex fallback.
            echo '<html><script id="SIGI_STATE" type="application/json">{broken"roomId":"4444444444444"</script></html>';
            exit;
        case 'gameplayer2':
            echo '<html>live now</html>';
            exit;
        default:
            notFoundHtml();
    }
}

notFoundHtml();

function flvOnlyStream(): array
{
    return [
        'flv_pull_url' => [
            'FULL_HD1' => ['https://pull-f5.example.com/fullhd.flv', 'https://pull-f5.example.com/fullhd2.flv'],
            'HD1' => 'https://pull-f5.example.com/hd.flv',
            'SD2' => 'https://pull-f5.example.com/sd2.flv',
            'SD1' => 'https://pull-f5.example.com/sd1.flv',
        ],
        'hls_pull_url' => null,
        'rtmp_pull_url' => null,
    ];
}

function roomDataPayload(string $roomId): array
{
    return [
        'status_code' => 0,
        'data' => [
            'room' => ['status' => 2],
            'title' => 'Test Live',
            'status' => 2,
            'create_time' => 1700000000,
            'user_count' => 1234,
            'cover' => ['url_list' => ['https://p16.example.com/cover.jpg']],
            'stats' => ['like_count' => 9999, 'total_user' => 5555],
            'owner' => [
                'nickname' => 'Tester',
                'display_id' => str_replace('1111111111111', 'liveuser', $roomId) !== $roomId ? 'liveuser' : 'another',
                'avatar_thumb' => ['url_list' => ['https://p16.example.com/avatar.jpg']],
                'bio_description' => 'hi',
            ],
            'stream_url' => [
                'hls_pull_url' => 'https://pull-hls.example.com/index.m3u8',
                'rtmp_pull_url' => 'rtmp://rtmp-pull.example.com/live',
                'flv_pull_url' => [
                    'FULL_HD1' => ['https://pull-f5.example.com/fullhd.flv'],
                    'HD1' => 'https://pull-f5.example.com/hd.flv',
                ],
                'live_core_sdk_data' => [
                    'pull_data' => [
                        'stream_data' => json_encode([
                            'data' => [
                                'origin' => ['main' => ['flv' => 'https://pull-f5.example.com/sdk.flv', 'hls' => 'https://pull-hls.example.com/sdk.m3u8']],
                                'oda' => ['main' => ['flv' => 'https://pull-f5.example.com/oda.flv', 'hls' => null]],
                            ],
                        ]),
                        'options' => [
                            'qualities' => [
                                ['sdk_key' => 'origin', 'level' => 10, 'vGear' => 'gear1', 'vCodec' => 'h264', 'vBitrate' => 4000000],
                                ['sdk_key' => 'oda', 'level' => 5, 'vGear' => 'gear2', 'vCodec' => 'h265', 'vBitrate' => 1000000],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}