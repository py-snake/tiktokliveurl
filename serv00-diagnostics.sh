# ============================================================
# serv00 -> TikTok CDN diagnostics
# Paste the whole file into your serv00 SSH shell, one step at a time.
# ============================================================

# --- 1. DNS -------------------------------------------------
echo '== 1. DNS =='
getent hosts pull-c5-tt04.tiktokcdn-eu.com webcast.tiktok.com

# --- 2. TCP/TLS connect timing: CDN vs working API host ------
echo
echo '== 2. TCP/TLS connect timing =='
for h in webcast.tiktok.com pull-c5-tt04.tiktokcdn-eu.com; do
  curl -sS -o /dev/null -m 8 -w "$h connect=%{time_connect}s tls=%{time_appconnect}s\n" \
    https://$h/ -H 'User-Agent: x' -H 'Referer: https://www.tiktok.com/'
done

# --- 3. IPv4 vs IPv6 -----------------------------------------
echo
echo '== 3. IPv4 vs IPv6 =='
curl -4 -sS -o /dev/null -m 8 -w "v4 connect=%{time_connect}s\n" \
  https://pull-c5-tt04.tiktokcdn-eu.com/ \
  -H 'User-Agent: x' -H 'Referer: https://www.tiktok.com/'
curl -6 -sS -o /dev/null -m 8 -w "v6 result=%{http_code}\n" \
  https://pull-c5-tt04.tiktokcdn-eu.com/ \
  -H 'User-Agent: x' -H 'Referer: https://www.tiktok.com/' \
  || echo "v6: unreachable"

# --- 4. Real stream: bytes arriving from serv00 ---------------
echo
echo '== 4. Stream throughput (10s) =='
curl -sS -o /dev/null -m 10 -w "http=%{http_code} size=%{size_download}B time=%{time_total}s\n" \
  'https://pull-c5-tt04.tiktokcdn-eu.com/stage/stream-1272931362358429269.flv?expire=1789500077&sign=677eeeafaf8fd80fe0ca895a8f26b0c1&only_audio=1' \
  -H 'User-Agent: Mozilla/5.0' -H 'Referer: https://www.tiktok.com/'

echo
echo '== done =='

# --- 5. PHP-curl vs shell-curl: IPv6 preference --------------
echo
echo '== 5. PHP-curl IPv4 vs IPv6 (the real test) =='
cat > /tmp/ptest.php <<'PHP'
<?php
$url = 'https://pull-c5-tt04.tiktokcdn-eu.com/';
$ua  = ['User-Agent: Mozilla/5.0', 'Referer: https://www.tiktok.com/'];
$modes = [0 => 'default', CURL_IPRESOLVE_V4 => 'ipv4', CURL_IPRESOLVE_V6 => 'ipv6'];
foreach ($modes as $ir => $label) {
    $c = curl_init();
    $o = [
        CURLOPT_URL => $url,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $ua,
    ];
    if ($ir) $o[CURLOPT_IPRESOLVE] = $ir;
    curl_setopt_array($c, $o);
    $r = curl_exec($c);
    printf("%-7s code=%d err=%s bytes=%d\n",
        $label, curl_getinfo($c, CURLINFO_HTTP_CODE), curl_error($c), strlen((string) $r));
    curl_close($c);
}
PHP
php /tmp/ptest.php

# --- 6. WEB-runtime PHP (admits first ... served via HTTPS) --
echo
echo '== 6. PHP inside the web runtime (served via HTTPS) =='
TT_DIR=$(find ~/domains -type d -name tiktokliveurl 2>/dev/null | head -1)
if [ -z "$TT_DIR" ]; then
  echo "tiktokliveurl dir not found under ~/domains — locate it and rerun section 6"
else
  echo "app dir: $TT_DIR"
  cat > "$TT_DIR/_ptest.php" <<'PHP'
<?php
header('Content-Type: text/plain');
$ua = ['User-Agent: Mozilla/5.0', 'Referer: https://www.tiktok.com/'];
$targets = [
    'api (webcast)' => 'https://webcast.tiktok.com/webcast/room/info/?aid=1988&room_id=1',
    'cdn (stream)'  => 'https://pull-c5-tt04.tiktokcdn-eu.com/stage/stream-1272931362358429269.flv?expire=1789500077&sign=677eeeafaf8fd80fe0ca895a8f26b0c1&only_audio=1',
];
foreach ($targets as $label => $url) {
    $c = curl_init();
    curl_setopt_array($c, [
        CURLOPT_URL => $url,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $ua,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) {
            static $n = 0;
            $n += strlen($data);
            if ($n > 65536) return 0;
            return strlen($data);
        },
    ]);
    $r = curl_exec($c);
    printf("%-16s code=%d err=%s bytes=%d\n",
        $label, curl_getinfo($c, CURLINFO_HTTP_CODE), curl_error($c), strlen((string) $r));
    curl_close($c);
}
echo "done\n";
PHP
  curl -sS -m 15 "https://bossking.serv00.net/tiktokliveurl/_ptest.php"
  rm -f "$TT_DIR/_ptest.php"
fi