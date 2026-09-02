<?php

/**
 * Zero-dependency statement-level coverage collector.
 *
 * Instrumented copies of the project's PHP files call Cov::hit() after/before
 * each executable statement. Hits are buffered in-process and flushed to a
 * shared text log on shutdown (one "<realPath>:<line>" per line, appended), so
 * multiple PHP processes (the built-in HTTP server runs one process per
 * request) all contribute to the same coverage log.
 */

namespace TiktokCoverage;

final class Cov
{
    /** @var array<string, array<int, int>> realPath => line => 1 */
    private static array $hits = [];
    private static string $logPath = '';
    private static bool $initialized = false;

    public static function setLog(string $path): void
    {
        self::$logPath = $path;
    }

    public static function hit(string $file, int $line): void
    {
        self::$hits[$file][$line] = 1;
    }

    public static function hitSpan(string $file, int $from, int $to): void
    {
        if ($to < $from) {
            $t = $from;
            $from = $to;
            $to = $t;
        }
        for ($line = $from; $line <= $to; $line++) {
            self::$hits[$file][$line] = 1;
        }
    }

    public static function dump(): void
    {
        if (self::$logPath === '' || self::$hits === []) {
            return;
        }
        $lines = [];
        foreach (self::$hits as $file => $hits) {
            foreach ($hits as $line => $_) {
                $lines[] = $file . ':' . $line;
            }
        }
        if ($lines === []) {
            return;
        }
        @file_put_contents(self::$logPath, "\n" . implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
        self::$hits = [];
    }

    public static function install(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        register_shutdown_function(self::dump(...));
    }
}