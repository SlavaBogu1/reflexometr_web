<?php

declare(strict_types=1);

namespace Reflexometr\Support;

/**
 * HF-02 (final-trial-hang investigation): temporary local file log for a host with no accessible
 * PHP error log (WebHostMost shared hosting) — writes to server/storage/debug.log (non-web-servable,
 * gitignored). Remove all call sites + this file once the investigation concludes.
 */
final class DebugLog
{
    public static function write(string $tag, array $data = []): void
    {
        $path = dirname(__DIR__, 2) . '/storage/debug.log';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s %s\n",
            date('Y-m-d H:i:s.v'),
            $tag,
            $data === [] ? '' : json_encode($data, JSON_UNESCAPED_SLASHES),
        );
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private function __construct()
    {
    }
}
