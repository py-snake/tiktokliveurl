# TikTok Live URL PHP Wrapper - Implementation Plan

> **Constraint:** Shared hosting, PHP only (no Node.js, no Python, no exec)
> **Goal:** Get TikTok live stream URLs for VLC/browser playback

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                   PHP TikTok Live URL Wrapper                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────────┐    ┌──────────────────┐                  │
│  │  RoomResolver    │    │  StreamExtractor │                  │
│  │                  │    │                  │                  │
│  │  1. HTML Scrape  │───▶│  2. Webcast API  │───▶ Stream URLs │
│  │  2. API Fallback │    │  3. Parse URLs   │                  │
│  └──────────────────┘    └──────────────────┘                  │
│           │                       │                             │
│           ▼                       ▼                             │
│  ┌──────────────────┐    ┌──────────────────┐                  │
│  │  HttpClient      │    │  Response Parser │                  │
│  │  (Guzzle + TLS)  │    │  (JSON/Regex)    │                  │
│  └──────────────────┘    └──────────────────┘                  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Key Finding: NO Special TLS Fingerprinting Needed!

After analyzing PirateTok/live-py's actual implementation:

**The webcast API endpoints work with Python's built-in `urllib.request` - no TLS impersonation required.**

```python
# From PirateTok - uses STANDARD urllib, no curl_cffi!
req = urllib.request.Request(url, headers=headers)
opener = _build_opener(proxy)
with opener.open(req, timeout=timeout) as resp:
    body_raw = resp.read().decode("utf-8")
```

The `curl_cffi` with Chrome impersonation is only used as a **fallback** for HTML scraping when the API fails.

### What Actually Works

| Endpoint | Special TLS Needed? | Evidence |
|----------|---------------------|----------|
| `webcast.tiktok.com/webcast/room/info/` | **NO** | PirateTok uses urllib |
| `webcast.tiktok.com/webcast/room/check_alive/` | **NO** | Standard HTTP |
| `www.tiktok.com/api-live/user/room/` | **NO** | Standard HTTP |
| `www.tiktok.com/@{user}/live` (HTML) | Sometimes | Fallback may need impersonation |

### What IS Required

1. **Proper browser-like headers** (User-Agent, Referer, Origin, Sec-Ch-Ua, etc.)
2. **Cookie management** (ttwid helps, not always required)
3. **Standard PHP cURL** - Works fine!

**Conclusion: PHP Guzzle/cURL is sufficient. No special TLS fingerprinting needed.**

---

## Implementation Strategy

### Phase 1: Basic HTTP Client (No TLS Fingerprinting Needed!)

**Good news:** The webcast API endpoints work with standard PHP cURL. No special TLS fingerprinting required.

```php
$client = new \GuzzleHttp\Client([
    'headers' => [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.127 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
        'Accept-Encoding' => 'gzip, deflate, br',
        'Referer' => 'https://www.tiktok.com/',
        'Origin' => 'https://www.tiktok.com',
        'Sec-Ch-Ua' => '"Not/A)Brand";v="8", "Chromium";v="126"',
        'Sec-Ch-Ua-Mobile' => '?0',
        'Sec-Ch-Ua-Platform' => '"Windows"',
        'Sec-Fetch-Site' => 'none',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-User' => '?1',
        'Sec-Fetch-Dest' => 'document',
    ],
    'verify' => false,
    'timeout' => 10,
]);
```

**If HTML scraping fails as fallback**, you have options:
- Use a proxy service with browser impersonation
- Accept that the API method works 95%+ of the time

### Phase 2: Room ID Resolution

