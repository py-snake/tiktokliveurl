<?php
$config = require_once __DIR__ . '/config.php';
$token = $config['master_token'];
$authed = isset($_GET['token']) && hash_equals($token, (string)$_GET['token']);
if (!$authed && isset($_COOKIE['tt_token'])) $authed = hash_equals($token, (string)$_COOKIE['tt_token']);
if ($authed) {
    setcookie('tt_token', $token, ['expires'=>time()+86400*30,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
}
if (!$authed) { http_response_code(401); header('Content-Type: text/html; charset=utf-8'); echo '<h1>401 Unauthorized</h1><p><a href="index.php">Login</a></p>'; exit; }
$username = trim($_GET['username'] ?? $_GET['u'] ?? '');
$roomId = trim($_GET['room_id'] ?? '');
$urlParam = trim($_GET['url'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Watch — TikTok Live</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0f0f0f;--bg2:#1a1a1a;--card:#222;--text:#fff;--muted:#aaa;--dim:#666;--accent:#fe2c55;--success:#25d366;--border:#333;--r:12px;--rs:8px}
body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{display:flex;align-items:center;gap:12px;padding:10px 16px;background:var(--bg2);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:10}
.topbar a{color:var(--muted);text-decoration:none;font-size:0.9rem}
.topbar a:hover{color:var(--text)}
.topbar h1{font-size:1rem;font-weight:700;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.topbar .badge{font-size:0.75rem;padding:3px 8px;border-radius:999px;background:rgba(254,44,85,.15);color:var(--accent);display:none}
.topbar .badge.live{display:inline-flex;align-items:center;gap:6px}
.dot{width:8px;height:8px;border-radius:50%;background:currentColor;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.layout{display:flex;height:calc(100vh - 48px)}
.video-pane{flex:1 1 68%;background:#000;display:flex;flex-direction:column;min-width:0}
#watch-video{width:100%;flex:1;max-height:100%;background:#000;display:block}
.video-bar{padding:8px 12px;background:var(--card);border-top:1px solid var(--border);display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:0.85rem;color:var(--muted)}
.video-bar .title{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
.btn{padding:8px 14px;border:none;border-radius:var(--rs);font-weight:600;cursor:pointer;font-size:0.85rem}
.btn-primary{background:var(--accent);color:#fff}
.btn-secondary{background:var(--border);color:var(--text)}
.chat-pane{flex:0 0 360px;max-width:40%;background:var(--card);border-left:1px solid var(--border);display:flex;flex-direction:column;min-width:280px}
@media(max-width:900px){.layout{flex-direction:column;height:auto}.video-pane{flex:none;height:48vh}.chat-pane{flex:none;max-width:none;height:52vh}}
.chat-head{padding:10px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.chat-head h2{font-size:0.9rem;flex:1}
.chat-status{font-size:0.75rem;color:var(--dim)}
.chat-status.live{color:var(--success)}
.chat-status.err{color:var(--accent)}
#watch-chat-feed{flex:1;overflow-y:auto;padding:10px 12px;font-size:0.85rem;line-height:1.4}
#watch-chat-feed:empty::before{content:"No messages yet — connecting…";color:var(--dim);font-size:0.8rem}
.chat-msg{padding:4px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.chat-msg:last-child{border:none}
.chat-user{font-weight:700;color:#25f4ee}
.chat-text{color:var(--text);word-break:break-word}
.chat-gift{color:#ffb86b}
.chat-like{color:#ff7ab6}
.chat-actions{padding:8px 12px;border-top:1px solid var(--border);display:flex;gap:8px}
#watch-status{padding:8px 12px;font-size:0.8rem;color:var(--muted);min-height:1.2em;border-top:1px solid var(--border)}
.note{padding:8px 12px;font-size:0.75rem;color:var(--muted);border-top:1px solid var(--border)}
.note code{background:var(--bg);padding:1px 4px;border-radius:4px}
</style>
</head>
<body>
<div class="topbar">
  <a href="index.php?token=<?=htmlspecialchars($_GET['token']??'',ENT_QUOTES)?>">← Back</a>
  <h1 id="watch-title">Loading…</h1>
  <span class="badge" id="watch-badge"><span class="dot"></span> LIVE</span>
  <span id="watch-viewers" style="font-size:0.8rem;color:var(--muted)"></span>
</div>
<div class="layout">
  <div class="video-pane">
    <video id="watch-video" controls autoplay playsinline></video>
    <div class="video-bar">
      <span class="title" id="watch-video-title"></span>
      <button class="btn btn-secondary" onclick="watchCopyMpv()">Copy mpv</button>
      <button class="btn btn-secondary" onclick="watchPopOut()">Pop-out</button>
    </div>
    <div id="watch-status"></div>
    <div class="note">Video via <code>proxy.php</code> (<code>pull-f5-*</code> FLV is most reliable; HLS often <code>403</code>). If black, use mpv: <code id="watch-mpv-cmd" style="word-break:break-all"></code></div>
  </div>
  <div class="chat-pane">
    <div class="chat-head"><h2>Live Chat</h2><span class="chat-status" id="watch-chat-status">—</span></div>
    <div id="watch-chat-feed"></div>
    <div class="chat-actions">
      <button class="btn btn-secondary" id="watch-chat-btn" onclick="watchToggleChat()">Connect</button>
      <button class="btn btn-secondary" onclick="watchClearChat()">Clear</button>
    </div>
  </div>
</div>
<script src="vendor/hls.min.js"></script>
<script src="vendor/flv.min.js"></script>
<script>
const TOKEN=<?=json_encode($token,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;
const USERNAME=<?=json_encode($username,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;
const ROOM_ID=<?=json_encode($roomId,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;
const URL_PARAM=<?=json_encode($urlParam,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;
const API_BASE='api.php';
const VLC_UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
let watchRoom=null, watchEs=null, watchMpv='';
function escH(s){return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
function proxyUrl(u){return `proxy.php?url=${encodeURIComponent(u)}&token=${encodeURIComponent(TOKEN)}`}
async function apiCall(action, params){
  const qs=new URLSearchParams({action,token:TOKEN,...params});
  const r=await fetch(`${API_BASE}?${qs}`);
  if(r.status===401){ alert('Session expired'); location.href='index.php'; return null; }
  return r.json();
}
function fmt(n){ if(!n) return '0'; if(n>=1e6) return (n/1e6).toFixed(1)+'M'; if(n>=1e3) return (n/1e3).toFixed(1)+'K'; return String(n); }
let hbTimer=null;
function watchClearChat(){ document.getElementById('watch-chat-feed').innerHTML=''; }
function watchCopyMpv(){ if(!watchMpv) return; navigator.clipboard.writeText(watchMpv).then(()=>{const b=document.querySelector('[onclick="watchCopyMpv()"]'); const t=b.textContent; b.textContent='Copied!'; setTimeout(()=>b.textContent=t,1200)}).catch(()=>{prompt('Copy mpv:',watchMpv)}) }
function watchPopOut(){ const v=document.getElementById('watch-video'); if(v) v.requestFullscreen?.(); }
function watchSetStatus(t,cls){ const e=document.getElementById('watch-chat-status'); if(e){ e.textContent=t; e.className='chat-status '+(cls||''); } }
function watchAppend(html){ const f=document.getElementById('watch-chat-feed'); const d=document.createElement('div'); d.innerHTML=html; const el=d.firstElementChild||d; f.appendChild(el); while(f.children.length>400) f.removeChild(f.firstChild); f.scrollTop=f.scrollHeight; }
function watchToggleChat(){
  if(watchEs && watchEs.readyState!==EventSource.CLOSED){ try{watchEs.close()}catch{}; watchEs=null; watchSetStatus('— idle',''); document.getElementById('watch-chat-btn').textContent='Connect'; return; }
  watchConnectChat();
}
function watchConnectChat(){
  const u=USERNAME||watchRoom?.owner?.display_id||'';
  if(!u && !ROOM_ID){ watchSetStatus('no user','err'); return; }
  watchSetStatus('connecting…',''); document.getElementById('watch-chat-btn').textContent='Connecting…';
  const esUrl=`chat_sse.php?${u?`username=${encodeURIComponent(u)}`:`room_id=${encodeURIComponent(ROOM_ID)}`}&token=${encodeURIComponent(TOKEN)}`;
  const es=new EventSource(esUrl);
  watchEs=es;
  es.onopen=()=>{ watchSetStatus('live ✓','live'); document.getElementById('watch-chat-btn').textContent='Disconnect'; watchAppend(`<div class="chat-msg" style="color:var(--muted);font-size:0.75rem">Connected — streaming…</div>`); };
  es.onmessage=(ev)=>{
    try{
      const d=JSON.parse(ev.data);
      if(d.type==='chat') watchAppend(`<div class="chat-msg"><span class="chat-user">${escH(d.user?.uniqueId||d.user?.nickname||'?')}</span>: <span class="chat-text">${escH(d.comment)}</span></div>`);
      else if(d.type==='gift') watchAppend(`<div class="chat-msg chat-gift">🎁 ${escH(d.user?.uniqueId||'?')} gift ${d.giftId} ×${d.repeatCount||1}</div>`);
      else if(d.type==='like') watchAppend(`<div class="chat-msg chat-like">❤️ ${escH(d.user?.uniqueId||'?')} +${d.likeCount||1}</div>`);
      else if(d.type==='connected') watchSetStatus('live ✓','live');
      else if(d.type==='status') watchSetStatus(d.message,'');
      else if(d.type==='error'){ watchSetStatus('error: '+(d.message||''),'err'); watchAppend(`<div class="chat-msg" style="color:var(--accent);font-size:0.75rem">${escH(d.message||'error')}</div>`); }
    }catch(e){ console.warn(e) }
  };
  es.onerror=()=>{
    watchSetStatus('reconnecting…','err');
    if(es.readyState===EventSource.CLOSED){ watchEs=null; document.getElementById('watch-chat-btn').textContent='Connect'; }
  };
}
async function watchInit(){
  let username=USERNAME, roomId=ROOM_ID, streamUrl=URL_PARAM;
  document.getElementById('watch-title').textContent=username?`@${username}`:(roomId||'Live');
  if(streamUrl){
    watchPlay(streamUrl);
    if(username) watchConnectChat();
    return;
  }
  if(!username && !roomId){ document.getElementById('watch-title').textContent='No user'; return; }
  // fetch via api
  const q= username?{username}: {room_id:roomId};
  // try check first to get stream urls and live info
  const data=await apiCall('check', q);
  if(!data||!data.success){ document.getElementById('watch-title').textContent='Error: '+(data?.message||'fetch failed'); return; }
  const d=data.data;
  if(!d.is_live){ document.getElementById('watch-title').textContent=`@${d.username} is offline`; document.getElementById('watch-badge').style.display='none'; return; }
  watchRoom=d.room;
  document.getElementById('watch-title').textContent=d.room?.title||`@${d.username} — LIVE`;
  document.getElementById('watch-video-title').textContent=d.room?.title||'';
  document.getElementById('watch-viewers').textContent=d.room?`${fmt(d.room.viewer_count)} viewers • ${fmt(d.room.like_count)} likes`:'';
  document.getElementById('watch-badge').classList.add('live'); document.getElementById('watch-badge').style.display='inline-flex';
  const urls=d.room?.stream_urls||{};
  // pick best: FLV 720p > FLV 480p > HLS > any
  let pick=null, kind='flv';
  if(urls.flv){
    pick=urls.flv['720p']||urls.flv['480p']||urls.flv['360p']||Object.values(urls.flv)[0];
  }
  if(!pick && urls.hls){ pick=urls.hls; kind='hls'; }
  if(!pick && urls.qualities && urls.qualities.length){
    const q0=urls.qualities[0]; pick=q0.hls||q0.flv; kind = q0.hls && q0.hls.includes('.m3u8') ? 'hls':'flv';
  }
  if(!pick){ document.getElementById('watch-status').textContent='No stream URL available (TikTok returned empty). Try mpv or refresh.'; return; }
  watchMpv=`mpv --referrer="https://www.tiktok.com/" --user-agent="${VLC_UA}" "${pick}"`;
  document.getElementById('watch-mpv-cmd').textContent=watchMpv;
  watchPlay(pick, kind);
  // auto-connect chat
  watchConnectChat();
}
function watchPlay(url, kind){
  if(!kind) kind = /\.m3u8(\?|$)/i.test(url) ? 'hls':'flv';
  const video=document.getElementById('watch-video');
  const status=document.getElementById('watch-status');
  if(window.__hls) try{window.__hls.destroy()}catch{}
  if(window.__flv) try{window.__flv.destroy()}catch{}
  video.pause(); video.removeAttribute('src'); video.load();
  status.textContent='Loading…';
  const src=proxyUrl(url);
  if(kind==='hls'){
    if(window.Hls && Hls.isSupported()){
      const hls=new Hls(); window.__hls=hls;
      hls.on(Hls.Events.MANIFEST_PARSED,()=>{ status.textContent=''; video.play().catch(()=> status.textContent='Click video to play'); });
      hls.on(Hls.Events.ERROR,(e,d)=>{ if(d.fatal) status.textContent=`Playback error: ${d.details||d.type}`; });
      hls.loadSource(src); hls.attachMedia(video);
    } else if(video.canPlayType('application/vnd.apple.mpegurl')){
      video.src=src; video.addEventListener('loadedmetadata',()=>{status.textContent=''; video.play().catch(()=>{})},{once:true});
    } else status.textContent='HLS not supported';
  } else {
    if(window.flvjs && flvjs.isSupported()){
      const p=flvjs.createPlayer({type:'flv',url:src,isLive:true}); window.__flv=p;
      p.attachMediaElement(video); p.on(flvjs.Events.ERROR,(t,d)=> status.textContent=`FLV error: ${t} ${d||''}`);
      p.load(); p.play().catch(()=> status.textContent='Click video to play'); status.textContent='';
    } else status.textContent='FLV not supported';
  }
  watchMpv=`mpv --referrer="https://www.tiktok.com/" --user-agent="${VLC_UA}" "${url}"`;
  document.getElementById('watch-mpv-cmd').textContent=watchMpv;
}
document.addEventListener('DOMContentLoaded', watchInit);
</script>
</body>
</html>
