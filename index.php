<?php
$config = require_once __DIR__ . '/config.php';
$token = $config['master_token'];

$authed = isset($_GET['token']) && hash_equals($token, (string) $_GET['token']);
if (!$authed && isset($_COOKIE['tt_token'])) {
    $authed = hash_equals($token, (string) $_COOKIE['tt_token']);
}
if ($authed) {
    setcookie('tt_token', $token, [
        'expires' => time() + 86400 * 30,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TikTok Live Monitor</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg-primary: #0f0f0f;
            --bg-secondary: #1a1a1a;
            --bg-card: #222;
            --text-primary: #fff;
            --text-secondary: #aaa;
            --text-muted: #666;
            --accent: #fe2c55;
            --accent-hover: #ff4466;
            --success: #25d366;
            --border: #333;
            --radius: 12px;
            --radius-sm: 8px;
            --shadow: 0 4px 24px rgba(0,0,0,0.4);
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.5;
        }

        /* Login */
        .login-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-box {
            background: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 40px 32px;
            width: 100%;
            max-width: 400px;
            box-shadow: var(--shadow);
            text-align: center;
        }
        .login-box h1 {
            font-size: 1.6rem;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #25f4ee, #fe2c55);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .login-box p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 24px;
        }
        .login-box input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 1rem;
            outline: none;
            margin-bottom: 16px;
            transition: border-color 0.2s;
        }
        .login-box input:focus { border-color: var(--accent); }
        .login-box input::placeholder { color: var(--text-muted); }
        .login-error {
            color: var(--accent);
            font-size: 0.85rem;
            margin-bottom: 12px;
            display: none;
        }

        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        header { text-align: center; padding: 30px 0 20px; }
        header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #25f4ee, #fe2c55);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        header p { color: var(--text-secondary); font-size: 0.9rem; margin-top: 6px; }

        .input-section {
            background: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }
        .input-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .input-row input {
            flex: 1;
            min-width: 200px;
            padding: 14px 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 1rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .input-row input:focus { border-color: var(--accent); }
        .input-row input::placeholder { color: var(--text-muted); }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-secondary { background: var(--border); color: var(--text-primary); }
        .btn-secondary:hover { background: #444; }

        .controls {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .auto-refresh-label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            font-size: 0.9rem;
            cursor: pointer;
            user-select: none;
        }
        .auto-refresh-label input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--accent);
        }

        .status-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 16px;
        }
        @media (max-width: 400px) { .cards-grid { grid-template-columns: 1fr; } }

        .card {
            background: var(--bg-card);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid var(--border);
        }
        .card:hover { transform: translateY(-2px); box-shadow: 0 8px 32px rgba(0,0,0,0.5); }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }
        .card-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--border);
            object-fit: cover;
            flex-shrink: 0;
        }
        .card-user { flex: 1; min-width: 0; }
        .card-username {
            font-weight: 700;
            font-size: 1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .card-nickname {
            color: var(--text-secondary);
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .card-status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            flex-shrink: 0;
        }
        .card-status.live { background: rgba(254, 44, 85, 0.15); color: var(--accent); }
        .card-status.offline { background: rgba(100, 100, 100, 0.15); color: var(--text-muted); }
        .card-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
        }
        .card-status.live .card-status-dot { animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

        .card-body { padding: 16px; }

        .card-preview {
            width: 100%;
            aspect-ratio: 16/9;
            background: var(--bg-primary);
            border-radius: var(--radius-sm);
            margin-bottom: 12px;
            overflow: hidden;
        }
        .card-preview img { width: 100%; height: 100%; object-fit: cover; }

        .card-meta { display: flex; gap: 16px; margin-bottom: 12px; flex-wrap: wrap; }
        .card-meta-item { font-size: 0.85rem; color: var(--text-secondary); }
        .card-meta-item span { color: var(--text-primary); font-weight: 600; }

        .card-title {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 12px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .url-section { margin-top: 12px; }
        .url-group { margin-bottom: 10px; }
        .url-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .url-row { display: flex; gap: 6px; align-items: stretch; }
        .url-input {
            flex: 1;
            padding: 10px 12px;
            background: var(--bg-primary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 0.8rem;
            font-family: 'SF Mono', Monaco, monospace;
            overflow-x: auto;
            white-space: nowrap;
        }
        .btn-copy {
            padding: 10px 14px;
            background: var(--border);
            border: none;
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .btn-copy:hover { background: #444; }
        .btn-copy.copied { background: var(--success); color: #fff; }

        .btn-mpv {
            padding: 10px 14px;
            background: #ff8800;
            border: none;
            border-radius: var(--radius-sm);
            color: #fff;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        .btn-mpv:hover { background: #ffaa33; color: #fff; }
        .btn-mpv.copied { background: var(--success); }

        .btn-play {
            padding: 10px 14px;
            background: var(--border);
            border: none;
            border-radius: var(--radius-sm);
            color: #fff;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        .btn-play.checking { opacity: 0.6; cursor: wait; }
        .btn-play.available { background: var(--success); }
        .btn-play.available:hover { background: #2ed87a; color: #fff; }
        .btn-play.unavailable { background: var(--accent); }
        .btn-play.unavailable:hover { background: var(--accent-hover); color: #fff; }
        .btn-play:disabled { opacity: 0.4; cursor: not-allowed; }

        .player-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.85);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .player-modal.show { display: flex; }
        .player-modal-inner {
            width: 100%;
            max-width: 960px;
            background: var(--bg-card);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        .player-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 16px;
            border-bottom: 1px solid var(--border);
        }
        .player-modal-title { font-size: 0.9rem; color: var(--text-secondary); font-weight: 600; }
        .player-close {
            background: transparent;
            border: none;
            color: var(--text-secondary);
            font-size: 1.4rem;
            line-height: 1;
            cursor: pointer;
            padding: 4px 8px;
        }
        .player-close:hover { color: var(--accent); }
        #player-video {
            display: block;
            width: 100%;
            max-height: 70vh;
            background: #000;
        }
        .player-status {
            padding: 10px 16px;
            font-size: 0.85rem;
            color: var(--text-secondary);
            min-height: 1.2em;
        }

        .vlc-note {
            background: rgba(255, 136, 0, 0.1);
            border: 1px solid rgba(255, 136, 0, 0.3);
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 16px;
            line-height: 1.6;
        }
        .vlc-note code {
            background: var(--bg-primary);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8rem;
            color: #ff8800;
        }
        .vlc-note summary {
            cursor: pointer;
            font-weight: 600;
            color: #ff8800;
            margin-bottom: 8px;
        }

        .btn-open {
            padding: 10px 14px;
            background: var(--accent);
            border: none;
            border-radius: var(--radius-sm);
            color: #fff;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
            white-space: nowrap;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .btn-open:hover { background: var(--accent-hover); }

        .card-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }
        .btn-remove {
            margin-left: auto;
            padding: 8px 12px;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.2s;
        }
        .btn-remove:hover { border-color: var(--accent); color: var(--accent); }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state svg { width: 64px; height: 64px; margin-bottom: 16px; opacity: 0.3; }
        .empty-state h3 { font-size: 1.1rem; margin-bottom: 8px; color: var(--text-secondary); }

        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--bg-card);
            color: var(--text-primary);
            padding: 12px 24px;
            border-radius: var(--radius-sm);
            box-shadow: 0 8px 32px rgba(0,0,0,0.5);
            z-index: 1000;
            transition: transform 0.3s ease;
            border: 1px solid var(--border);
        }
        .toast.show { transform: translateX(-50%) translateY(0); }

        .qualities-list { margin-top: 8px; }
        .quality-item { margin-bottom: 6px; }
        .quality-item .url-row { background: var(--bg-primary); border-radius: var(--radius-sm); padding: 6px; }

        .note-box {
            background: rgba(254, 44, 85, 0.1);
            border: 1px solid rgba(254, 44, 85, 0.3);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }

        footer {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
<?php if (!$authed): ?>
    <div class="login-wrapper">
        <div class="login-box">
            <h1>TikTok Live Monitor</h1>
            <p>Enter your access token to continue</p>
            <div class="login-error" id="login-error">Invalid token. Try again.</div>
            <input type="password" id="token-input" placeholder="Access token" aria-label="Access token" autofocus>
            <button class="btn btn-primary" style="width:100%" onclick="submitToken()">Login</button>
        </div>
    </div>
    <script>
    document.getElementById('token-input').addEventListener('keypress', e => { if (e.key === 'Enter') submitToken(); });
    function submitToken() {
        const v = document.getElementById('token-input').value.trim();
        if (!v) return;
        window.location.href = '?token=' + encodeURIComponent(v);
    }
    </script>
<?php else: ?>
    <div class="container">
        <header>
            <h1>TikTok Live Monitor</h1>
            <p>Check if users are live and get stream URLs</p>
        </header>

        <div class="input-section">
            <div class="input-row">
                <input type="text" id="username-input" placeholder="Enter username or URL (e.g., @tiktok or tiktok.com/@tiktok)" aria-label="Username or URL to check" autocomplete="off">
                <button class="btn btn-primary" id="check-btn" onclick="checkUser()">Check</button>
            </div>
            <div class="controls">
                <label class="auto-refresh-label">
                    <input type="checkbox" id="auto-refresh" onchange="toggleAutoRefresh()">
                    Auto-refresh every 60s
                </label>
                <label class="auto-refresh-label" title="Shows a browser popup and plays a chime when a tracked user comes online">
                    <input type="checkbox" id="notify-toggle" onchange="toggleNotifications()">
                    Browser notifications
                </label>
            </div>
        </div>

        <div class="status-bar">
            <span id="profiles-count">0 profiles tracked</span>
            <span id="live-count">0 live now</span>
            <span id="last-refresh" style="margin-left:auto; font-size:0.8rem; color:var(--text-muted);"></span>
            <button class="btn btn-secondary" onclick="refreshAll()" id="refresh-all-btn" style="padding:6px 14px; font-size:0.8rem;">Refresh Now</button>
        </div>

        <details class="vlc-note">
            <summary>Player not working? Click here</summary>
            <strong>"mpv"</strong> copies a command for mpv with proper headers (most reliable — works even when the buttons below don't).<br>
            <strong>"Play"</strong> opens an in-page player that streams through this server's proxy. It's <span style="color:var(--success); font-weight:700;">green</span> when the source responds OK, <span style="color:var(--accent); font-weight:700;">red</span> when it returns 403/an error, and greyed out while checking.<br>
            <strong>"Copy"</strong> copies the raw CDN URL (for advanced use).<br><br>
            TikTok CDN URLs require <code>Referer</code> and <code>User-Agent</code> headers, which <strong>"mpv"</strong> sets for you directly — no server involved. <strong>"Play"</strong> instead routes through this server's proxy, which TikTok's HLS CDN nodes commonly reject outright (403) regardless of headers; FLV nodes are more permissive but the proxy may still be unable to relay a continuous live stream on some hosts. If a stream shows red or won't play, use the mpv command instead.
        </details>

        <div id="cards-container">
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
                <h3>No profiles tracked yet</h3>
                <p>Enter a username above to start monitoring</p>
            </div>
        </div>

        <footer>TikTok Live Monitor &mdash; Stream URLs refresh automatically</footer>
    </div>

    <div class="toast" id="toast"></div>

    <div class="player-modal" id="player-modal">
        <div class="player-modal-inner">
            <div class="player-modal-header">
                <span class="player-modal-title">Live Player</span>
                <button class="player-close" onclick="closePlayer()" aria-label="Close">&times;</button>
            </div>
            <video id="player-video" controls autoplay playsinline></video>
            <div class="player-status" id="player-status"></div>
        </div>
    </div>

    <script src="vendor/hls.min.js"></script>
    <script src="vendor/flv.min.js"></script>
    <script>
    const API_BASE = 'api.php';
    const TOKEN = <?= json_encode($token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const REFRESH_INTERVAL = 60000;

    let profiles = (() => {
        try {
            const raw = localStorage.getItem('tt_profiles');
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            console.warn('Corrupted localStorage tt_profiles, resetting:', e);
            localStorage.removeItem('tt_profiles');
            return {};
        }
    })();
    let refreshTimer = null;
    let bootRefreshed = false;
    let notifyEnabled = localStorage.getItem('tt_notify') === '1';
    let audioCtx = null;
    let isRefreshing = false;  // guard against refreshAll re-entry

    document.addEventListener('DOMContentLoaded', () => {
        renderCards();
        updateCounts();
        refreshAll().finally(() => { bootRefreshed = true; });
        document.getElementById('username-input').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') checkUser();
        });
        document.getElementById('notify-toggle').checked = notifyEnabled;
        const params = new URLSearchParams(window.location.search);
        const u = params.get('u');
        if (u) {
            document.getElementById('username-input').value = u;
            checkUser();
        }
    });

    // Cross-tab sync: when another tab updates localStorage, reload profiles
    window.addEventListener('storage', (e) => {
        if (e.key === 'tt_profiles') {
            try {
                profiles = e.newValue ? JSON.parse(e.newValue) : {};
            } catch {
                profiles = {};
            }
            renderCards();
            updateCounts();
        }
    });

    function extractUsername(input) {
        input = input.trim();
        const match = input.match(/tiktok\.com\/@([^/?]+)/);
        if (match) return match[1];
        if (input.startsWith('@')) return input.slice(1);
        return input;
    }

    async function apiCall(action, params) {
        const qs = new URLSearchParams({ action, token: TOKEN, ...params });
        const res = await fetch(`${API_BASE}?${qs}`);
        if (res.status === 401) {
            showToast('Session expired. Please re-login.');
            localStorage.removeItem('tt_profiles');
            setTimeout(() => window.location.href = '?', 1500);
            return null;
        }
        return await res.json();
    }

    async function checkUser() {
        const input = document.getElementById('username-input');
        const btn = document.getElementById('check-btn');
        const username = extractUsername(input.value);
        if (!username) { showToast('Please enter a username'); return; }

        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner"></span>';

        try {
            const data = await apiCall('check', { username });
            if (!data || !data.success) {
                showToast(data?.message || 'Error checking user');
                return;
            }

            profiles[username] = {
                username: username,
                is_live: data.data.is_live,
                room_id: data.data.room_id,
                room: data.data.room || null,
                note: data.data.note || null,
                last_checked: Date.now(),
            };

            saveProfiles();
            renderCards();
            updateCounts();
            input.value = '';
            showToast(data.data.is_live ? `${username} is LIVE!` : `${username} is offline`);
        } catch (err) {
            showToast('Network error. Please try again.');
            console.error(err);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Check';
        }
    }

    async function refreshProfile(username) {
        try {
            const data = await apiCall('check', { username });
            if (data && data.success) {
                const wasLive = !!(profiles[username] && profiles[username].is_live);
                profiles[username] = {
                    ...profiles[username],
                    is_live: data.data.is_live,
                    room_id: data.data.room_id,
                    room: data.data.room || null,
                    note: data.data.note || null,
                    last_checked: Date.now(),
                };
                if (data.data.is_live && !wasLive && bootRefreshed) {
                    notifyLive(username, data.data.room || null);
                }
                saveProfiles();
                updateCard(username);  // update only this card instead of full rebuild
                updateCounts();
            }
        } catch (err) {
            console.error(`Failed to refresh ${username}:`, err);
        }
    }

    async function refreshAll() {
        if (isRefreshing) return;  // prevent re-entry
        isRefreshing = true;
        try {
            if (Object.keys(profiles).length === 0) return;
            document.getElementById('refresh-all-btn').disabled = true;
            document.getElementById('last-refresh').textContent = 'Refreshing...';
            const POOL = 1;
            let i = 0;
            const workers = [];
            const lanes = Math.min(POOL, Object.keys(profiles).length);
            for (let lane = 0; lane < lanes; lane++) {
                workers.push((async () => {
                    for (;;) {
                        const u = Object.keys(profiles)[i++];
                        if (u === undefined) break;
                        await refreshProfile(u);
                    }
                })());
            }
            await Promise.all(workers);
            document.getElementById('refresh-all-btn').disabled = false;
            document.getElementById('last-refresh').textContent = `Last: ${new Date().toLocaleTimeString()}`;
        } finally {
            isRefreshing = false;
        }
    }

    function toggleAutoRefresh() {
        const checked = document.getElementById('auto-refresh').checked;
        if (checked) {
            refreshAll();
            refreshTimer = setInterval(refreshAll, REFRESH_INTERVAL);
        } else if (refreshTimer) {
            clearInterval(refreshTimer);
            refreshTimer = null;
        }
    }

    function toggleNotifications() {
        const enabled = document.getElementById('notify-toggle').checked;
        notifyEnabled = enabled;
        localStorage.setItem('tt_notify', enabled ? '1' : '0');
        if (enabled) {
            ensureAudio();
            if (notificationsSupported()) {
                if (Notification.permission === 'default') {
                    Notification.requestPermission().then(perm => {
                        if (perm !== 'granted') {
                            document.getElementById('notify-toggle').checked = false;
                            notifyEnabled = false;
                            localStorage.setItem('tt_notify', '0');
                            showToast('Notifications blocked — you can enable them in your browser settings, sound will still play.');
                        } else {
                            showToast('Browser notifications enabled');
                        }
                    });
                    return; // wait for permission result before showing toast
                } else if (Notification.permission === 'denied') {
                    document.getElementById('notify-toggle').checked = false;
                    notifyEnabled = false;
                    localStorage.setItem('tt_notify', '0');
                    showToast('Notifications blocked — you can enable them in your browser settings, sound will still play.');
                    return;
                }
            }
            showToast('Browser notifications enabled');
        } else {
            showToast('Browser notifications disabled');
        }
    }

    function notificationsSupported() {
        return 'Notification' in window;
    }

    function ensureAudio() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (AC) audioCtx = new AC();
            }
            if (audioCtx && audioCtx.state === 'suspended') {
                audioCtx.resume().catch(() => {});
            }
        } catch (e) {}
    }

    function playChime() {
        if (!audioCtx) ensureAudio();
        if (!audioCtx) return;
        const now = audioCtx.currentTime;
        [[880, 0, 0.18], [1174.7, 0.22, 0.28]].forEach(([freq, offset, dur]) => {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, now + offset);
            gain.gain.exponentialRampToValueAtTime(0.35, now + offset + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + dur);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start(now + offset);
            osc.stop(now + offset + dur + 0.02);
        });
    }

    function notifyLive(username, room) {
        showToast(`@${username} is LIVE!`);
        if (!notifyEnabled) return;
        playChime();
        if (!notificationsSupported() || Notification.permission !== 'granted') return;
        const owner = (room && room.owner) || {};
        const avatar = owner.avatar || null;
        const body = room && room.title
            ? room.title
            : (room ? `${formatNumber(room.viewer_count)} viewers` : 'Live now on TikTok');
        const opts = { body: body, tag: 'tt-live-' + username, icon: avatar, badge: avatar };
        if (!opts.icon) delete opts.icon;
        if (!opts.badge) delete opts.badge;
        try {
            const n = new Notification('@' + username + ' is LIVE!', opts);
            n.onclick = () => {
                window.focus();
                const card = document.querySelector(`[data-username="${CSS.escape(username)}"]`);
                if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                n.close();
            };
        } catch (e) {
            console.error('Notification failed:', e);
        }
    }

    function removeProfile(username) {
        delete profiles[username];
        saveProfiles();
        renderCards();
        updateCounts();
        showToast(`Removed @${username}`);
    }

    function saveProfiles() {
        try {
            localStorage.setItem('tt_profiles', JSON.stringify(profiles));
        } catch (e) {
            if (e.name === 'QuotaExceededError') {
                console.error('localStorage quota exceeded, clearing old data');
                localStorage.removeItem('tt_profiles');
                try {
                    localStorage.setItem('tt_profiles', JSON.stringify(profiles));
                } catch (e2) {
                    console.error('Failed to save profiles even after clearing:', e2);
                    showToast('Storage full — unable to save profiles');
                }
            } else {
                console.error('Failed to save profiles:', e);
                showToast('Failed to save profiles');
            }
        }
    }

    function updateCounts() {
        const total = Object.keys(profiles).length;
        const live = Object.values(profiles).filter(p => p.is_live).length;
        document.getElementById('profiles-count').textContent = `${total} profile${total !== 1 ? 's' : ''} tracked`;
        document.getElementById('live-count').textContent = `${live} live now`;
    }

    function renderCards() {
        const container = document.getElementById('cards-container');
        const usernames = Object.keys(profiles);
        if (usernames.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <h3>No profiles tracked yet</h3>
                    <p>Enter a username above to start monitoring</p>
                </div>`;
            return;
        }
        usernames.sort((a, b) => {
            if (profiles[a].is_live !== profiles[b].is_live) return profiles[b].is_live ? 1 : -1;
            return (profiles[b].last_checked || 0) - (profiles[a].last_checked || 0);
        });
        container.innerHTML = usernames.map(username => renderCard(profiles[username])).join('');
    }

    function renderCard(profile) {
        const { username, is_live, room, note } = profile;
        const owner = room?.owner || {};
        const streamUrls = room?.stream_urls || {};
        const avatarUrl = owner.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(username)}&background=333&color=fff&size=96`;

        let bodyContent = '';

        if (is_live && room) {
            const coverUrl = room.cover || streamUrls.hls;
            if (coverUrl) {
                bodyContent += `<div class="card-preview"><img src="${escHtml(coverUrl)}" alt="Live preview" onerror="this.style.display='none'"></div>`;
            }
            bodyContent += `
                <div class="card-meta">
                    <div class="card-meta-item">Viewers: <span>${formatNumber(room.viewer_count)}</span></div>
                    <div class="card-meta-item">Likes: <span>${formatNumber(room.like_count)}</span></div>
                </div>`;
            if (room.title) bodyContent += `<div class="card-title">${escHtml(room.title)}</div>`;

            bodyContent += '<div class="url-section">';
            if (streamUrls.flv && Object.keys(streamUrls.flv).length > 0) {
                for (const [q, url] of Object.entries(streamUrls.flv)) {
                    if (url) bodyContent += renderUrlGroup(`FLV ${q}`, url);
                }
            }
            if (streamUrls.rtmp) bodyContent += renderUrlGroup('RTMP', streamUrls.rtmp);
            if (streamUrls.hls) {
                bodyContent += renderUrlGroup('HLS (M3U8)', streamUrls.hls,
                    'TikTok often blocks HLS CDN access outright, even proxied. Use "mpv" on an FLV link above if this fails.');
            }
            if (streamUrls.qualities && streamUrls.qualities.length > 0) {
                bodyContent += '<div class="qualities-list"><div class="url-label">Additional Qualities</div>';
                for (const q of streamUrls.qualities) {
                    const url = q.hls || q.flv;
                    if (url) bodyContent += renderUrlGroup(q.gear || q.key, url);
                }
                bodyContent += '</div>';
            }
            bodyContent += '</div>';

            bodyContent += '<div class="card-actions">';
            bodyContent += `<button class="btn-remove" onclick="removeProfile('${escAttr(username)}')">Remove</button></div>`;

        } else if (is_live) {
            bodyContent += `<div class="note-box">&#9888; ${escHtml(note || 'User is live but stream URLs could not be retrieved')}</div>`;
            bodyContent += `
                <div style="text-align:center; padding:10px; color:var(--accent); font-weight:600;">LIVE</div>
                <div class="card-actions">
                    <a class="btn-open" href="https://www.tiktok.com/@${escAttr(username)}/live" target="_blank" rel="noopener">Open TikTok</a>
                    <button class="btn-remove" onclick="removeProfile('${escAttr(username)}')">Remove</button>
                </div>`;
        } else {
            bodyContent = `
                <div style="text-align:center; padding:20px; color:var(--text-muted);">
                    <p>Currently offline</p>
                    <p style="font-size:0.8rem; margin-top:4px;">Last checked: ${formatTime(profile.last_checked)}</p>
                </div>
                <div class="card-actions">
                    <button class="btn-remove" onclick="removeProfile('${escAttr(username)}')">Remove</button>
                </div>`;
        }

        return `
            <div class="card" data-username="${escAttr(username)}">
                <div class="card-header">
                    <img class="card-avatar" src="${escHtml(avatarUrl)}" alt="${escAttr(username)}" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(username)}&background=333&color=fff&size=96'">
                    <div class="card-user">
                        <div class="card-username">@${escHtml(username)}</div>
                        <div class="card-nickname">${escHtml(owner.nickname || username)}</div>
                    </div>
                    <div class="card-status ${is_live ? 'live' : 'offline'}">
                        <span class="card-status-dot"></span>
                        ${is_live ? 'LIVE' : 'OFFLINE'}
                    </div>
                </div>
                <div class="card-body">${bodyContent}</div>
            </div>`;
    }

    function updateCard(username) {
        const container = document.getElementById('cards-container');
        const card = container.querySelector(`[data-username="${escAttr(username)}"]`);
        if (!card) return;
        const profile = profiles[username];
        if (!profile) return;
        card.outerHTML = renderCard(profile);
    }

    const VLC_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    function proxyUrl(url) {
        return `proxy.php?url=${encodeURIComponent(url)}&token=${encodeURIComponent(TOKEN)}`;
    }

    let playBtnSeq = 0;

    function streamKind(url) {
        if (/^rtmp:\/\//i.test(url)) return null; // not playable in a browser
        if (/\.m3u8(\?|$)/i.test(url)) return 'hls';
        return 'flv';
    }

function renderUrlGroup(label, url, warning) {
        const mpvCmd = `mpv --referrer="https://www.tiktok.com/" --user-agent="${VLC_UA}" "${url}"`;
        const warningHtml = warning ? `<div class="note-box" style="margin-top:6px; margin-bottom:0;">&#9888; ${escHtml(warning)}</div>` : '';
        const kind = streamKind(url);
        const btnId = `play-btn-${playBtnSeq++}`;

        let playBtnHtml;
        if (!kind) {
            playBtnHtml = `<button class="btn-play" disabled title="RTMP can't be played in a browser — use the mpv command">Play</button>`;
        } else {
            playBtnHtml = `<button id="${btnId}" class="btn-play checking" onclick="openPlayer('${escAttr(url)}', '${kind}')" title="Checking availability&hellip;">Play</button>`;
            queueAvailCheck(btnId, url);
        }

        return `
            <div class="url-group">
                <div class="url-label">${escHtml(label)}</div>
                <div class="url-row">
                    <input class="url-input" type="text" readonly value="${escAttr(url)}" onclick="this.select()">
                    <button class="btn-mpv" style="background:#555" onclick="copyUrl(this, '${escAttr(mpvCmd)}')" title="Copy mpv command">mpv</button>
                    ${playBtnHtml}
                    <button class="btn-copy" onclick="copyUrl(this, '${escAttr(url)}')">Copy</button>
                </div>
                ${warningHtml}
            </div>`;
    }

    // Availability checks run strictly ONE AT A TIME: serv00 only allows 3
    // concurrent PHP interpreters, so parallel HEAD requests are how every
    // request can end up queued behind a stuck pool. A single in-flight check
    // (plus at most one api.php check and one playing stream) stays inside
    // that budget. Results are cached so re-renders don't re-check the same
    // URLs more often than the TTL.
    const availCache = new Map();
    const AVAIL_TTL = 60000;
    const AVAIL_POOL = 1;
    const availQueue = [];
    let availInFlight = 0;

    function queueAvailCheck(btnId, url) {
        const cached = availCache.get(url);
        if (cached && (Date.now() - cached.at) < AVAIL_TTL) {
            applyAvailResult(btnId, cached.status);
            return;
        }
        availQueue.push({ btnId, url });
        pumpAvailQueue();
    }

    function pumpAvailQueue() {
        while (availInFlight < AVAIL_POOL && availQueue.length > 0) {
            const job = availQueue.shift();
            availInFlight++;
            checkAvailability(job).finally(() => {
                availInFlight--;
                pumpAvailQueue();
            });
        }
    }

    async function checkAvailability({ btnId, url }) {
        let status = null;
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 12000);
        try {
            const res = await fetch(proxyUrl(url), { method: 'HEAD', signal: ctrl.signal });
            status = res.status;
        } catch {
            status = null;
        }
        clearTimeout(timer);
        availCache.set(url, { status, at: Date.now() });
        if (availCache.size > 200) {
            let oldest = null;
            for (const [k, v] of availCache.entries()) {
                if (!oldest || v.at < oldest[1].at) oldest = [k, v];
            }
            if (oldest) availCache.delete(oldest[0]);
        }
        applyAvailResult(btnId, status);
    }

    function applyAvailResult(btnId, status) {
        const btn = document.getElementById(btnId);
        if (!btn) return; // card was re-rendered before the check finished
        btn.classList.remove('checking');
        if (status && status >= 200 && status < 400) {
            btn.classList.add('available');
            btn.title = 'Source is available — click to play';
        } else {
            btn.classList.add('unavailable');
            let reason = status === 403
                ? 'Blocked by the CDN (403 Access Denied)'
                : `Source unavailable (${status || 'timed out / network error'})`;
            btn.title = `${reason} — try the mpv command instead`;
        }
    }

    let __playGen = 0;          // bumped on every open/close; stale retries check this
    let __playRetryTimer = null;
    let __playWatchTimer = null;
    let __playCtx = null;       // { url, kind, attempt, gen } for error handlers
    let __lastT = -1;
    let __lastAdvance = Date.now();
    const PLAYER_MAX_BACKOFF_MS = 15000;
    const PLAYER_STALL_MS = 20000;

    function playerStillOpen(gen) {
        return gen === __playGen
            && document.getElementById('player-modal').classList.contains('show');
    }

    function clearPlayerTimers() {
        if (__playRetryTimer) { clearTimeout(__playRetryTimer); __playRetryTimer = null; }
        if (__playWatchTimer) { clearInterval(__playWatchTimer); __playWatchTimer = null; }
    }

    function schedulePlayerRetry(note) {
        const ctx = __playCtx;
        if (!ctx || !playerStillOpen(ctx.gen)) return;
        const attempt = ctx.attempt + 1;
        const delay = Math.min(2000 * attempt, PLAYER_MAX_BACKOFF_MS);
        clearPlayerTimers();
        const status = document.getElementById('player-status');
        let msg = `Connection lost${note ? ' ' + note : ''} — retrying in ${Math.round(delay / 1000)}s (attempt ${attempt})…`;
        if (attempt >= 5) msg += ' If this persists the signed URL may have expired — close and Refresh.';
        if (status) status.textContent = msg;
        __playCtx = { url: ctx.url, kind: ctx.kind, attempt, gen: ctx.gen };
        __playRetryTimer = setTimeout(() => {
            __playRetryTimer = null;
            if (!playerStillOpen(ctx.gen)) return;
            startPlayback(ctx.url, ctx.kind, attempt, ctx.gen);
        }, delay);
    }

    function armPlaybackWatchdog() {
        const ctx = __playCtx;
        if (!ctx) return;
        if (__playWatchTimer) { clearInterval(__playWatchTimer); __playWatchTimer = null; }
        __lastT = -1;
        __lastAdvance = Date.now();
        const video = document.getElementById('player-video');
        __playWatchTimer = setInterval(() => {
            const c = __playCtx;
            if (!c || !playerStillOpen(c.gen) || !video) {
                clearInterval(__playWatchTimer);
                __playWatchTimer = null;
                return;
            }
            if (video.paused || video.ended) { __lastAdvance = Date.now(); return; }
            const t = video.currentTime;
            if (t !== __lastT) { __lastT = t; __lastAdvance = Date.now(); return; }
            if (Date.now() - __lastAdvance > PLAYER_STALL_MS) {
                schedulePlayerRetry('(stalled)');
            }
        }, 5000);
    }

    function destroyPlayers() {
        const video = document.getElementById('player-video');
        if (window.__hlsInstance) { try { window.__hlsInstance.destroy(); } catch { /* noop */ } window.__hlsInstance = null; }
        if (window.__flvInstance) { try { window.__flvInstance.destroy(); } catch { /* noop */ } window.__flvInstance = null; }
        if (video) {
            video.pause();
            video.removeAttribute('src');
            video.load();
            video.removeEventListener('error', onNativeHlsError);
        }
    }

    // Error handler for Safari native HLS
    function onNativeHlsError() {
        schedulePlayerRetry();
    }

    function openPlayer(url, kind) {
        const modal = document.getElementById('player-modal');
        const status = document.getElementById('player-status');
        __playGen += 1;
        clearPlayerTimers();
        destroyPlayers();
        status.textContent = 'Loading…';
        modal.classList.add('show');
        startPlayback(url, kind, 1, __playGen);
    }

    function startPlayback(url, kind, attempt, gen) {
        if (!playerStillOpen(gen)) return;
        const video = document.getElementById('player-video');
        const status = document.getElementById('player-status');
        destroyPlayers();
        __playCtx = { url, kind, attempt, gen };
        if (attempt > 1 && status) status.textContent = `Reconnecting (attempt ${attempt})…`;

        const src = proxyUrl(url);

        if (kind === 'hls') {
            if (window.Hls && Hls.isSupported()) {
                const hls = new Hls();
                window.__hlsInstance = hls;
                hls.on(Hls.Events.MANIFEST_PARSED, () => {
                    if (!playerStillOpen(gen)) return;
                    status.textContent = '';
                    __lastAdvance = Date.now();
                    video.play().catch(() => {
                        status.textContent = 'Click the video to start playback';
                    });
                });
                hls.on(Hls.Events.ERROR, (event, data) => {
                    if (!data.fatal || !playerStillOpen(gen)) return;
                    try { hls.destroy(); } catch { /* noop */ }
                    window.__hlsInstance = null;
                    schedulePlayerRetry();
                });
                hls.loadSource(src);
                hls.attachMedia(video);
                armPlaybackWatchdog();
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = src;
                video.addEventListener('loadedmetadata', () => {
                    if (!playerStillOpen(gen)) return;
                    status.textContent = '';
                    __lastAdvance = Date.now();
                    video.play().catch(() => {
                        status.textContent = 'Click the video to start playback';
                    });
                }, { once: true });
                video.addEventListener('error', onNativeHlsError);
                armPlaybackWatchdog();
            } else {
                status.textContent = 'HLS playback is not supported in this browser.';
            }
        } else if (kind === 'flv') {
            if (window.flvjs && flvjs.isSupported()) {
                const flvPlayer = flvjs.createPlayer({ type: 'flv', url: src, isLive: true });
                window.__flvInstance = flvPlayer;
                flvPlayer.attachMediaElement(video);
                flvPlayer.on(flvjs.Events.ERROR, (errType, errDetail) => {
                    if (!playerStillOpen(gen)) return;
                    try { flvPlayer.destroy(); } catch { /* noop */ }
                    window.__flvInstance = null;
                    schedulePlayerRetry(`${errType} ${errDetail || ''}`.trim());
                });
                flvPlayer.load();
                flvPlayer.play().catch(() => {
                    if (playerStillOpen(gen)) status.textContent = 'Click the video to start playback';
                });
                status.textContent = '';
                __lastAdvance = Date.now();
                armPlaybackWatchdog();
            } else {
                status.textContent = 'FLV playback is not supported in this browser.';
            }
        }
    }

    function closePlayer() {
        __playGen += 1;
        __playCtx = null;
        clearPlayerTimers();
        document.getElementById('player-modal').classList.remove('show');
        destroyPlayers();
    }

    document.getElementById('player-modal').addEventListener('click', (e) => {
        if (e.target.id === 'player-modal') closePlayer();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closePlayer();
    });

    async function copyUrl(btn, url) {
        try {
            await navigator.clipboard.writeText(url);
            btn.classList.add('copied'); btn.textContent = 'Copied!';
            setTimeout(() => { btn.classList.remove('copied'); btn.textContent = 'Copy'; }, 1500);
        } catch {
            const ta = document.createElement('textarea');
            ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select(); document.execCommand('copy');
            document.body.removeChild(ta);
            btn.classList.add('copied'); btn.textContent = 'Copied!';
            setTimeout(() => { btn.classList.remove('copied'); btn.textContent = 'Copy'; }, 1500);
        }
    }

    function formatNumber(n) {
        if (!n) return '0';
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
        return n.toString();
    }

    function formatTime(ts) {
        if (!ts) return 'never';
        return new Date(ts).toLocaleTimeString();
    }

    function showToast(msg) {
        const toast = document.getElementById('toast');
        toast.textContent = msg;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 2500);
    }

    function escHtml(s) {
        if (!s) return '';
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(s) {
        if (!s) return '';
        return s.replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;');
    }
    </script>
<?php endif; ?>
</body>
</html>
