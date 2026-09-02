# TikTok API Features - Research Report

> **Purpose:** Inform the development of a TikTok API PHP wrapper for getting live stream URLs and watching in VLC/browser.
> **Date:** 2026-08-30
> **Constraint:** Shared hosting, PHP only (no Node.js, no Python, no exec)
> **See also:** [php-wrapper-plan.md](php-wrapper-plan.md) for pure PHP implementation strategy

---

## Table of Contents

- [Repository Activity Map](#repository-activity-map)
- [Core API Endpoints](#core-api-endpoints)
- [Live Stream API Features](#live-stream-api-features)
- [Signature & Authentication](#signature--authentication)
- [Existing PHP Implementations](#existing-php-implementations)
- [Data Structures](#data-structures)
- [Anti-Bot & Rate Limiting](#anti-bot--rate-limiting)
- [Recommendations for PHP Wrapper](#recommendations-for-php-wrapper)

---

## Repository Activity Map

### Primary Repos (User-Provided)

| Repo | Language | Stars | Last Updated | Activity | Relevance |
|------|----------|-------|--------------|----------|-----------|
| [Michele0303/TikTok-Live-Recorder](https://github.com/Michele0303/TikTok-Live-Recorder) | Python | High | 2026-08 | ✅ Active | **CRITICAL** - Live stream URL extraction |
| [carcabot/tiktok-signature](https://github.com/carcabot/tiktok-signature) | Node.js | High | 2026-08 | ✅ Active | **CRITICAL** - X-Bogus/X-Gnarly signature generation |
| [davidteather/tiktok-api](https://github.com/davidteather/tiktok-api) | Python | High | 2026-08 | ✅ Active | User/video/trending data |
| [tiktok/sparkling](https://github.com/tiktok/sparkling) | TypeScript | High | 2026-08 | ✅ Active | TikTok internal infrastructure (Lynx) |
| [drawrowfly/tiktok-scraper](https://github.com/drawrowfly/tiktok-scraper) | Node.js | High | 2026-08 | ✅ Active | Video/user/hashtag scraping |
| [isaackogan/TikTokLive](https://github.com/isaackogan/TikTokLive) | Python | High | 2026-08 | ✅ Active | **CRITICAL** - Live stream WebSocket events |
| [armxe/tiktok-api](https://github.com/armxe/tiktok-api) | Python | Medium | 2026-03 | ✅ Active | Mobile/Web signature algorithms |

### Additional Active Repos Discovered

| Repo | Language | Stars | Last Updated | Relevance |
|------|----------|-------|--------------|-----------|
| [zerodytrash/TikTok-Live-Connector](https://github.com/zerodytrash/TikTok-Live-Connector) | TypeScript | 2136 | 2026-08-29 | **CRITICAL** - Node.js live stream events |
| [PirateTok/live-py](https://github.com/PirateTok/live-py) | Python | 4 | 2026-08-30 | **CRITICAL** - Zero-dependency live connector |
| [PirateTok/live-rs](https://github.com/PirateTok/live-rs) | Rust | 4 | 2026-07-27 | Live connector in Rust |
| [PirateTok/live-go](https://github.com/PirateTok/live-go) | Go | 2 | 2026-07-03 | Live connector in Go |
| [PirateTok/live-js](https://github.com/PirateTok/live-js) | TypeScript | 0 | 2026-05-05 | Live connector in JS |
| [PirateTok/live-cs](https://github.com/PirateTok/live-cs) | C# | 1 | 2026-05-24 | Live connector in C# |
| [PirateTok/live-java](https://github.com/PirateTok/live-java) | Java | 2 | 2026-07-27 | Live connector in Java |
| [PirateTok/live-lua](https://github.com/PirateTok/live-lua) | Lua | 1 | 2026-05-02 | Live connector in Lua |
| [PirateTok/live-dart](https://github.com/PirateTok/live-dart) | Dart | 0 | 2026-04-10 | Live connector in Dart |
| [PirateTok/live-ex](https://github.com/PirateTok/live-ex) | Elixir | 1 | 2026-05-27 | Live connector in Elixir |
| [PirateTok/live-sh](https://github.com/PirateTok/live-sh) | Shell | 0 | 2026-04-04 | Live connector in POSIX sh |
| [jwdeveloper/TikTokLiveJava](https://github.com/jwdeveloper/TikTokLiveJava) | Java | 211 | 2026-08-26 | Java live connector |
| [Diminishing-scree890/tiktok-live-python](https://github.com/Diminishing-scree890/tiktok-live-python) | Python | 0 | 2026-08-30 | Python live data streaming |

---

## Core API Endpoints

### TikTok Web API Base URLs

| URL | Purpose |
|-----|---------|
| `https://www.tiktok.com` | Main web app |
| `https://webcast.tiktok.com` | Live stream/Webcast API |
| `https://api.tiktokv.com` | Mobile API (Android/iOS) |
| `https://tiktok.eulerstream.com` | Third-party signing service |

### Key API Routes

#### 1. Room Info (Live Stream Details)
```
GET https://webcast.tiktok.com/webcast/room/info/?aid=1988&room_id={room_id}
```
**Returns:**
- Stream status (`status: 2` = live)
- Stream URLs (FLV, HLS, RTMP)
- Stream quality options
- Room metadata (title, viewer count, etc.)
- Owner info

#### 2. Room Alive Check
```
GET https://webcast.tiktok.com/webcast/room/check_alive/?aid=1988&region=CH&room_ids={room_id}&user_is_login=true
```
**Returns:**
- `alive: true/false`

#### 3. User Room ID Resolution
```
GET https://www.tiktok.com/api-live/user/room/?uniqueId={username}&giftInfo=false
```
**Returns:**
- `data.user.roomId` - The active room ID

#### 4. User Profile
```
GET https://www.tiktok.com/api/user/list/?...&secUid={secUid}
```

#### 5. User Followers
```
GET https://www.tiktok.com/api/user/list/?...&secUid={secUid}&maxCursor={cursor}
```

#### 6. Video Feed
```
GET https://www.tiktok.com/api/post/item_list/?secUid={secUid}&cursor=0&count=30
```

#### 7. Trending
```
GET https://www.tiktok.com/api/awe/v1/general/search/?...
```

#### 8. Hashtag Info
```
GET https://www.tiktok.com/api/challenge/detail/?challengeID={id}
```

---

## Live Stream API Features

### Stream URL Extraction (CRITICAL)

#### Method 1: Webcast Room Info API
The primary method used by most tools:

```python
# From Michele0303/TikTok-Live-Recorder (src/core/tiktok_api.py)
response = http_client.get(
    f"https://webcast.tiktok.com/webcast/room/info/?aid=1988&room_id={room_id}"
)
data = response.json()

stream_url = data.get("data", {}).get("stream_url", {})

# Legacy URLs (fallback)
flv_pull_url = stream_url.get("flv_pull_url", {})
# Keys: FULL_HD1, HD1, SD2, SD1
hls_pull_url = stream_url.get("hls_pull_url")  # M3U8 playlist
rtmp_pull_url = stream_url.get("rtmp_pull_url")  # RTMP stream

# Modern SDK stream data
sdk_data_str = stream_url.get("live_core_sdk_data", {}).get("pull_data", {}).get("stream_data")
# Parsed JSON with quality tiers
qualities = stream_url.get("live_core_sdk_data", {}).get("pull_data", {}).get("options", {}).get("qualities", [])
# Each quality has: sdk_key, level, vCodec, vBitrate, vGear, aBitrate, aGear, fps,ileeType
```

#### Method 2: HTML Page Scraping (Fallback)
When API returns status code `4003110` (WAF blocked):

```python
# From Michele0303/TikTok-Live-Recorder
response = http_client.get(f"https://www.tiktok.com/@{user}/live")
content = response.text

# Extract FLV URLs
flv_matches = re.findall(r'https?://[^\s"\'<>]+\.flv[^\s"\'<>]*', content)

# Extract HLS URLs
hls_matches = re.findall(r'https?://[^\s"\'<>]+\.m3u8[^\s"\'<>]*', content)
```

#### Method 3: Third-Party Sign Services
Using signing services to bypass anti-bot:

```python
# Euler Stream API
response = http_client.get(
    "https://tiktok.eulerstream.com/webcast/room_info",
    params={"uniqueId": username, "giftInfo": "false"},
    headers={"x-api-key": "your-api-key"}
)

# TikRec signing service
response = http_client.get(
    "https://tikrec.com/tiktok/room/api/sign",
    params={"unique_id": username}
)
signed_path = response.json().get("signed_path")
```

### Stream URL Structure

TikTok live streams are available in multiple formats:

| Format | Protocol | URL Pattern | Best For |
|--------|----------|-------------|----------|
| **FLV** | HTTP-FLV | `https://.../*.flv` | Recording, low latency |
| **HLS** | HTTP | `https://.../*.m3u8` | Browser playback, adaptive |
| **RTMP** | RTMP | `rtmp://...` | VLC, OBS |

#### FLV Quality Tiers
```
FULL_HD1 - Full HD (1080p)
HD1       - HD (720p)
SD2       - Standard Definition
SD1       - Low Definition
```

#### HLS Playlist
```m3u8
#EXTM3U
#EXT-X-STREAM-INF:BANDWIDTH=xxx,RESOLUTION=xxx
https://.../{stream_id}_medium.m3u8
```

### Stream Data Object Structure

```json
{
  "stream_url": {
    "flv_pull_url": {
      "FULL_HD1": "https://...",
      "HD1": "https://...",
      "SD2": "https://...",
      "SD1": "https://..."
    },
    "hls_pull_url": "https://.../playlist.m3u8",
    "hls_pull_url_map": {...},
    "rtmp_pull_url": "rtmp://...",
    "live_core_sdk_data": {
      "pull_data": {
        "stream_data": "{\"data\":{...},\"expire_time\":...}",
        "options": {
          "qualities": [
            {
              "sdk_key": "uhd",
              "level": 3,
              "vCodec": "h264",
              "vBitrate": 1500000,
              "vGear": "UHD",
              "aBitrate": 128000,
              "aGear": "STANDARD",
              "fps": 30
            }
          ]
        }
      }
    }
  }
}
```

### Room Info Response Structure

```json
{
  "status_code": 0,
  "data": {
    "room": {
      "id": "7xxxxxxxxxxxxxxxxx",
      "status": 2,
      "title": "Stream Title",
      "create_time": 1234567890,
      "cover": {
        "url_list": ["https://..."]
      },
      "owner": {
        "id": "...",
        "nickname": "Streamer",
        "display_id": "username",
        "avatar_thumb": {
          "url_list": ["https://..."]
        },
        "bio_description": "Bio",
        "follow_info": {
          "follow_count": 12345
        }
      },
      "stats": {
        "total_user_str": "1.2K",
        "user_count_str": "500"
      }
    },
    "stream_url": {...}
  }
}
```

### Live Event Types (WebSocket)

From TikTokLive and TikTok-Live-Connector, the following real-time events are available:

| Event | Description |
|-------|-------------|
| `CommentEvent` | Chat message received |
| `GiftEvent` | Gift sent (with streak support) |
| `LikeEvent` | Like received |
| `FollowEvent` | New follower |
| `ShareEvent` | Stream shared |
| `JoinEvent` | Viewer joined |
| `RoomUserSeqEvent` | Viewer count update |
| `LinkMicBattleEvent` | Battle started |
| `LinkMicArmiesEvent` | Battle points |
| `PollEvent` | Poll update |
| `QuestionNewEvent` | New question |
| `SuperFanEvent` | Super fan status |
| `LiveEndEvent` | Stream ended |
| `LivePauseEvent` | Stream paused |
| `CaptionEvent` | Auto-caption update |
| `RoomPinEvent` | Message pinned |
| `GoalUpdateEvent` | Goal update |
| `EmoteChatEvent` | Emote sent |
| `EnvelopeEvent` | Treasure chest |
| `BarrageEvent` | VIP viewer joined |
| `ControlEvent` | Stream control action |
| `ImDeleteEvent` | Message deleted |
| `RankUpdateEvent` | Rank update |
| `RankTextEvent` | Rank text |
| `RoomEvent` | Room broadcast |
| `SocialEvent` | Social action |
| `SystemEvent` | System message |
| `LinkMicMethodEvent` | Link-mic action |
| `LinkMicFanTicketMethodEvent` | Fan ticket |
| `OecLiveShoppingEvent` | Live shopping |
| `MsgDetectEvent` | Moderation detect |
| `LinkMessageEvent` | Link-mic message |
| `LinkLayerEvent` | Link-mic layer |
| `RoomVerifyEvent` | Room verification |
| `UnauthorizedMemberEvent` | Unauthorized action |
| `SpeakerEvent` | Active speaker |
| `SubNotifyEvent` | Subscription notification |
| `GiftBroadcastEvent` | Big gift broadcast |
| `GiftDynamicRestrictionEvent` | Gift restriction |
| `GiftPanelUpdateEvent` | Gift panel update |
| `GiftPromptEvent` | Gift prompt |
| `GuideEvent` | In-room guide |
| `NoticeEvent` | System notice |
| `ToastEvent` | Toast notification |
| `ViewerPicksUpdateEvent` | Viewer picks |
| `MarqueeAnnouncementEvent` | Marquee announcement |
| `BoostCardEvent` | Boost card |
| `CapsuleEvent` | Capsule notice |
| `BottomEvent` | Bottom notice |
| `PerceptionEvent` | Warning/violation |
| `RoomNotifyEvent` | Room notification |
| `PartnershipDropsUpdateEvent` | Partnership drops |
| `PartnershipGameOfflineEvent` | Game offline |
| `PartnershipPunishEvent` | Partnership punishment |
| `LiveGameIntroEvent` | Game intro |
| `GameRankNotifyEvent` | Game rank |
| `AccessControlEvent` | Access control/CAPTCHA |
| `AccessRecallEvent` | Access lifted |
| `LinkMicLayoutStateEvent` | Layout state |
| `LinkStateEvent` | Link state |
| `SubPinEventEvent` | Subscription pin |

---

## Signature & Authentication

### X-Bogus Signature (Web)

**File:** `carcabot/tiktok-signature` - Generates X-Bogus and X-Gnarly signatures.

**Pipeline:**
1. Double-MD5 of URL params and request body
2. RC4-encrypt User-Agent with key `[0,1,14]`
3. Base64 + MD5
4. Assemble salt array (timestamp, magic `536919696`, hashes)
5. Filter → scramble → RC4-encrypt (key `[255]`)
6. Prefix `\x02\xFF`, encode with shifted base64 alphabet

**API Endpoint:**
```bash
POST http://localhost:8080/signature
{
  "url": "https://www.tiktok.com/api/post/item_list/?..."
}

Response:
{
  "status": "ok",
  "data": {
    "signed_url": "https://www.tiktok.com/api/post/item_list/?...&X-Bogus=...&X-Gnarly=...",
    "x-bogus": "DFSzswVLXdxANGP5CtmFF2lUrn/4",
    "x-gnarly": "M8tHhQ2H0Kh/XPpeEgkaXo20D9uW...",
    "device-id": "7520531026079925774",
    "cookies": "tt_chain_token=...; ttwid=...; tt_csrf_token=...",
    "navigator": {
      "user_agent": "Mozilla/5.0 ...",
      "platform": "MacIntel",
      "browser_language": "en-US",
      "os": "mac",
      "screen_width": "1920",
      "screen_height": "1080"
    }
  }
}
```

### X-Argus / X-Gorgon (Mobile)

**File:** `armxe/tiktok-api` - Mobile signing pipeline.

| Header | Description |
|--------|-------------|
| `X-Argus` | Main mobile signature (protobuf → SM3 → SIMON → AES) |
| `X-Gorgon` | Secondary integrity header |
| `X-Ladon` | Lightweight token |
| `X-Khronos` | Current Unix timestamp |

**Quick usage:**
```python
from Mobile import Metasec

signer = Metasec()
headers = signer.sign(
    url="https://api.tiktokv.com/aweme/v1/feed/?...",
    app_id=1233,
    app_version="25.1.1",
    device_type="SM-G973N",
    # ... more params
)
# headers = {"x-argus": "...", "x-gorgon": "...", "x-ladon": "...", "x-khronos": "..."}
```

### X-Bogus (Web - Python Implementation)

**File:** `armxe/tiktok-api/Web/bogus.py`

```python
# Pipeline:
# 1. Double-MD5 of URL params and body
# 2. RC4 encrypt User-Agent
# 3. Base64 + MD5
# 4. Salt array assembly
# 5. Filter → scramble → RC4
# 6. Prefix \x02\xFF → shifted base64
```

### TTEncrypt

**File:** `armxe/tiktok-api/TTEncrypt/ttencrypt.py`

Encrypts raw request payload:
- Custom AES-based cipher with hardcoded S-boxes
- gzip compression before encryption
- Magic header: `[0x74, 0x63, 0x05, 0x10, 0x00, 0x00]`

### WebSocket Signing

For live stream connections, WebSocket URLs require signing:

```javascript
// From zerodytrash/TikTok-Live-Connector
SignConfig.apiKey = 'your-api-key';
SignConfig.basePath = 'https://your-custom-sign-server.com';

const connection = new TikTokLiveConnection(username, {
    signApiKey: 'your-api-key'
});
```

### Authentication (Optional)

For sending messages or accessing restricted content:

```javascript
// Session cookies required
session: {
    cookie: {
        type: 'cookie',
        value: {
            sessionId: '<account_session_id>',
            ttTargetIdc: '<account_target_idc>'
        }
    }
}
```

---

## Existing PHP Implementations

### 1. TikScraperPHP (pablouser1/TikScraperPHP)

**Status:** 75 stars, 22 forks, maintained
**Last Updated:** 2026-07-03

```php
$api = new \TikScraper\Api([
    'debug' => false,
    'browser' => [
        'url' => 'http://localhost:4444', // ChromeDriver instance
    ],
    'verify_fp' => 'verify_...',
    'device_id' => '596845...',
    'user_agent' => 'CUSTOM_USER_AGENT',
    'proxy' => 'http://user:password@hostname:port'
]);

// Get user videos
$user = $api->user('username');
$user->feed();

// Get hashtag videos
$hashtag = $api->hashtag('funny');
$hashtag->feed();

// Get video info
$video = $api->video('video_id');
```

**Features:**
- User feed scraping
- Hashtag feed scraping
- Music feed scraping
- Video metadata extraction
- Caching support (ICache interface)
- ChromeDriver integration for signature generation
- Proxy support

**Limitations:**
- No live stream URL extraction
- Requires ChromeDriver running
- No WebSocket/event support

### 2. tiktok-php-sdk (msaaqcom/tiktok-php-sdk)

**Status:** 6 stars, 7 forks
**Purpose:** TikTok Events API PHP Wrapper (for TikTok Pixel/Events API)
**Not relevant for live stream scraping**

### 3. tiktok-marketing-api (promopult/tiktok-marketing-api)

**Status:** 11 stars, 10 forks
**Purpose:** TikTok Ads API wrapper
**Not relevant for live stream scraping**

### 4. php-social-video-downloader (oiv-an/php-social-video-downloader)

**Status:** 1 star
**Purpose:** yt-dlp wrapper for video downloads
**Could potentially be used for TikTok video downloads**

### 5. izisaurio/tiktok-api

**Status:** 0 stars
**Purpose:** TikTok API PHP wrapper for login and publishing
**Not relevant for live stream scraping**

---

## Data Structures

### User Profile Response

```json
{
  "secUid": "MS4wLjABAAAA...",
  "userId": "107955",
  "uniqueId": "tiktok",
  "nickName": "TikTok",
  "signature": "Make Your Day",
  "following": 490,
  "fans": 38040567,
  "heart": "211522962",
  "video": 93,
  "verified": true
}
```

### Video Feed Response

```json
{
  "id": "VIDEO_ID",
  "text": "CAPTION",
  "createTime": "1583870600",
  "authorMeta": {
    "id": "USER_ID",
    "name": "USERNAME",
    "fans": 43500,
    "heart": "1093998",
    "verified": false
  },
  "musicMeta": {
    "musicId": "6808098113188120838",
    "musicName": "song name",
    "playUrl": "SOUND_URL"
  },
  "videoUrl": "VIDEO_URL",
  "videoUrlNoWaterMark": "VIDEO_URL_NO_WATERMARK",
  "videoMeta": {
    "width": 480,
    "height": 864,
    "duration": 14
  },
  "diggCount": 2104,
  "shareCount": 1,
  "playCount": 9007,
  "commentCount": 50,
  "hashtags": [...]
}
```

### Hashtag Info Response

```json
{
  "challengeId": "4231",
  "challengeName": "love",
  "posts": 66904972,
  "views": "194557706433",
  "isCommerce": false
}
```

---

## Anti-Bot & Rate Limiting

### Common Issues

1. **IP Reputation** - Residential IPs treated with less scrutiny than datacenter IPs
2. **WAF Block (4003110)** - Access restriction, requires fallback methods
3. **EmptyResponseException** - TikTok blocking requests
4. **Captcha/verifyFp** - Verification challenge

### Mitigation Strategies

1. **Residential Proxies** - Highly recommended for production
   - Bright Data, Oxylabs, Smartproxy, IPRoyal
2. **User-Agent Rotation** - Match signature to User-Agent
3. **Cookie Management** - Use `sid_tt` session cookies
4. **Request Delays** - 1-2 second delays between requests
5. **Session Refresh** - Refresh browser sessions periodically
6. **Signing Services** - Use Euler Stream or custom sign servers

### Required Headers for Video Access

```javascript
{
  "user-agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) ...",
  "referer": "https://www.tiktok.com/",
  "cookie": "tt_webid_v2=689854141086886123"
}
```

**Note:** The `videoUrl` is bound to the `tt_webid_v2` cookie value. You must use the same headers that were used to extract the URL.

---

## Recommendations for PHP Wrapper

### Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    PHP TikTok Live Wrapper                   │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐     │
│  │   HTTP      │    │  Signature  │    │   Stream    │     │
│  │   Client    │───▶│  Generator  │───▶│   Extractor │     │
│  └─────────────┘    └─────────────┘    └─────────────┘     │
│         │                   │                   │           │
│         ▼                   ▼                   ▼           │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐     │
│  │  Room ID    │    │  X-Bogus    │    │   FLV/HLS   │     │
│  │  Resolver   │    │  X-Gnarly   │    │   URLs      │     │
│  └─────────────┘    └─────────────┘    └─────────────┘     │
│                                                             │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐     │
│  │   Cache     │    │   Proxy     │    │   Logger    │     │
│  │   Layer     │    │   Manager   │    │             │     │
│  └─────────────┘    └─────────────┘    └─────────────┘     │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Recommended Implementation Stack

1. **HTTP Client:** Guzzle 7+ (with proxy support)
2. **Signature Generation:**
   - Option A: Self-hosted `carcabot/tiktok-signature` Node.js service
   - Option B: Integrate `armxe/tiktok-api` Python signing (via exec or FFI)
   - Option C: Port X-Bogus algorithm to PHP (complex)
3. **Caching:** Redis or APCu for room IDs and session tokens
4. **Proxy:** Support HTTP/SOCKS5 proxies

### Core Classes to Implement

```php
class TikTokLive {
    // Room resolution
    public function getRoomId(string $username): ?string;
    public function getRoomInfo(string $roomId): RoomInfo;
    public function isLive(string $username): bool;
    
    // Stream URLs
    public function getStreamUrls(string $roomId): StreamUrls;
    public function getHlsUrl(string $roomId): ?string;
    public function getFlvUrl(string $roomId, string $quality = 'HD1'): ?string;
    public function getRtmpUrl(string $roomId): ?string;
    
    // User info
    public function getUserInfo(string $username): UserInfo;
    public function getFollowers(string $username): array;
    
    // Recording
    public function recordStream(string $url, string $output, int $duration = 0): void;
}

class StreamUrls {
    public ?string $hls;        // M3U8 playlist URL
    public ?string $rtmp;       // RTMP URL
    public array $flv;          // FLV URLs by quality
    public array $qualities;    // Available quality options
}

class RoomInfo {
    public string $roomId;
    public string $title;
    public int $viewerCount;
    public int $likeCount;
    public string $status;
    public StreamUrls $streamUrl;
    public UserInfo $owner;
}
```

### Step-by-Step Implementation Plan

1. **Phase 1: Basic Room Resolution**
   - Implement `getRoomId()` using webcast API
   - Add fallback to HTML scraping
   - Add third-party signing service support

2. **Phase 2: Stream URL Extraction**
   - Implement `getStreamUrls()` from room info
   - Parse SDK stream data for quality options
   - Handle legacy URL fallbacks

3. **Phase 3: Signature Integration**
   - Set up Node.js signature service (Docker)
   - OR implement PHP signing (port from Python/Node)
   - Add X-Bogus/X-Gnarly generation

4. **Phase 4: Proxy & Anti-Bot**
   - Add proxy rotation support
   - Implement User-Agent rotation
   - Add cookie management
   - Implement retry logic

5. **Phase 5: Advanced Features**
   - WebSocket event listener (optional)
   - Multi-user monitoring
   - Recording with FFmpeg
   - Webhook notifications

### Quick Reference: Getting Live URL in PHP

```php
<?php
require 'vendor/autoload.php';

use TikTokLive\TikTokLive;

$client = new TikTokLive([
    'proxy' => 'http://user:pass@host:port',
    'sign_api_key' => 'your-euler-stream-key', // Optional
]);

$username = 'streamer_username';

// Check if live
if (!$client->isLive($username)) {
    echo "User is not live\n";
    exit;
}

// Get stream URLs
$urls = $client->getStreamUrls($client->getRoomId($username));

// Play in VLC
echo "VLC URL: " . $urls->hls . "\n";
// Or: vlc $urls->hls

// Play in browser (embed)
echo '<video src="' . $urls->hls . '" controls autoplay></video>';

// FLV for recording
echo "FLV URL: " . $urls->flv['HD1'] . "\n";
```

---

## Key Insights for PHP Wrapper

### CRITICAL FINDING: Signatures NOT Required for Live URLs

After analyzing Michele0303/TikTok-Live-Recorder and PirateTok/live-py:

**The webcast API endpoints for live stream URLs do NOT require X-Bogus/X-Gnarly signatures.**

The key endpoints work with just proper HTTP headers:
- `webcast.tiktok.com/webcast/room/info/` - Returns stream URLs
- `webcast.tiktok.com/webcast/room/check_alive/` - Check if live
- `www.tiktok.com/api-live/user/room/` - Resolve username to room ID

**What IS required:**
1. Proper browser-like headers (User-Agent, Referer, etc.)
2. TLS fingerprint that looks like a real browser (the hard part for PHP)
3. Cookie management (ttwid, etc.)

**See [php-wrapper-plan.md](php-wrapper-plan.md) for pure PHP implementation strategy.**

### Critical Success Factors

1. **TLS Fingerprinting is the Main Challenge** - PHP cURL has a distinctive fingerprint
2. **Room ID Resolution Has Multiple Paths** - HTML scrape → API → Third-party fallback
3. **Stream URLs Are Ephemeral** - They expire and need refresh
4. **Headers Must Match Signatures** - User-Agent must match the one used for signing
5. **Proxy is Almost Required** - For reliable, high-volume access

### What Other Languages Have That PHP Lacks

| Feature | Python | Node.js | PHP Status |
|---------|--------|---------|------------|
| Live Stream URL Extraction | ✅ | ✅ | ❌ Need to implement |
| WebSocket Events | ✅ TikTokLive | ✅ TikTokLiveConnector | ❌ Complex in PHP |
| X-Bogus Generation | ✅ armxe | ✅ carcabot | ❌ Need to port or use service |
| Room Info API | ✅ | ✅ | ❌ Need to implement |
| Proxy Support | ✅ | ✅ | ❌ Need to implement |

### Recommended Approach

**For PHP wrapper focused on live stream URLs:**

1. **Use a Node.js microservice** for signature generation (carcabot/tiktok-signature)
2. **Implement HTTP client** in PHP with Guzzle
3. **Port room resolution logic** from Michele0303/TikTok-Live-Recorder
4. **Parse stream URLs** from room info response
5. **Provide simple API** for VLC/browser playback

This hybrid approach leverages existing battle-tested code while keeping the core logic in PHP.

---

## References

### Essential Reading

- [Michele0303/TikTok-Live-Recorder](https://github.com/Michele0303/TikTok-Live-Recorder) - Stream URL extraction
- [carcabot/tiktok-signature](https://github.com/carcabot/tiktok-signature) - Signature generation
- [isaackogan/TikTokLive](https://github.com/isaackogan/TikTokLive) - Live events documentation
- [zerodytrash/TikTok-Live-Connector](https://github.com/zerodytrash/TikTok-Live-Connector) - Node.js implementation
- [armxe/tiktok-api](https://github.com/armxe/tiktok-api) - Mobile/Web signing algorithms
- [PirateTok/live-py](https://github.com/PirateTok/live-py) - Zero-dependency live connector

### API Documentation Points

- Room Info: `https://webcast.tiktok.com/webcast/room/info/`
- Room Check: `https://webcast.tiktok.com/webcast/room/check_alive/`
- User Room: `https://www.tiktok.com/api-live/user/room/`
- Signature Service: `https://tiktok.eulerstream.com`

---

*Report generated from analysis of 15+ repositories and their source code.*
