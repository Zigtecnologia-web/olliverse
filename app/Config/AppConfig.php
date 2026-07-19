<?php

declare(strict_types=1);

namespace App\Config;

final readonly class AppConfig
{
    /**
     * @param array<int, string> $preferredModels
     */
    public function __construct(
        public string $ollamaBaseUrl,
        public string $defaultSystemPrompt,
        public int $contextTokenLimit,
        public int $ollamaConnectTimeout,
        public int $ollamaResponseTimeout,
        public int $modelMetadataCacheTtl,
        public array $preferredModels,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            self::env('DEFAULT_SYSTEM_PROMPT', 'Você é um assistente técnico prestativo.'),
            (int) self::env('CONTEXT_TOKEN_LIMIT', '8000'),
            (int) self::env('OLLAMA_CONNECT_TIMEOUT', '10'),
            (int) self::env('OLLAMA_RESPONSE_TIMEOUT', '180'),
            (int) self::env('MODEL_METADATA_CACHE_TTL', '3600'),
            ['llama3.2:latest', 'llama3.2', 'qwen2.5:0.5b'],
        );
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return is_string($value) && trim($value) !== '' ? $value : $default;
    }
}
