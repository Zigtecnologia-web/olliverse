<?php

declare(strict_types=1);

namespace App\Http;

final class NdjsonResponse
{
    public static function start(): void
    {
        header('Content-Type: application/x-ndjson; charset=UTF-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function emit(array $payload): void
    {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
    }
}
