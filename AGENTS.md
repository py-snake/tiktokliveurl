# AGENTS.md

## Project Overview

Pure PHP TikTok live stream URL monitor for shared hosting. Users enter TikTok profile names/URLs, check live status, view stream previews, and copy stream URLs (HLS/FLV/RTMP) for VLC/browser playback. PHP only — no Node.js, no Python, no Docker, no exec().

**Deployed:** `https://bossking.serv00.net/tiktokliveurl/`
**Project:** `tiktokliveurl`

---

## Key Tools (MUST USE)

### GitHub MCP
- Use for all GitHub operations: issues, PRs, code search, commits, releases.
- Reference repos for TikTok API research:
  - `farizrifqi/tiktok-live-proxy` — Cloudflare Worker HLS proxy
  - `Michele0303/TikTok-Live-Recorder` — curl_cffi TLS impersonation
  - `Shiho-Patch/DTV`, `synctv-org/synctv` — M3U8 rewriting proxies
  - `eulerstream/eulerstream-cdn-proxy` — CDN URL append
- See `tiktok-cdn-circumvention.md` for the full research on CDN "Access Denied" bypass.

### SonarQube MCP
- Project key: **`tiktokliveurl`**
- Always resolve the project key via `search_my_sonarqube_projects` if unsure.
- Query issues: `search_sonar_issues_in_projects` (projectKeys=`["tiktokliveurl"]`).
- Check quality gate: `get_project_quality_gate_status`.
- Analyze a file: `analyze_code_snippet` (per-file; deprecated but works against this project).
- Change issue status: `change_sonar_issue_status` (accept / falsepositive / reopen).
- Re-scan after changes by running `sonar-scan.sh` (uses `sonar-scanner` with the project key, host `http://127.0.0.1:9000`, and a token).

---

## Project Files

| File | Purpose |
|------|---------|
| `config.php` | Master token + TikTok API settings + proxy + CORS (**gitignored — not committed**) |
| `api.php` | JSON API: `check`, `room_info`. Live detection, stream URL extraction |
| `index.php` | Responsive web UI: login gate, cards, copy/mpv/Play buttons, auto-refresh |
| `proxy.php` | Stream proxy with Referer/User-Agent, HLS segment URL rewriting, CDN host allowlist |
| `tiktok_signer.php` | Pure PHP X-Gnarly signer (**not used** by api.php, kept on disk) |
| `sonar-scan.sh` | Runs the SonarQube scanner against the project |
| `tiktok-api-features.md` | TikTok API research report |
| `php-wrapper-plan.md` | Pure PHP implementation plan |
| `tiktok-cdn-circumvention.md` | Research doc on bypassing Akamai CDN access-denied |

---

## Key Technical Facts

### Critical: Brotli Bug
`httpRequest` must send `Accept-Encoding: gzip, deflate` — **NOT** `br`. PHP has no `brotli_decode()`, so brotli-compressed TikTok responses fail to decode and every request silently returns `null`. The 4003110 "signature" error was actually this brotli bug, not missing signatures.

### Signatures NOT Required
`webcast/room/info/` works WITHOUT X-Gnarly/X-Bogus signatures (minimal params: `aid=1988&room_id={id}`).

### Source Params
- `api-live/user/room` requires `sourceType=54` (else returns 19881005 params_error).
- Use cURL, not `file_get_contents` (fails silently on this server).

### CDN Access Reality
| CDN Node | Status |
|----------|--------|
| `pull-f5-*` FLV | **Works** with `Referer` + `User-Agent` headers |
| `pull-hls-*` HLS | **Blocked** (403 by Akamai) |
| `pull-flv-w60-*` FLV | 404 / TLS errors (regional) |
| **serv00 proxy** | Can't reach TikTok CDN (network/TLS issue) — proxy.php unreliable from shared hosting |

- `pull-f5-*` FLV is the only reliable stream format. mpv > VLC for FLV+TLS.
- VLC needs both `--http-referrer` and `--user-agent`.

---

## SonarQube Known State

- Quality gate: **OK** (41 issues, all non-blocking after the S6418 false-positive).
- `config.php` S6418 (hard-coded token) is **false positive** — file is gitignored.
- `tiktok_signer.php` S4790 (weak hash) is **intentional** — it's the TikTok MD5 X-Gnarly algorithm, not used for security.
- `proxy.php` permissive CORS (`*`) is intentional for cross-origin stream segments.

---

## Workflow

1. **Before** editing, run the current API/UI to confirm behavior.
2. **After** editing a PHP file, `php -l <file>` for syntax check.
3. Re-scan with `sonar-scan.sh` and re-query SonarQube issues.
4. Verify deployment on serv00 after changes.
