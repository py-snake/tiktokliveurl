<?php
/**
 * chat_sse.php — pure TikTok live chat via SSE (no 3rd party)
 * Browser: new EventSource('chat_sse.php?username=xxx&token=TOKEN')
 * Server: fetches ttwid via plain curl, builds wss://webcast-ws.tiktok.com/...
 *         with ttwid Cookie, proxies WebSocket frames as SSE data: {type, user, comment}
 * Works on serv00 Linux (plain curl slips past JA3 filter; no curl_cffi needed).
 */

set_time_limit(0);
ini_set('display_errors', '0');

$config = @include __DIR__ . '/config.php';
if (!is_array($config)) $config = [];
$masterToken = $config['master_token'] ?? '';

$token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);
if ($masterToken !== '' && $masterToken !== 'CHANGE_ME_TO_A_RANDOM_SECRET') {
    if (!hash_equals((string)$masterToken, (string)$token)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>'Unauthorized']);
        exit;
    }
}

// SSE headers
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
@ini_set('zlib.output_compression','0');
@ini_set('output_buffering','off');
while (ob_get_level()) @ob_end_flush();
if (function_exists('fastcgi_finish_request')) {
    // not finishing, just ensuring buffers flushed
}

require_once __DIR__ . '/tiktok_codec.php';

function sse_send(array $data): void {
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush(); @flush();
    if (connection_aborted()) exit;
}
function sse_comment(string $msg): void {
    echo ': ' . $msg . "\n\n";
    @ob_flush(); @flush();
}

function extractUsernameSse(string $input): string {
    if (preg_match('#tiktok\.com/@([^/?]+)#', $input, $m)) return $m[1];
    if (str_starts_with($input, '@')) return substr($input,1);
    return $input;
}

