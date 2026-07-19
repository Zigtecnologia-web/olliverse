<?php

declare(strict_types=1);

namespace App\Services;

final class ModelSelector
{
    /**
     * @param array<int, string> $models
     * @param array<int, string> $preferences
     */
    public static function defaultModel(array $models, array $preferences): string
    {
        foreach ($preferences as $preference) {
            if (in_array($preference, $models, true)) {
                return $preference;
            }
        }

        return $models[0] ?? 'llama3.2:latest';
    }
}
