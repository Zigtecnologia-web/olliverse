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
        public string $sqliteDatabasePath,
        public string $ragEmbeddingModel,
        public array $preferredModels,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            self::env('DEFAULT_SYSTEM_PROMPT', 'Você é um assistente técnico, analítico e pragmático. Entenda a intenção da solicitação antes de responder. Priorize clareza, precisão e objetividade. Explique trade-offs quando existirem, não faça suposições sem evidências e deixe explícitas as incertezas quando necessário. Adapte a profundidade e a linguagem ao contexto e ao nível técnico do usuário.'),
            (int) self::env('CONTEXT_TOKEN_LIMIT', '8192'),
            (int) self::env('OLLAMA_CONNECT_TIMEOUT', '10'),
            (int) self::env('OLLAMA_RESPONSE_TIMEOUT', '180'),
            (int) self::env('MODEL_METADATA_CACHE_TTL', '3600'),
            self::env('SQLITE_DATABASE_PATH', dirname(__DIR__, 2) . '/storage/database.sqlite'),
            self::env('RAG_EMBEDDING_MODEL', 'nomic-embed-text'),
            ['llama3.2:latest', 'llama3.2', 'qwen2.5:0.5b'],
        );
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return is_string($value) && trim($value) !== '' ? $value : $default;
    }
}
