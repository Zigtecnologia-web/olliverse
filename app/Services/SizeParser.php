<?php

declare(strict_types=1);

namespace App\Services;

final class SizeParser
{
    public static function bytesToGb(int|float|null $bytes): ?float
    {
        if (!$bytes || $bytes <= 0) {
            return null;
        }

        return round($bytes / 1024 / 1024 / 1024, 1);
    }

    public static function sizeToGb(?string $size): ?float
    {
        if (!$size || preg_match('/([\d.,]+)\s*(bytes?|kb|kib|mb|mib|gb|gib|tb|tib)?/i', $size, $matches) !== 1) {
            return null;
        }

        $value = (float) str_replace(',', '.', $matches[1]);
        $unit = strtolower($matches[2] ?? 'bytes');

        return match ($unit) {
            'tb', 'tib' => round($value * 1024, 1),
            'gb', 'gib' => round($value, 1),
            'mb', 'mib' => round($value / 1024, 1),
            'kb', 'kib' => round($value / 1024 / 1024, 1),
            default => self::bytesToGb($value),
        };
    }
}