#### Method 1: HTML Scraping (Recommended Primary)
```php
function getRoomIdFromHtml(string $username): ?string {
    $url = "https://www.tiktok.com/@{$username}/live";
    
    $response = $this->httpClient->get($url);
    $html = (string) $response->getBody();
    
    // Extract SIGI_STATE JSON
    $pattern = '/<script id="SIGI_STATE" type="application\/json">(.*?)<\/script>/s';
    if (preg_match($pattern, $html, $matches)) {
        $sigiState = json_decode($matches[1], true);
        $roomId = $sigiState['LiveRoom']['liveRoomUserInfo']['liveRoom']['roomId'] ?? null;
        if ($roomId) {
            return $roomId;
        }
    }
    
    return null;
}
```

#### Method 2: API Endpoint (Fallback)
```php
function getRoomIdFromApi(string $username): ?string {
    $url = "https://www.tiktok.com/api-live/user/room/";
    $params = [
        'uniqueId' => $username,
        'sourceType' => '54',
        'aid' => '1988',
        'app_name' => 'tiktok_web',
        'device_platform' => 'web_pc',
        // ... other params
    ];
    
    $response = $this->httpClient->get($url, ['query' => $params]);
    $data = json_decode((string) $response->getBody(), true);
    
    return $data['data']['user']['roomId'] ?? null;
}
```

#### Method 3: Third-Party Service (Last Resort)
```php
function getRoomIdFromService(string $username): ?string {
    // Euler Stream (free tier available)
    $url = "https://tiktok.eulerstream.com/webcast/room_info";
    $params = ['uniqueId' => $username, 'giftInfo' => 'false'];
    
    $response = $this->httpClient->get($url, [
        'query' => $params,
        'headers' => ['x-api-key' => '']  // Free tier
    ]);
    $data = json_decode((string) $response->getBody(), true);
    
    return $data['data']['room_info']['id'] ?? null;
}
```

### Phase 3: Stream URL Extraction

```php
function getStreamUrls(string $roomId): array {
    $url = "https://webcast.tiktok.com/webcast/room/info/";
    $params = [
        'aid' => '1988',
        'room_id' => $roomId
    ];
    
    $response = $this->httpClient->get($url, ['query' => $params]);
    $data = json_decode((string) $response->getBody(), true);
    
    $streamUrl = $data['data']['stream_url'] ?? [];
    
    $urls = [
        'hls' => $streamUrl['hls_pull_url'] ?? null,
        'rtmp' => $streamUrl['rtmp_pull_url'] ?? null,
        'flv' => [],
    ];
    
    // FLV quality tiers
    $flvPullUrl = $streamUrl['flv_pull_url'] ?? [];
    foreach (['FULL_HD1', 'HD1', 'SD2', 'SD1'] as $quality) {
        if (isset($flvPullUrl[$quality])) {
            $urls['flv'][$quality] = $flvPullUrl[$quality];
        }
    }
    
    // Modern SDK stream data
    $sdkDataStr = $streamUrl['live_core_sdk_data']['pull_data']['stream_data'] ?? null;
    if ($sdkDataStr) {
        $sdkData = json_decode($sdkDataStr, true);
        // Parse quality options
    }
    
    return $urls;
}
```

---

## Complete PHP Class Structure

