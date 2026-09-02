# TikTok CDN "Access Denied" — Circumvention Research

> **Date:** 2026-08-30
> **Context:** Shared hosting PHP app monitoring TikTok live streams
> **Problem:** CDN nodes block direct access; proxy on serv00 can't reach CDN

---

## Table of Contents

- [Observed CDN Behavior](#observed-cdn-behavior)
- [WAF Arms Race Timeline](#waf-arms-race-timeline)
- [Approach 1: Direct Headers (What Works)](#approach-1-direct-headers-what-works)
- [Approach 2: TLS Impersonation (curl_cffi)](#approach-2-tls-impersonation-curl_cffi)
- [Approach 3: Cloudflare Worker Proxy](#approach-3-cloudflare-worker-proxy)
- [Approach 4: Local Proxy with Headers](#approach-4-local-proxy-with-headers)
- [Approach 5: M3U8 Rewriting Proxy](#approach-5-m3u8-rewriting-proxy)
- [Approach 6: CDN URL Append (EulerStream)](#approach-6-cdn-url-append-eulerstream)
- [Approach 7: yt-dlp curl_cffi Stack](#approach-7-yt-dlp-curl_cffi-stack)
- [CDN Node Classification](#cdn-node-classification)
- [Required Headers](#required-headers)
- [Implementation for Our Project](#implementation-for-our-project)

---

## Observed CDN Behavior

### Test Results (2026-08-30)

| CDN Node | Protocol | Direct (no headers) | Direct (with headers) | Via serv00 proxy |
|----------|----------|--------------------|-----------------------|------------------|
| `pull-f5-tt04` | FLV | Timeout | **200 OK** (streaming) | Timeout (serv00 network) |
| `pull-hls-f16-tt04` | HLS | 403 | 403 | 403 (upstream) |
| `pull-flv-w60-sg01` | FLV | 404 | 404 | 502 (upstream 404) |

### Key Findings

1. **`pull-f5-*` FLV nodes**: Work with proper `Referer` + `User-Agent` headers from residential/datacenter IPs
2. **`pull-hls-*` HLS nodes**: **Permanently blocked** by Akamai — 403 regardless of headers or source IP
3. **`pull-flv-w60-*` nodes**: Appear to be regional or expired — 404 even with headers
4. **serv00 proxy**: Can't reach TikTok CDN (network/TLS issue on shared hosting)

### Akamai 403 Response Headers

```
server: AkamaiGHost
content-type: text/html
x-tt-trace-tag: id=16;cdn-cache=miss;type=dyn
akamai-mon-iucid-del: 1593435
```

The `type=dyn` indicates a dynamic origin request that Akamai rejected. This is not a caching issue — the CDN actively blocks HLS segment requests from non-TikTok clients.

---

## WAF Arms Race Timeline

TikTok's WAF evolved through 4 major versions in 20 days (August 2026):

| Rung | Date | WAF Key | What Blocks | Bypass |
|------|------|---------|-------------|--------|
| 1 | Aug 06 | Non-browser TLS on shared host | Standard cURL from shared hosting | TLS impersonation |
| 2 | Aug 10 | curl_cffi impersonation fingerprint | curl_cffi Chrome impersonation | Unimpersonated canonical URL |
| 3 | Aug 18 | Plain-client HTTP header fingerprint | Static headers | Randomized headers |
| 4 | Aug 25 | Challenge flow requiring browser-grade client | Most automated tools | **Impersonated (curl_cffi) + challenge solver** |

Source: yt-dlp incident reports and community tracking.

---

## Approach 1: Direct Headers (What Works)

**For `pull-f5-*` FLV nodes**, simple headers are sufficient:

```bash
curl -H "Referer: https://www.tiktok.com/" \
     -H "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.127 Safari/537.36" \
     "https://pull-f5-tt04.tiktokcdn-eu.com/stage/stream_xxx.flv?expire=...&sign=..."
```

**Result:** HTTP 200, streaming FLV data.

**Limitation:** Only works for FLV, not HLS. The HLS CDN nodes (`pull-hls-*`) block all external requests.

---

## Approach 2: TLS Impersonation (curl_cffi)

**Repository:** `Michele0303/TikTok-Live-Recorder`
**Language:** Python
**Approach:** `curl_cffi` impersonates Chrome's TLS fingerprint (JA3 hash)

### How It Works

`curl_cffi` is a Python binding for curl that supports TLS fingerprint impersonation. It makes your HTTPS requests look like they're coming from a real Chrome browser at the TLS level.

### Implementation

```python
# From Michele0303/TikTok-Live-Recorder/src/http_utils/http_client.py
from curl_cffi import Session, CurlSslVersion, CurlOpt

class HttpClient:
    def configure_session(self) -> None:
        self.req = Session(
            impersonate="chrome136",  # Impersonates Chrome 136 TLS fingerprint
            http_version="v1",
            curl_options={CurlOpt.SSLVERSION: CurlSslVersion.TLSv1_2},
        )
        self.req.headers.update({
            "Sec-Ch-Ua": '"Not/A)Brand";v="8", "Chromium";v="126"',
            "Sec-Ch-Ua-Mobile": "?0",
            "Sec-Ch-Ua-Platform": '"Windows"',
            "Accept-Language": "en-US",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) ...",
            "Referer": "https://www.tiktok.com/",
            "Origin": "https://www.tiktok.com",
        })
```

### CDN Bypass Strategy

```python
# From Michele0303 - multiple fallback sources for stream URLs
def get_stream_urls(self, user):
    # 1. Try tikrec.com signing service
    signed_url = self._tikrec_get_room_id_signed_url(user)
    
    # 2. Try EulerStream API
    response = self.http_client.get(
        f"{self.EULER_API}/webcast/room_info",
        params={"uniqueId": user, "giftInfo": "false"},
    )
    
    # 3. HTML page scraping (regex)
    response = self.http_client.get(f"https://www.tiktok.com/@{user}/live")
    flv_matches = re.findall(r'https?://[^\s"\'<>]+\.flv[^\s"\'<>]*', content)
    hls_matches = re.findall(r'https?://[^\s"\'<>]+\.m3u8[^\s"\'<>]*', content)
```

### WAF Detection

```python
def get_room_id_from_user(self, user: str) -> str | None:
    response = self.http_client.get(signed_url)
    if not response.text or "Please wait" in response.text:
        raise UserLiveError(TikTokError.WAF_BLOCKED)
```

### PHP Feasibility

**Not directly available in PHP.** curl_cffi is Python-only. Options:
- Use a Python sidecar process (not available on shared hosting)
- Use a remote signing API that runs curl_cffi
- Accept that PHP cURL works for `pull-f5-*` FLV nodes

---

## Approach 3: Cloudflare Worker Proxy

**Repository:** `farizrifqi/tiktok-live-proxy`
**Language:** TypeScript (Cloudflare Worker)
**Approach:** Edge proxy that relays HLS segments from TikTok CDN

### How It Works

A Cloudflare Worker sits at the CF edge network, fetching TikTok HLS segments and relaying them to clients. Cloudflare's edge IPs have better reputation than shared hosting IPs.

### Implementation

```typescript
// GET /proxy-stream/:encodedUrl/:segment
// :encodedUrl = Base64(url).split('').reverse().join('')

const encodedUrl = Buffer.from('https://example.com/stream/index.m3u8')
    .toString('base64')
    .split('').reverse().join('');
```

### Why It Works

1. **Cloudflare edge IPs** have high reputation with CDNs
2. **TLS fingerprint** is Cloudflare's, not yours
3. **Geographic distribution** — edge node is close to TikTok CDN
4. **No IP blocking** — CF IPs are whitelisted by most CDNs

### M3U8 Rewriting

The worker rewrites `.m3u8` playlist contents to route segment requests through the proxy:

```typescript
// Rewrite segment URLs in HLS playlist
body = body.replace(
    /([^\s]+\.ts[^\s]*)/g,
    (match) => `/proxy-stream/${encodedUrl}/${match}`
);
```

### PHP Alternative

We can replicate this logic in our PHP proxy — but the issue is serv00's network can't reach TikTok CDN. A Cloudflare Worker would solve this.

---

## Approach 4: Local Proxy with Headers

**Repository:** `nickk-kk/mediaflow-proxy` (fork of `janwesthof/mediaflow-proxy`)
**Language:** Python
**Approach:** Local HTTP proxy that adds proper headers to outgoing requests

### How It Works

A local Python HTTP proxy intercepts requests and adds TikTok-required headers before forwarding:

```python
# Proxy adds headers to outgoing requests
headers = {
    "Origin": "https://www.tiktok.com",
    "Referer": "https://www.tiktok.com/",
    "User-Agent": "Mozilla/5.0 ...",
}
```

### Usage with VLC

```bash
# Point VLC through the proxy
vlc --http-proxy=http://localhost:8080 "https://pull-hls-.../.m3u8"
```

### PHP Equivalent (Our proxy.php)

Our `proxy.php` already does this — adds Referer + User-Agent. But serv00 can't reach the CDN.

---

## Approach 5: M3U8 Rewriting Proxy

**Repository:** `Shiho-Patch/DTV` (Rust), `synctv-org/synctv` (Rust)
**Approach:** Server-side proxy that rewrites M3U8 manifests

### How It Works

1. Client requests proxied M3U8 URL
2. Proxy fetches original M3U8 from TikTok CDN
3. Proxy rewrites all segment URLs to route through the proxy
4. Client plays segments through the proxy

### Rust Implementation (DTV)

```rust
fn tiktok_headers(cookie: Option<&str>, kind: TikTokMediaKind) -> HashMap<String, String> {
    let origin = match kind {
        TikTokMediaKind::Video | TikTokMediaKind::Live => "https://www.tiktok.com",
    };
    HashMap::from([
        ("Origin".to_string(), origin.to_string()),
        ("Referer".to_string(), format!("{origin}/")),
        ("User-Agent".to_string(), PROVIDER_USER_AGENT.to_string()),
    ])
}
```

### Our PHP Implementation

`proxy.php` already implements M3U8 rewriting:

```php
// Rewrite .ts segment URLs to route through proxy
$body = preg_replace_callback('/^(?!#)(.+\.ts.*)$/m', function ($m) use ($parsed, $baseUrl, $token) {
    $segment = trim($m[1]);
    if (preg_match('#^https?://#i', $segment)) {
        $fullUrl = $segment;
    } else {
        $fullUrl = $parsed['scheme'] . '://' . $parsed['host'] . $baseUrl . $segment;
    }
    return 'proxy.php?url=' . urlencode($fullUrl) . '&token=' . urlencode($token);
}, $body);
```

**Problem:** The initial M3U8 fetch fails (403) because serv00 can't reach `pull-hls-*` CDN.

---

## Approach 6: CDN URL Append (EulerStream)

**Source:** EulerStream documentation
**Approach:** Append `.cdn-proxy.eulerstream.com` to CDN URLs

### How It Works

```
Original:  https://pull-f5-tt04.tiktokcdn-eu.com/stage/stream.flv?...
Proxied:   https://pull-f5-tt04.tiktokcdn-eu.com.cdn-proxy.eulerstream.com/stage/stream.flv?...
```

EulerStream's CDN proxy adds proper headers and TLS impersonation.

### API Alternative

```python
# EulerStream API for room info
response = requests.get(
    "https://tiktok.eulerstream.com/webcast/room_info",
    params={"uniqueId": username, "giftInfo": "false"},
    headers={"x-api-key": "your-api-key"},
)
```

### PHP Implementation

```php
function eulerProxy(string $cdnUrl): string {
    // Append .cdn-proxy.eulerstream.com after the CDN hostname
    return preg_replace(
        '/(https:\/\/[^/]+)/',
        '$1.cdn-proxy.eulerstream.com',
        $cdnUrl
    );
}
```

**Status:** Requires API key for full access. Free tier may be limited.

---

## Approach 7: yt-dlp curl_cffi Stack

**Source:** yt-dlp nightly releases + community
**Approach:** yt-dlp + curl_cffi + Deno for comprehensive bypass

### Installation

```bash
pipx install --force --pip-args=--pre yt-dlp
pipx inject yt-dlp curl_cffi
```

### Usage

```bash
# Impersonate Chrome for TikTok
yt-dlp --impersonate chrome "https://www.tiktok.com/@user/live"
```

### Key Fix (PR #17452)

yt-dlp's fix for the header-fingerprint block:
1. **Randomizes HTTP headers** per request
2. **Removes impersonation** from webpage requests (then re-adds later)
3. Uses `curl_cffi` for TLS fingerprint impersonation

### Relevance to PHP

Not directly usable in PHP, but confirms that:
- TLS fingerprinting is the primary defense
- Header randomization helps
- Multi-fallback strategies are essential

---

## CDN Node Classification

Based on testing and community reports:

| Node Pattern | Protocol | External Access | Notes |
|-------------|----------|-----------------|-------|
| `pull-f5-*` | FLV | **Works** with headers | Best option for direct access |
| `pull-f5-*` | RTMP | Works | Same as FLV, different protocol |
| `pull-hls-f16-*` | HLS | **Blocked** (403) | Akamai actively blocks |
| `pull-hls-f26-*` | HLS | **Blocked** (403) | Same as f16 |
| `pull-flv-w60-*` | FLV | **404** or TLS error | Regional/expired nodes |
| `pull-flv-w6-*` | FLV | Variable | Some work, some don't |

### SDK Stream Data

The `live_core_sdk_data.pull_data.stream_data` JSON contains multiple quality variants, each with both FLV and HLS URLs. The `main.flv` URL is usually on a `pull-f5-*` node.

### Quality Key Mapping

```
FULL_HD1 → 1080p (flv_pull_url key)
HD1      → 720p  (flv_pull_url key)
SD2      → 480p  (flv_pull_url key)
SD1      → 360p  (flv_pull_url key)
```

SDK data keys: `uhd`, `hd`, `ld`, `ao` (audio only)

---

## Required Headers

### For CDN Access (FLV)

```http
Origin: https://www.tiktok.com
Referer: https://www.tiktok.com/
User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.127 Safari/537.36
```

### For API Access

```http
User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.127 Safari/537.36
Accept: application/json, text/html, */*
Accept-Language: en-US,en;q=0.9
Accept-Encoding: gzip, deflate    ← NO brotli!
Referer: https://www.tiktok.com/
Origin: https://www.tiktok.com
Sec-Ch-Ua: "Not/A)Brand";v="8", "Chromium";v="126"
Sec-Ch-Ua-Mobile: ?0
Sec-Ch-Ua-Platform: "Windows"
Sec-Fetch-Site: same-origin
Sec-Fetch-Mode: cors
Sec-Fetch-Dest: empty
```

### Critical: No Brotli

PHP does not have `brotli_decode()`. If `Accept-Encoding` includes `br`, TikTok returns brotied responses that fail to decode. Always use `gzip, deflate` only.

---

## Implementation for Our Project

### Current State

1. **API works**: `api.php` correctly fetches room info and stream URLs
2. **FLV direct works**: `pull-f5-*` FLV URLs accessible with headers from this server
3. **HLS blocked**: `pull-hls-*` returns 403 from Akamai
4. **Proxy broken**: serv00 can't reach TikTok CDN (network/TLS issue)

### Recommended Fixes

#### Fix 1: Prefer FLV over HLS in UI

Since HLS is blocked and FLV works, the UI should default to FLV:

```php
// In parseStreamUrls(), prioritize FLV
// Already done — FLV is shown first in the card
```

#### Fix 2: Remove Broken Proxy for HLS

The proxy can't reach HLS CDN nodes. Instead:
- Show FLV URLs with "Copy" and "mpv" buttons
- For HLS, show a note explaining it's CDN-blocked
- Optionally try EulerStream CDN proxy for HLS

#### Fix 3: Add FLV Direct Access Option

For users whose local network can access TikTok CDN:

```html
<button onclick="window.open('https://pull-f5-...')">Open FLV Direct</button>
```

#### Fix 4: Test EulerStream CDN Proxy

```php
function eulerProxyUrl(string $url): string {
    return $url . '.cdn-proxy.eulerstream.com';
}
```

#### Fix 5: Add mpv as Primary Player

mpv handles FLV streams better than VLC and has better TLS support:

```bash
mpv --referrer="https://www.tiktok.com/" \
    --user-agent="Mozilla/5.0 ..." \
    "https://pull-f5-.../.flv?..."
```

### Architecture Decision

Given the constraints (shared hosting, PHP only, serv00 network issues):

1. **Accept FLV-only** for now (it works with headers)
2. **Drop proxy.php** for stream relay (can't reach CDN)
3. **Use mpv** as recommended player (handles FLV + TLS)
4. **Monitor** for when TikTok blocks `pull-f5-*` nodes (may require curl_cffi or CF Worker)

---

## References

### Key Repositories

| Repo | Approach | Relevance |
|------|----------|-----------|
| [farizrifqi/tiktok-live-proxy](https://github.com/farizrifqi/tiktok-live-proxy) | Cloudflare Worker | HLS relay via CF edge |
| [Michele0303/TikTok-Live-Recorder](https://github.com/Michele0303/TikTok-Live-Recorder) | curl_cffi Chrome impersonation | TLS fingerprint bypass |
| [Shiho-Patch/DTV](https://github.com/Shiho-Patch/DTV) | Rust proxy + M3U8 rewriting | Server-side HLS proxy |
| [nickk-kk/mediaflow-proxy](https://github.com/nickk-kk/mediaflow-proxy) | Python HTTP proxy | Header injection proxy |
| [janwesthof/mediaflow-proxy](https://github.com/janwesthof/mediaflow-proxy) | Python HTTP proxy | Original mediaflow proxy |
| [karilaa-dev/tt-bot](https://github.com/karilaa-dev/tt-bot) | curl_cffi chrome120 | WAF bypass fix |
| [eulerstream/eulerstream-cdn-proxy](https://github.com/eulerstream/eulerstream-cdn-proxy) | CDN URL append | Proxy via URL suffix |
| [hatienl0i2612/tiktok-crawler](https://github.com/hatienl0i2612/tiktok-crawler) | Go CLI + proxy | Multi-proxy support |
| [synctv-org/synctv](https://github.com/synctv-org/synctv) | Rust M3U8 rewriting | Server-side HLS proxy |

### Community Knowledge

- yt-dlp nightly + curl_cffi is the gold standard for TikTok access
- TLS fingerprinting (JA3 hash) is the primary defense
- Header randomization helps bypass header-fingerprint blocks
- `pull-f5-*` FLV nodes are the most accessible CDN tier
- `pull-hls-*` HLS nodes are actively blocked by Akamai
- Cloudflare Workers bypass most CDN blocks due to edge IP reputation

---

*Document compiled from GitHub research, direct testing, and community findings.*
