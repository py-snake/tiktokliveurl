<?php

/**
 * X-Gnarly signature generator for TikTok webcast API.
 *
 * Pure PHP port of haig233/tiktok-xgnarly-decoded (JS reference implementation).
 * No external dependencies — uses only PHP built-in functions.
 *
 * Usage:
 *   $xgnarly = TikTokSigner::encode($queryString, $body, $userAgent);
 *   $signedUrl = $url . '&X-Gnarly=' . $xgnarly;
 */
class TikTokSigner
{
    private const MAGIC_BYTE = 75; // 'K'

    private const SIGMA = [
        1196819126,
        600974999,
        3863347763,
        1451689750,
    ];

    private const ALPHABET =
        'u09tbS3UvgDEe6r-ZVMXzLpsAohTn7mdINQlW412GqBjfYiyk8JORCF5/xKHwacP=';

    private const FIELD_ORDER = [
        1, 2, 6, 7, 8, 9, 10, 11, 4, 5, 3, 12, 13, 14, 15, 0,
    ];

    /**
     * Encode X-Gnarly signature.
     *
     * @param string $queryString  URL query string without leading '?', with msToken, minus X-Bogus/X-Gnarly
     * @param string $body         Request body ('' for GET)
     * @param string $userAgent    User-Agent header
     * @param array  $counters    ['totalXHR' => int, 'totalFetch' => int, 'interceptedXHR' => int, 'interceptedFetch' => int]
     * @param array  $options     ['ubcode' => int, 'sdkVersion' => string, 'timestampMs' => int]
     * @return string Base64-encoded X-Gnarly value
     */
    public static function encode(
        string $queryString,
        string $body,
        string $userAgent,
        array $counters = [],
        array $options = []
    ): string {
        $ts = $options['timestampMs'] ?? (int) (microtime(true) * 1000);
        $ubcode = $options['ubcode'] ?? 4;
        $sdkVersion = $options['sdkVersion'] ?? '1.0.0.368';

        $r14Low = random_int(0, 0xFFFF);
        $field14 = (65 << 16) | $r14Low;

        $field15 = random_int(0, 0xFFFFFFFF);

        $md5Query = md5($queryString);
        $md5Body = md5($body);
        $md5Ua = md5($userAgent);

        $fields = [
            1  => 65,
            2  => $ubcode,
            3  => $md5Query,
            4  => $md5Body,
            5  => $md5Ua,
            6  => intdiv($ts, 1000),
            7  => 3181061566,
            8  => $ts % 0x80000000,
            9  => '5.1.3-ZTCA',
            10 => $sdkVersion,
            11 => 1,
            12 => ($counters['totalXHR'] ?? 0) + ($counters['totalFetch'] ?? 0),
            13 => ($counters['interceptedXHR'] ?? 0) + ($counters['interceptedFetch'] ?? 0),
            14 => $field14,
            15 => $field15,
        ];

        $plaintext = self::encodePayload($fields);

        $keyBytes = [];
        for ($i = 0; $i < 48; $i++) {
            $keyBytes[] = random_int(0, 255);
        }

        $keyWords = [];
        for ($i = 0; $i < 12; $i++) {
            $o = $i * 4;
            $keyWords[$i] = ($keyBytes[$o]
                | ($keyBytes[$o + 1] << 8)
                | ($keyBytes[$o + 2] << 16)
                | ($keyBytes[$o + 3] << 24)) & 0xFFFFFFFF;
        }

        $rounds = self::deriveRounds($keyWords);

        $cipher = $plaintext;
        self::chachaXor($cipher, $keyWords, $rounds);

        $xLen = count($cipher);
        $mod = $xLen + 1;
        $sum = 0;
        foreach ($keyBytes as $b) {
            $sum = ($sum + $b) % $mod;
        }
        foreach ($cipher as $b) {
            $sum = ($sum + $b) % $mod;
        }
        $insertPos = $sum;

        $out = [];
        $out[] = self::MAGIC_BYTE;
        for ($i = 0; $i < $insertPos; $i++) {
            $out[] = $cipher[$i];
        }
        for ($i = 0; $i < 48; $i++) {
            $out[] = $keyBytes[$i];
        }
        for ($i = $insertPos; $i < $xLen; $i++) {
            $out[] = $cipher[$i];
        }

        return self::encodeBase64($out);
    }