```php
<?php

namespace TikTokLive;

class Client
{
    private \GuzzleHttp\Client $httpClient;
    private ?string $proxy;
    private ?string $cookie;
    
    public function __construct(array $config = [])
    {
        $this->proxy = $config['proxy'] ?? null;
        $this->cookie = $config['cookie'] ?? null;
        
        $this->initHttpClient();
    }
    
    private function initHttpClient(): void
    {
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.127 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Referer' => 'https://www.tiktok.com/',
            'Origin' => 'https://www.tiktok.com',
            'Sec-Ch-Ua' => '"Not/A)Brand";v="8", "Chromium";v="126"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Dest' => 'empty',
        ];
        
        if ($this->cookie) {
            $headers['Cookie'] = $this->cookie;
        }
        
        $options = [
            'headers' => $headers,
            'verify' => false,
            'timeout' => 10,
            'http_errors' => false,
        ];
        
        if ($this->proxy) {
            $options['proxy'] = $this->proxy;
        }
        
        $this->httpClient = new \GuzzleHttp\Client($options);
    }
    
    /**
     * Check if a user is currently live
     */
    public function isLive(string $username): bool
    {
        $roomId = $this->getRoomId($username);
        if (!$roomId) {
            return false;
        }
        
        return $this->checkAlive($roomId);
    }
    
    /**
     * Get room ID for a username
     */
    public function getRoomId(string $username): ?string
    {
        // Method 1: HTML scraping
        $roomId = $this->getRoomIdFromHtml($username);
        if ($roomId) {
            return $roomId;
        }
        
        // Method 2: API
        $roomId = $this->getRoomIdFromApi($username);
        if ($roomId) {
            return $roomId;
        }
        
        // Method 3: Third-party service
        $roomId = $this->getRoomIdFromService($username);
        return $roomId;
    }
    
    /**
     * Get stream URLs for a room
     */
    public function getStreamUrls(string $username): ?StreamUrls
    {
        $roomId = $this->getRoomId($username);
        if (!$roomId) {
            return null;
        }
        
        if (!$this->checkAlive($roomId)) {
            return null;
        }
        
        $url = "https://webcast.tiktok.com/webcast/room/info/";
        $params = [
            'aid' => '1988',
            'room_id' => $roomId
        ];
        
        $response = $this->httpClient->get($url, ['query' => $params]);
        $data = json_decode((string) $response->getBody(), true);
        
        if (($data['status_code'] ?? -1) !== 0) {
            return null;
        }
        
        return StreamUrls::fromApiResponse($data['data']['stream_url'] ?? []);
    }
    
    /**
     * Get HLS URL for browser/VLC playback
     */
    public function getHlsUrl(string $username): ?string
    {
        $urls = $this->getStreamUrls($username);
        return $urls?->hls;
    }
    
    /**
     * Get FLV URL for recording
     */
    public function getFlvUrl(string $username, string $quality = 'HD1'): ?string
    {
        $urls = $this->getStreamUrls($username);
        return $urls->flv[$quality] ?? $urls->flv['SD1'] ?? null;
    }
    
    /**
     * Get RTMP URL
     */
    public function getRtmpUrl(string $username): ?string
    {
        $urls = $this->getStreamUrls($username);
        return $urls?->rtmp;
    }
    
    private function getRoomIdFromHtml(string $username): ?string
    {
        try {
            $url = "https://www.tiktok.com/@{$username}/live";
            $response = $this->httpClient->get($url);
            $html = (string) $response->getBody();
            
            $pattern = '/<script id="SIGI_STATE" type="application\/json">(.*?)<\/script>/s';
            if (preg_match($pattern, $html, $matches)) {
                $sigiState = json_decode($matches[1], true);
                return $sigiState['LiveRoom']['liveRoomUserInfo']['liveRoom']['roomId'] ?? null;
            }
        } catch (\Exception $e) {
            // Silently fail, try next method
        }
        
        return null;
    }
    
    private function getRoomIdFromApi(string $username): ?string
    {
        try {
            $url = "https://www.tiktok.com/api-live/user/room/";
            $params = [
                'uniqueId' => $username,
                'sourceType' => '54',
                'aid' => '1988',
                'app_name' => 'tiktok_web',
                'device_platform' => 'web_pc',
            ];
            
            $response = $this->httpClient->get($url, ['query' => $params]);
            $data = json_decode((string) $response->getBody(), true);
            
            return $data['data']['user']['roomId'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    private function getRoomIdFromService(string $username): ?string
    {
        try {
            $url = "https://tiktok.eulerstream.com/webcast/room_info";
            $params = [
                'uniqueId' => $username,
                'giftInfo' => 'false'
            ];
            
            $response = $this->httpClient->get($url, [
                'query' => $params,
                'headers' => ['x-api-key' => '']
            ]);
            $data = json_decode((string) $response->getBody(), true);
            
            return $data['data']['room_info']['id'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    private function checkAlive(string $roomId): bool
    {
        try {
            $url = "https://webcast.tiktok.com/webcast/room/check_alive/";
            $params = [
                'aid' => '1988',
                'room_ids' => $roomId
            ];
            
            $response = $this->httpClient->get($url, ['query' => $params]);
            $data = json_decode((string) $response->getBody(), true);
            
            $aliveData = $data['data'][0] ?? [];
            return ($aliveData['alive'] ?? false) === true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

class StreamUrls
{
    public ?string $hls;
    public ?string $rtmp;
    public array $flv;
    public array $qualities;
    
    public static function fromApiResponse(array $streamUrl): self
    {
        $instance = new self();
        
        $instance->hls = $streamUrl['hls_pull_url'] ?? null;
        $instance->rtmp = $streamUrl['rtmp_pull_url'] ?? null;
        $instance->flv = [];
        $instance->qualities = [];
        
        // FLV quality tiers
        $flvPullUrl = $streamUrl['flv_pull_url'] ?? [];
        foreach (['FULL_HD1', 'HD1', 'SD2', 'SD1'] as $quality) {
            if (isset($flvPullUrl[$quality])) {
                $instance->flv[$quality] = $flvPullUrl[$quality];
            }
        }
        
        // Quality info from SDK data
        $qualities = $streamUrl['live_core_sdk_data']['pull_data']['options']['qualities'] ?? [];
        foreach ($qualities as $q) {
            $instance->qualities[] = [
                'key' => $q['sdk_key'],
                'level' => $q['level'],
                'codec' => $q['vCodec'],
                'bitrate' => $q['vBitrate'],
                'gear' => $q['vGear'],
            ];
        }
        
        return $instance;
    }
    
    /**
     * Get the best available URL for VLC/browser
     */
    public function getBestUrl(): ?string
    {
        // Prefer HLS for browser/VLC
        if ($this->hls) {
            return $this->hls;
        }
        
        // Fall back to FLV
        foreach (['FULL_HD1', 'HD1', 'SD2', 'SD1'] as $quality) {
            if (isset($this->flv[$quality])) {
                return $this->flv[$quality];
            }
        }
        
        // Fall back to RTMP
        return $this->rtmp;
    }
}
```

