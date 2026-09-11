<?php
/**
 * Minimal protobuf codec for TikTok webcast — extracted from
 * Nargor/tiktok-live-connector-php (MIT) for pure-PHP fetch decoding.
 * Only the fields needed for chat_token (wsUrl/cursor/internalExt + chat)
 * are implemented.
 */

final class TikTokProtoReader
{
    public int $pos = 0;
    public int $len;
    public function __construct(public string $buf) { $this->len = strlen($buf); }
    public function eof(): bool { return $this->pos >= $this->len; }
    public function readVarint(): int {
        $result = 0; $shift = 0;
        while (true) {
            if ($this->pos >= $this->len) throw new RuntimeException('Truncated varint.');
            $byte = ord($this->buf[$this->pos++]);
            $result |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) break;
            $shift += 7;
            if ($shift > 63) throw new RuntimeException('Varint too long.');
        }
        return $result;
    }
    public function readTag(): ?array {
        if ($this->eof()) return null;
        $tag = $this->readVarint();
        return [$tag >> 3, $tag & 0x07];
    }
    public function readBytes(): string {
        $len = $this->readVarint();
        if ($this->pos + $len > $this->len) throw new RuntimeException('Truncated length-delimited.');
        $slice = substr($this->buf, $this->pos, $len);
        $this->pos += $len;
        return $slice;
    }
    public function readString(): string { return $this->readBytes(); }
    public function skip(int $wireType): void {
        switch ($wireType) {
            case 0: $this->readVarint(); return;
            case 1: $this->pos += 8; return;
            case 2: $len = $this->readVarint(); $this->pos += $len; return;
            case 5: $this->pos += 4; return;
            default: throw new RuntimeException("Unsupported wire $wireType");
        }
    }
    public function walk(callable $visitor): void {
        while (!$this->eof()) {
            $tag = $this->readTag();
            if ($tag === null) return;
            [$field,$wire] = $tag;
            $before = $this->pos;
            if ($visitor($field,$wire,$this) === false) {
                $this->pos = $before;
                $this->skip($wire);
            }
        }
    }
}

final class TikTokProtoWriter
{
    private string $buf = '';
    public function getBytes(): string { return $this->buf; }
    public function writeVarint(int $value): void {
        if ($value < 0) {
            $this->buf .= chr(($value & 0x7F) | 0x80);
            for ($i=0;$i<8;$i++) { $value = ($value >> 7) & ~(0x7F << 57); $this->buf .= chr(($value & 0x7F) | 0x80); }
            $this->buf .= chr(0x01); return;
        }
        while ($value > 0x7F) { $this->buf .= chr(($value & 0x7F) | 0x80); $value >>= 7; }
        $this->buf .= chr($value & 0x7F);
    }
    public function writeTag(int $f,int $w): void { $this->writeVarint(($f<<3)|$w); }
    public function writeVarintField(int $f,int $v): void { $this->writeTag($f,0); $this->writeVarint($v); }
    public function writeStringField(int $f,string $v): void { $this->writeTag($f,2); $this->writeVarint(strlen($v)); $this->buf .= $v; }
    public function writeBytesField(int $f,string $v): void { $this->writeStringField($f,$v); }
    public function writeInt64StringField(int $f,string $v): void { $this->writeVarintField($f, is_numeric($v)?(int)$v:0); }
}