    private static function encodePayload(array $fields): array
    {
        $present = [];
        foreach (self::FIELD_ORDER as $k) {
            if ($k === 0) {
                continue;
            }
            if (!isset($fields[$k])) {
                continue;
            }
            $present[] = $k;
        }

        $xorHeader = 0;
        foreach ($present as $k) {
            $v = $fields[$k];
            if (is_int($v)) {
                $xorHeader = ($xorHeader ^ $v) & 0xFFFFFFFF;
            }
        }

        $order = $present;
        $order[] = 0;
        $fieldsWith0 = $fields;
        $fieldsWith0[0] = $xorHeader;

        $out = [count($order)];
        foreach ($order as $k) {
            $v = $fieldsWith0[$k];
            if (is_int($v)) {
                $valueBytes = self::intToBytes($v);
            } elseif (is_string($v)) {
                $valueBytes = array_values(unpack('C*', $v));
            } else {
                throw new \InvalidArgumentException("unsupported field $k type: " . gettype($v));
            }
            $out[] = $k & 0xFF;
            $len = count($valueBytes);
            $out[] = ($len >> 8) & 0xFF;
            $out[] = $len & 0xFF;
            foreach ($valueBytes as $b) {
                $out[] = $b;
            }
        }

        return $out;
    }

    private static function intToBytes(int $n): array
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('non-int');
        }
        if ($n === 0) {
            return [0];
        }
        $out = [];
        while ($n > 0) {
            array_unshift($out, $n & 0xFF);
            $n = intdiv($n, 256);
        }
        return $out;
    }

    private static function encodeBase64(array $bytes): string
    {
        $out = '';
        $i = 0;
        $len = count($bytes);
        $alpha = self::ALPHABET;

        while ($i + 3 <= $len) {
            $n = ($bytes[$i] << 16) | ($bytes[$i + 1] << 8) | $bytes[$i + 2];
            $out .= $alpha[($n >> 18) & 63];
            $out .= $alpha[($n >> 12) & 63];
            $out .= $alpha[($n >> 6) & 63];
            $out .= $alpha[$n & 63];
            $i += 3;
        }

        $rem = $len - $i;
        if ($rem === 1) {
            $n = $bytes[$i] << 16;
            $out .= $alpha[($n >> 18) & 63];
            $out .= $alpha[($n >> 12) & 63];
            $out .= '==';
        } elseif ($rem === 2) {
            $n = ($bytes[$i] << 16) | ($bytes[$i + 1] << 8);
            $out .= $alpha[($n >> 18) & 63];
            $out .= $alpha[($n >> 12) & 63];
            $out .= $alpha[($n >> 6) & 63];
            $out .= '=';
        }

        return $out;
    }

    private static function chachaXor(array &$bytes, array $keyWords, int $rounds): void
    {
        $state = array_merge(self::SIGMA, $keyWords);
        $len = count($bytes);

        for ($off = 0; $off < $len; $off += 64) {
            $stream = self::chachaBlock($state, $rounds);
            $state[12] = ($state[12] + 1) & 0xFFFFFFFF;
            $lim = min(64, $len - $off);
            for ($i = 0; $i < $lim; $i++) {
                $word = $stream[$i >> 2];
                $byte = ($word >> (8 * ($i & 3))) & 0xFF;
                $bytes[$off + $i] ^= $byte;
            }
        }
    }

    private static function chachaBlock(array $initial, int $rounds): array
    {
        $s = $initial;
        $r = 0;

        while ($r < $rounds) {
            self::quarter($s, 0, 4, 8, 12);
            self::quarter($s, 1, 5, 9, 13);
            self::quarter($s, 2, 6, 10, 14);
            self::quarter($s, 3, 7, 11, 15);
            if (++$r >= $rounds) {
                break;
            }

            self::quarter($s, 0, 5, 10, 15);
            self::quarter($s, 1, 6, 11, 12);
            self::quarter($s, 2, 7, 12, 13);
            self::quarter($s, 3, 4, 13, 14);
            $r++;
        }

        for ($i = 0; $i < 16; $i++) {
            $s[$i] = ($s[$i] + $initial[$i]) & 0xFFFFFFFF;
        }

        return $s;
    }

    private static function quarter(array &$s, int $a, int $b, int $c, int $d): void
    {
        $s[$a] = ($s[$a] + $s[$b]) & 0xFFFFFFFF;
        $s[$d] = self::rotl($s[$d] ^ $s[$a], 16);
        $s[$c] = ($s[$c] + $s[$d]) & 0xFFFFFFFF;
        $s[$b] = self::rotl($s[$b] ^ $s[$c], 12);
        $s[$a] = ($s[$a] + $s[$b]) & 0xFFFFFFFF;
        $s[$d] = self::rotl($s[$d] ^ $s[$a], 8);
        $s[$c] = ($s[$c] + $s[$d]) & 0xFFFFFFFF;
        $s[$b] = self::rotl($s[$b] ^ $s[$c], 7);
    }

    private static function rotl(int $v, int $c): int
    {
        $v &= 0xFFFFFFFF;
        return (($v << $c) | ($v >> (32 - $c))) & 0xFFFFFFFF;
    }

    private static function deriveRounds(array $keyWords): int
    {
        $r = 0;
        foreach ($keyWords as $w) {
            $r = ($r + ($w & 15)) & 15;
        }
        return $r + 5;
    }
}