---

## Usage Examples

### Basic Usage
```php
<?php
require 'vendor/autoload.php';

use TikTokLive\Client;

$client = new Client();
$username = 'streamer_username';

// Get HLS URL for VLC
$hlsUrl = $client->getHlsUrl($username);
if ($hlsUrl) {
    echo "Open in VLC: " . $hlsUrl . "\n";
    // Or embed in HTML:
    // <video src="<?= $hlsUrl ?>" controls autoplay></video>
}
```

### With Proxy
```php
<?php
$client = new Client([
    'proxy' => 'http://user:pass@proxy:port',
]);

$hlsUrl = $client->getHlsUrl('username');
```

### Check if Live
```php
<?php
$client = new Client();

if ($client->isLive('username')) {
    echo "User is live!\n";
    $urls = $client->getStreamUrls('username');
    echo "HLS: " . $urls->hls . "\n";
}
```

### Get All Quality Options
```php
<?php
$client = new Client();
$urls = $client->getStreamUrls('username');

echo "Available qualities:\n";
foreach ($urls->qualities as $q) {
    echo "- {$q['gear']} ({$q['codec']}, {$q['bitrate']}bps)\n";
}

echo "\nDirect URLs:\n";
echo "HLS: {$urls->hls}\n";
echo "RTMP: {$urls->rtmp}\n";
foreach ($urls->flv as $quality => $url) {
    echo "FLV {$quality}: {$url}\n";
}
```

