<?php

/**
 * Minimal zero-dependency test framework + JUnit XML writer + HTTP helper.
 */

final class T
{
    /** @var list<array{name:string, status:string, detail:string, time:float}> */
    private static array $cases = [];

    public static function case(string $name): void
    {
        self::$cases[] = ['name' => $name, 'status' => 'PASS', 'detail' => '', 'time' => 0.0];
    }

    public static function assertTrue(bool $cond, string $msg): void
    {
        if (!$cond) {
            self::fail($msg);
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $msg): void
    {
        if ($expected !== $actual) {
            self::fail($msg . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
        }
    }

    public static function assertContains(string $needle, string $haystack, string $msg): void
    {
        if (!str_contains($haystack, $needle)) {
            self::fail($msg . " missing \"$needle\" in: " . substr($haystack, 0, 200));
        }
    }

    public static function fail(string $msg): void
    {
        $idx = count(self::$cases) - 1;
        if ($idx >= 0) {
            self::$cases[$idx]['status'] = 'FAIL';
            self::$cases[$idx]['detail'] = $msg;
        }
    }

    /** @return list<array{name:string, status:string, detail:string, time:float}> */
    public static function cases(): array
    {
        return self::$cases;
    }

    public static function total(): int
    {
        return count(self::$cases);
    }

    public static function failures(): int
    {
        $n = 0;
        foreach (self::$cases as $c) {
            if ($c['status'] === 'FAIL') {
                $n++;
            }
        }
        return $n;
    }

    public static function junit(string $suiteName, string $repoPath): string
    {
        $ms = self::total();
        $fs = self::failures();
        $fileMap = [
            'api:' => $repoPath . '/api.php',
            'proxy:' => $repoPath . '/proxy.php',
            'config:' => $repoPath . '/config.php',
            'signer:' => $repoPath . '/tiktok_signer.php',
        ];
        $byFile = [];
        foreach (self::$cases as $c) {
            $file = $repoPath . '/run-tests.php';
            foreach ($fileMap as $prefix => $f) {
                if (str_starts_with($c['name'], $prefix)) {
                    $file = $f;
                    break;
                }
            }
            $byFile[$file][] = $c;
        }
        $out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<testsuites name="' . htmlspecialchars($suiteName) . '" tests="' . $ms . '" failures="' . $fs . '">';
        foreach ($byFile as $file => $cases) {
            $out .= '<testsuite name="' . htmlspecialchars($file) . '" file="' . htmlspecialchars($file) . '" tests="'
                . count($cases) . '" failures="' . count(array_filter($cases, fn ($c) => $c['status'] === 'FAIL')) . '">';
            foreach ($cases as $c) {
                $out .= '<testcase classname="' . htmlspecialchars($suiteName) . '" name="'
                    . htmlspecialchars($c['name']) . '" time="' . sprintf('%.3f', $c['time']) . '">';
                if ($c['status'] === 'FAIL') {
                    $out .= '<failure message="' . htmlspecialchars($c['detail']) . '"/>';
                }
                $out .= '</testcase>';
            }
            $out .= '</testsuite>';
        }
        return $out . '</testsuites>';
    }

    /**
     * cURL HTTP client. Returns [httpStatus, body, headersList].
     * @return array{0:int,1:string,2:array<string,string>}
     */
    public static function http(string $method, string $url, array $extraHeaders = [], string $body = '', int $timeout = 10): array
    {
        $headers = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    return strlen($line);
                }
                $pos = strpos($trimmed, ':');
                if ($pos !== false && !preg_match('#^HTTP/#i', $trimmed)) {
                    $headers[strtolower(trim(substr($trimmed, 0, $pos)))] = trim(substr($trimmed, $pos + 1));
                }
                return strlen($line);
            },
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $extraHeaders,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        return [$status, $resp === false ? '' : $resp, $headers];
    }

    /** @return resource */
    public static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            fwrite(STDERR, "no free port: $errstr\n");
            return 0;
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        return $port;
    }

    /** @return array{0:resource,1:bool} [proc, ready] */
    public static function startServer(string $docrootOrRouter, int $port, bool $router): array
    {
        $cmd = [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=1', '-S', "127.0.0.1:$port"];
        if ($router) {
            $cmd[] = $docrootOrRouter;
        } else {
            $cmd[] = '-t';
            $cmd[] = $docrootOrRouter;
        }
        // Send stdout/stderr to a file, never pipes: an undrained pipe buffer
        // would block the server child once the request log grows past it.
        $dir = dirname(__DIR__) . '/.work';
        $logFd = ['file', $dir . '/server-' . $port . '.log', 'a'];
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $logFd, 2 => $logFd], $pipes, null, ['PATH' => getenv('PATH')]);

        $deadline = microtime(true) + 5;
        $ready = false;
        while (microtime(true) < $deadline) {
            $conn = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 0.3);
            if ($conn !== false) {
                fclose($conn);
                $ready = true;
                break;
            }
            usleep(50_000);
        }
        return [$proc, $ready];
    }

    public static function stopServer($proc): void
    {
        if (!is_resource($proc)) {
            return;
        }
        $status = proc_get_status($proc);
        if ($status['running']) {
            proc_terminate($proc);
            $deadline = microtime(true) + 2;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    break;
                }
                usleep(20_000);
            }
            if ($status['running']) {
                @exec('kill -9 ' . (int) $status['pid'] . ' 2>/dev/null');
                usleep(50_000);
            }
        }
        $status = proc_get_status($proc);
        if (!($status['running'] ?? false)) {
            proc_close($proc);
        }
    }
}