// minimal http helpers copied from api.php (avoid cross-include)
function sseHttpRequest(array $cfg, string $url, array $extraHeaders=[]): ?array {
    $ch=curl_init();
    $headers=[
        'User-Agent: ' . ($cfg['tiktok']['user_agent'] ?? 'Mozilla/5.0'),
        'Accept: text/html,application/json, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate',
        'Referer: https://www.tiktok.com/',
        'Origin: https://www.tiktok.com',
    ];
    foreach($extraHeaders as $h) $headers[]=$h;
    $respHeaders=[];
    curl_setopt_array($ch,[
        CURLOPT_URL=>$url,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HEADER=>false,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>3,
        CURLOPT_TIMEOUT=>$cfg['tiktok']['timeout']??10,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_SSL_VERIFYHOST=>false,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_HEADERFUNCTION=>function($ch,$hdr) use (&$respHeaders){ $l=strlen($hdr); $p=explode(':',$hdr,2); if(count($p)==2){$k=strtolower(trim($p[0])); $v=trim($p[1]); $respHeaders[$k][]=$v;} return $l; },
    ]);
    if(!empty($cfg['proxy'])) curl_setopt($ch,CURLOPT_PROXY,$cfg['proxy']);
    $body=curl_exec($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($body===false || $code>=400) return null;
    // handle gzip magic if body is raw gzip (curl already decodes if Accept-Encoding sent? but we keep)
    if(substr($body,0,3)==="\x1f\x8b\x08"){ $d=@gzdecode($body); if($d!==false) $body=$d; }
    return ['body'=>$body,'headers'=>$respHeaders,'code'=>$code];
}

function getRoomIdSse(array $cfg,string $username): ?string {
    // api-live/user/room?sourceType=54
    $params=http_build_query(['aid'=>'1988','app_name'=>'tiktok_web','device_platform'=>'web_pc','app_language'=>'en','browser_language'=>'en-US','region'=>'US','user_is_login'=>'false','sourceType'=>'54','staleTime'=>'600000','uniqueId'=>$username]);
    $url=$cfg['tiktok']['web_url']."/api-live/user/room?$params";
    $r=sseHttpRequest($cfg,$url);
    if(!$r) return null;
    $j=json_decode($r['body'],true);
    if(!$j || ($j['statusCode']??-1)!==0) return null;
    $rid=$j['data']['user']['roomId']??null;
    if(!$rid||$rid==='0') return null;
    return (string)$rid;
}
function isRoomAliveSse(array $cfg,string $roomId): bool {
    $params=http_build_query(['aid'=>'1988','room_ids'=>$roomId]);
    $url=$cfg['tiktok']['webcast_url']."/webcast/room/check_alive/?$params";
    $r=sseHttpRequest($cfg,$url);
    if(!$r) return false;
    $j=json_decode($r['body'],true);
    if(!$j||!isset($j['data'][0])) return false;
    return ($j['data'][0]['alive']??false)===true;
}
function fetchTtwidSse(array $cfg,string $username): ?string {
    $cacheFile=sys_get_temp_dir().'/ttwid_'.md5($username).'.json';
    if(is_file($cacheFile)){
        $c=json_decode(@file_get_contents($cacheFile),true);
        if($c && isset($c['ttwid']) && isset($c['exp']) && $c['exp']>time()) return $c['ttwid'];
    }
    $candidates=[];
    if($username) $candidates[]="https://www.tiktok.com/@".rawurlencode($username);
    $candidates[]="https://www.tiktok.com/@tiktok";
    foreach($candidates as $url){
        $r=sseHttpRequest($cfg,$url);
        if(!$r) continue;
        $hdr=$r['headers'];
        $cookies=$hdr['set-cookie']??[];
        foreach($cookies as $ck){
            if(preg_match('/ttwid\s*=\s*([^;]+)/i',$ck,$m)){
                $ttwid=trim($m[1]);
                // cache 12h
                @file_put_contents($cacheFile, json_encode(['ttwid'=>$ttwid,'exp'=>time()+43200]));
                return $ttwid;
            }
        }
        // also try parsing raw header case
        if(isset($hdr['set-cookie'])){}
    }
    return null;
}
function buildWssUrl(string $cdnHost,string $roomId,string $lang='en',string $region='US',bool $compress=false): string {
    $tz='UTC';
    try{ $tz=@date_default_timezone_get() ?: 'UTC'; }catch(Throwable $e){}
    // mimic Piratetok url.py system_timezone
    $browserLang="$lang-$region";
    $params=[
        'version_code'=>'180800','device_platform'=>'web','cookie_enabled'=>'true','screen_width'=>'1920','screen_height'=>'1080',
        'browser_language'=>$browserLang,'browser_platform'=>'Linux x86_64','browser_name'=>'Mozilla','browser_version'=>'5.0 (X11)',
        'browser_online'=>'true','tz_name'=>$tz,'app_name'=>'tiktok_web','sup_ws_ds_opt'=>'1','update_version_code'=>'2.0.0',
        'compress'=> $compress?'gzip':'','webcast_language'=>$lang,'ws_direct'=>'1','aid'=>'1988','live_id'=>'12','app_language'=>$lang,
        'client_enter'=>'1','room_id'=>$roomId,'identity'=>'audience','history_comment_count'=>'6','last_rtt'=>number_format(100+mt_rand(0,10000)/100,3,'.',''),'heartbeat_duration'=>'10000','resp_content_type'=>'protobuf','did_rule'=>'3',
    ];
    return 'wss://'.$cdnHost.'/webcast/im/ws_proxy/ws_reuse_supplement/?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}
function wsEncode(string $payload,int $opcode=0x2): string {
    $len=strlen($payload);
    $b1=chr(0x80|$opcode);
    $header=$b1;
    $maskBit=0x80;
    if($len<126){ $header.=chr($maskBit|$len); }
    elseif($len<65536){ $header.=chr($maskBit|126).pack('n',$len); }
    else{ $header.=chr($maskBit|127).pack('J',$len); }
    $mask=random_bytes(4);
    $header.=$mask;
    for($i=0;$i<$len;$i++) $payload[$i]=chr(ord($payload[$i]) ^ ord($mask[$i%4]));
    return $header.$payload;
}
function wsDecodeFrame($fp,int $timeout=5): ?array {
    // read 2 bytes
    $hdr=@fread($fp,2);
    if($hdr===false||strlen($hdr)<2) return null;
    $b1=ord($hdr[0]); $b2=ord($hdr[1]);
    $fin=($b1>>7)&1; $opcode=$b1&0x0F; $masked=($b2>>7)&1; $len=$b2&0x7F;
    if($len===126){ $ext=@fread($fp,2); if(strlen($ext)<2) return null; $len=unpack('n',$ext)[1]; }
    elseif($len===127){ $ext=@fread($fp,8); if(strlen($ext)<8) return null; $len=unpack('J',$ext)[1]; }
    $maskKey='';
    if($masked){ $maskKey=@fread($fp,4); if(strlen($maskKey)<4) return null; }
    $payload='';
    $remaining=$len;
    while($remaining>0){
        $chunk=@fread($fp,min(8192,$remaining));
        if($chunk===false||$chunk==='') break;
        $remaining-=strlen($chunk);
        $payload.=$chunk;
    }
    if($masked && $maskKey!==''){
        for($i=0;$i<$len;$i++) $payload[$i]=chr(ord($payload[$i]) ^ ord($maskKey[$i%4]));
    }
    return ['fin'=>$fin,'opcode'=>$opcode,'payload'=>$payload];
}

// ---- main ----
$username=trim($_GET['username'] ?? $_GET['u'] ?? '');
$roomId=trim($_GET['room_id'] ?? '');
$cdnHost=trim($_GET['cdn'] ?? 'webcast-ws.tiktok.com');
if($username && !$roomId){
    $username=extractUsernameSse($username);
    $roomId=getRoomIdSse($config,$username);
    if(!$roomId){ sse_send(['type'=>'error','message'=>'Could not resolve roomId for @'.$username.' (offline?)']); exit; }
} elseif(!$roomId){
    sse_send(['type'=>'error','message'=>'username or room_id required']); exit;
}
if(!isRoomAliveSse($config,$roomId)){
    sse_send(['type'=>'error','message'=>'Room '.$roomId.' not live — chat unavailable']); exit;
}
$ttwid=fetchTtwidSse($config,$username ?: 'tiktok');
if(!$ttwid){
    sse_send(['type'=>'error','message'=>'Failed to fetch ttwid (TikTok blocked IP or region)']); exit;
}
$wssUrl=buildWssUrl($cdnHost,$roomId,'en','US',true);
sse_comment('connecting to '.parse_url($wssUrl,PHP_URL_HOST));
sse_send(['type'=>'status','message'=>'connecting to TikTok WSS','room_id'=>$roomId]);

// open WSS
$u=parse_url($wssUrl);
$host=$u['host']; $path=($u['path']??'/').(isset($u['query'])?'?'.$u['query']:'');
$remote='ssl://'.$host.':443';
$key=base64_encode(random_bytes(16));
$headers=[
    "GET $path HTTP/1.1",
    "Host: $host",
    "Upgrade: websocket",
    "Connection: Upgrade",
    "Sec-WebSocket-Key: $key",
    "Sec-WebSocket-Version: 13",
    "Origin: https://www.tiktok.com",
    "Referer: https://www.tiktok.com/",
    "User-Agent: ".($config['tiktok']['user_agent'] ?? 'Mozilla/5.0'),
    "Cookie: ttwid=$ttwid",
    "Accept-Language: en-US,en;q=0.9",
    "Cache-Control: no-cache",
    "Pragma: no-cache",
];
$ctx=stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
$fp=@stream_socket_client($remote,$errno,$errstr,5,STREAM_CLIENT_CONNECT,$ctx);
if(!$fp){ sse_send(['type'=>'error','message'=>"WSS connect failed: $errno $errstr"]); exit; }
stream_set_timeout($fp,5);
fwrite($fp,implode("\r\n",$headers)."\r\n\r\n");
$resp=fread($fp,8192);
if(!str_contains($resp,'101')){ sse_send(['type'=>'error','message'=>'WSS handshake failed','details'=>substr($resp,0,400)]); fclose($fp); exit; }

// send heartbeat + enter_room (pure TikTok, no XBogus — ttwid in Cookie)
try{
    $hbPayload = TikTokCodec::encodeHeartbeat($roomId);
    $hbFrame   = TikTokCodec::encodePushFrame('hb', $hbPayload);
    $enterPayload = method_exists('TikTokCodec','encodeEnterRoom') ? TikTokCodec::encodeEnterRoom($roomId) : $hbPayload;
    $enterFrame   = TikTokCodec::encodePushFrame('im_enter_room', $enterPayload);
} catch(Throwable $e){ $hbPayload=''; $hbFrame=''; $enterFrame=''; }
if(isset($hbFrame) && $hbFrame!=='') @fwrite($fp, wsEncode($hbFrame,0x2));
if(isset($enterFrame) && $enterFrame!=='') @fwrite($fp, wsEncode($enterFrame,0x2));

sse_send(['type'=>'connected','room_id'=>$roomId,'ttwid'=>substr($ttwid,0,16).'...']);
$lastHb=time();
$hbPayloadFinal = TikTokCodec::encodeHeartbeat($roomId);
$hbFrameFinal = TikTokCodec::encodePushFrame('hb', $hbPayloadFinal);
stream_set_timeout($fp,1);
$buf='';

while(!connection_aborted()){
    // heartbeat every 10s
    if(time()-$lastHb>=10){
        @fwrite($fp, wsEncode($hbFrameFinal,0x2));
        $lastHb=time();
        sse_comment('hb');
    }
    // non-blocking read with select 1s
    $r=[$fp]; $w=null; $e=null;
    $n=@stream_select($r,$w,$e,1,0);
    if($n===false) break;
    if($n>0){
        $frame=wsDecodeFrame($fp,5);
        if($frame===null){ // might be ping
            if(feof($fp)) break;
            continue;
        }
        $opcode=$frame['opcode'];
        $payload=$frame['payload'];
        if($opcode===0x8){ // close
            break;
        }
        if($opcode===0x9){ // ping
            // pong
            $pong=chr(0x8A).chr(strlen($payload)).$payload;
            @fwrite($fp,$pong);
            continue;
        }
        if($opcode!==0x2 && $opcode!==0x1) continue;
        if($payload==='' ) continue;
        // decode push frame
        try{
            $pf=TikTokCodec::decodePushFrame($payload);
        }catch(Throwable $ex){ continue; }
        $pl=$pf['payload'] ?? '';
        if($pl==='') continue;
        // decompress if gzipped (we disabled compress, but handle)
        if(strlen($pl)>=3 && $pl[0]==="\x1f" && $pl[1]==="\x8b" && $pl[2]==="\x08"){
            $d=@gzdecode($pl);
            if($d!==false) $pl=$d;
        }
        // pl is WebcastResponse bytes
        try{
            $respData=TikTokCodec::decodeFetchResult($pl);
        }catch(Throwable $ex){ continue; }
        // ack if needed
        if(!empty($respData['needsAck']) && !empty($respData['internalExt']) && isset($pf['logId'])){
            $ackPayload=$respData['internalExt'];
            $ackFrame=TikTokCodec::encodePushFrame('ack', $ackPayload, 'pb', $pf['logId'] ?? '0');
            @fwrite($fp, wsEncode($ackFrame,0x2));
        }
        // send raw counts for debugging (user sees if any msg arrives)
        if(empty($respData['messages'])){
            // no messages in this push — still heartbeat
            sse_comment('msg empty '.strlen($pl).'B');
        }
        foreach($respData['messages'] as $msg){
            $type=$msg['type'] ?? '';
            $pld=$msg['payload'] ?? '';
            if($type==='WebcastChatMessage'){
                try{ $c=TikTokCodec::decodeChat($pld); sse_send(['type'=>'chat','user'=>$c['user'],'comment'=>$c['comment']]); }catch(Throwable $e){ sse_send(['type'=>'debug','message'=>'chat decode fail '.$e->getMessage()]);}
            } elseif($type==='WebcastGiftMessage'){
                try{ $g=TikTokCodec::decodeGift($pld); sse_send(['type'=>'gift','giftId'=>$g['giftId'],'repeatCount'=>$g['repeatCount'],'repeatEnd'=>$g['repeatEnd'],'user'=>$g['user']]); }catch(Throwable $e){}
            } elseif($type==='WebcastLikeMessage'){
                try{ $l=TikTokCodec::decodeLike($pld); sse_send(['type'=>'like','likeCount'=>$l['likeCount'],'totalLikeCount'=>$l['totalLikeCount'],'user'=>$l['user']]); }catch(Throwable $e){}
            } elseif($type==='WebcastMemberMessage'){
                sse_send(['type'=>'member','user'=>['uniqueId'=>'?'],'comment'=>'joined']);
            } elseif($type==='WebcastRoomUserSeqMessage'){
                // viewer count — forward as status
                // skip noise, but count for debug
                sse_comment('viewer seq '.strlen($pld).'B');
            } else {
                // other types — show type for debug so user knows stream is alive
                // comment to avoid chat spam, but data for console
                sse_comment('other '.$type);
            }
        }
    }
    // flush
    @ob_flush(); @flush();
    if(connection_aborted()) break;
    // also break after 280s to avoid max_execution_time 300
    if(time()-$_SERVER['REQUEST_TIME']>280) { sse_send(['type'=>'status','message'=>'sse timeout, reconnect']); break; }
}
fclose($fp);
sse_send(['type'=>'disconnected']);