### Embed in HTML
```php
<?php
$client = new Client();
$urls = $client->getStreamUrls('username');
?>
<!DOCTYPE html>
<html>
<head>
    <title>TikTok Live</title>
</head>
<body>
    <video id="player" controls autoplay></video>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <script>
        const url = '<?= $urls->hls ?>';
        const video = document.getElementById('player');
        
        if (Hls.isSupported()) {
            const hls = new Hls();
            hls.loadSource(url);
            hls.attachMedia(video);
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = url;
        }
    </script>
</body>
</html>
```

---

## Shared Hosting Considerations

### No Major Issues!

Since TLS fingerprinting is NOT required, shared hosting works great:

1. **Standard PHP cURL** - Works perfectly
2. **Guzzle** - Only dependency needed
3. **No exec() needed** - Pure PHP
4. **No special extensions** - Just curl, json, mbstring

### Minor Considerations

1. **Rate Limiting**
   - Shared IPs may be rate-limited after many requests
   - Workaround: Cache room IDs (5-10 min), stream URLs (1-2 min)

2. **Memory Limits**
   - Large JSON responses may hit memory limits
   - Workaround: Use `json_decode` with `assoc: true`, don't load unnecessary data

3. **Open_basedir restrictions**
   - Some hosts restrict file operations
   - Workaround: Use temp directory for caching

### Recommendations

1. **Use a caching layer** (APCu or file-based)
2. **Implement retry logic** with exponential backoff
3. **Cache room IDs** for 5-10 minutes
4. **Cache stream URLs** for 1-2 minutes (they expire)
5. **Use proper headers** - This is the most important thing!

---

## composer.json

```json
{
    "name": "tiktoklive/url-wrapper",
    "description": "PHP wrapper for getting TikTok live stream URLs",
    "require": {
        "php": ">=8.1",
        "guzzlehttp/guzzle": "^7.0"
    },
    "autoload": {
        "psr-4": {
            "TikTokLive\\": "src/"
        }
    }
}
```

---

## Testing Strategy

### Unit Tests
```php
test 'getRoomIdFromHtml extracts room ID from SIGI_STATE';
test 'getRoomIdFromApi returns room ID from API response';
test 'getStreamUrls parses FLV and HLS URLs';
test 'checkAlive returns true for live rooms';
```

### Integration Tests
```php
test 'isLive returns boolean for real username';
test 'getHlsUrl returns valid URL for live user';
test 'getStreamUrls returns multiple quality options';
```

---

## File Structure

```
tiktoklive-php/
├── src/
│   ├── Client.php
│   ├── StreamUrls.php
│   ├── Exception/
│   │   ├── TikTokException.php
│   │   ├── UserNotFoundException.php
│   │   └── StreamNotAvailableException.php
│   └── HttpClient/
│       └── GuzzleHttpClient.php
├── tests/
│   ├── ClientTest.php
│   └── StreamUrlsTest.php
├── composer.json
├── phpunit.xml
└── README.md
```

---

## Implementation Priority

| Priority | Feature | Complexity |
|----------|---------|------------|
| P0 | HTTP Client with headers | Low |
| P0 | getRoomId() from HTML | Medium |
| P0 | getStreamUrls() from webcast API | Medium |
| P1 | Proxy support | Low |
| P1 | Caching layer | Medium |
| P2 | Third-party service fallback | Low |
| P2 | Error handling/retry logic | Medium |
| P3 | WebSocket events (optional) | High |

---

## Summary

**Good news:** You DON'T need complex signature generation for live stream URLs. The webcast API endpoints work with proper HTTP headers.

**The main challenge** is TLS fingerprinting - PHP's cURL may get blocked. Solutions:
1. Use Guzzle with proper headers (works for moderate usage)
2. Use a proxy service with browser impersonation
3. Cache aggressively to reduce requests

**Minimal viable implementation:**
1. Guzzle HTTP client with browser headers
2. HTML scraping for room ID
3. Webcast API for stream URLs
4. Simple caching

This can be done in ~300 lines of PHP with no external dependencies beyond Guzzle.