final class TikTokCodec
{
    public static function decodePushFrame(string $bytes): array {
        $out = ['seqId'=>'0','logId'=>'0','payloadEncoding'=>'','payloadType'=>'','payload'=>'','headers'=>[]];
        (new TikTokProtoReader($bytes))->walk(function(int $field,int $wire,TikTokProtoReader $r) use (&$out): bool {
            switch($field){
                case 1: $out['seqId']=(string)$r->readVarint(); return true;
                case 2: $out['logId']=(string)$r->readVarint(); return true;
                case 5: $entry=$r->readBytes(); [$k,$v]=self::decodeStringMapEntry($entry); $out['headers'][$k]=$v; return true;
                case 6: $out['payloadEncoding']=$r->readString(); return true;
                case 7: $out['payloadType']=$r->readString(); return true;
                case 8: $out['payload']=$r->readBytes(); return true;
                default: return false;
            }
        });
        return $out;
    }
    public static function decodeFetchResult(string $bytes): array {
        $out=['messages'=>[],'cursor'=>'','internalExt'=>'','wsUrl'=>'','wsParams'=>[],'needsAck'=>false,'heartBeatDuration'=>0];
        (new TikTokProtoReader($bytes))->walk(function(int $field,int $wire,TikTokProtoReader $r) use (&$out): bool {
            switch($field){
                case 1: $out['messages'][]=self::decodeBaseProtoMessage($r->readBytes()); return true;
                case 2: $out['cursor']=$r->readString(); return true;
                case 5: $out['internalExt']=$r->readString(); return true;
                case 7: [$k,$v]=self::decodeStringMapEntry($r->readBytes()); $out['wsParams'][$k]=$v; return true;
                case 8: $out['heartBeatDuration']=$r->readVarint(); return true;
                case 9: $out['needsAck']=$r->readVarint()!==0; return true;
                case 10: $out['wsUrl']=$r->readString(); return true;
                default: return false;
            }
        });
        return $out;
    }
    private static function decodeBaseProtoMessage(string $bytes): array {
        $out=['type'=>'','payload'=>''];
        (new TikTokProtoReader($bytes))->walk(function(int $f,int $w,TikTokProtoReader $r) use (&$out): bool {
            switch($f){ case 1:$out['type']=$r->readString();return true; case 2:$out['payload']=$r->readBytes();return true; default:return false;}
        });
        return $out;
    }
    public static function decodeUser(string $bytes): array {
        $out=['userId'=>'0','nickname'=>'','uniqueId'=>''];
        (new TikTokProtoReader($bytes))->walk(function(int $f,int $w,TikTokProtoReader $r) use (&$out): bool {
            switch($f){ case 1:$out['userId']=(string)$r->readVarint();return true; case 3:$out['nickname']=$r->readString();return true; case 38:$out['uniqueId']=$r->readString();return true; default:return false;}
        });
        return $out;
    }
    public static function decodeChat(string $bytes): array {
        $out=['comment'=>'','user'=>null];
        (new TikTokProtoReader($bytes))->walk(function(int $f,int $w,TikTokProtoReader $r) use (&$out): bool {
            switch($f){
                case 2: $out['user']=self::decodeUser($r->readBytes());return true;
                case 3: $out['comment']=$r->readString();return true;
                default:return false;
            }
        });
        return $out;
    }
    public static function decodeGift(string $bytes): array {
        $out=['giftId'=>0,'repeatCount'=>0,'repeatEnd'=>0,'user'=>null];
        (new TikTokProtoReader($bytes))->walk(function(int $f,int $w,TikTokProtoReader $r) use (&$out): bool {
            switch($f){
                case 2:$out['giftId']=$r->readVarint();return true;
                case 5:$out['repeatCount']=$r->readVarint();return true;
                case 7:$out['user']=self::decodeUser($r->readBytes());return true;
                case 9:$out['repeatEnd']=$r->readVarint();return true;
                default:return false;
            }
        });
        return $out;
    }
    public static function decodeLike(string $bytes): array {
        $out=['likeCount'=>0,'totalLikeCount'=>0,'user'=>null];
        (new TikTokProtoReader($bytes))->walk(function(int $f,int $w,TikTokProtoReader $r) use (&$out): bool {
            switch($f){
                case 2:$out['likeCount']=$r->readVarint();return true;
                case 3:$out['totalLikeCount']=$r->readVarint();return true;
                case 5:$out['user']=self::decodeUser($r->readBytes());return true;
                default:return false;
            }
        });
        return $out;
    }
    private static function decodeStringMapEntry(string $bytes): array {
        $k=$v='';
        (new TikTokProtoReader($bytes))->walk(function(int $f,int $w,TikTokProtoReader $r) use (&$k,&$v): bool {
            switch($f){ case 1:$k=$r->readString();return true; case 2:$v=$r->readString();return true; default:return false;}
        });
        return [$k,$v];
    }
    public static function encodeHeartbeat(string $roomId): string {
        $w=new TikTokProtoWriter(); if($roomId!=='0'&&$roomId!=='') $w->writeInt64StringField(1,$roomId); $w->writeInt64StringField(2,'1'); return $w->getBytes();
    }
    public static function encodeEnterRoom(string $roomId): string {
        $w=new TikTokProtoWriter(); if($roomId!=='0'&&$roomId!=='') $w->writeInt64StringField(1,$roomId); $w->writeInt64StringField(4,'12'); $w->writeStringField(5,'audience'); return $w->getBytes();
    }
    public static function encodePushFrame(string $payloadType,string $payload,string $payloadEncoding='pb',string $logId='0'): string {
        $w=new TikTokProtoWriter(); if($logId!=='0'&&$logId!=='') $w->writeInt64StringField(2,$logId); if($payloadEncoding!=='') $w->writeStringField(6,$payloadEncoding); if($payloadType!=='') $w->writeStringField(7,$payloadType); if($payload!=='') $w->writeBytesField(8,$payload); return $w->getBytes();
    }
}
