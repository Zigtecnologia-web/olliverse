<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Http\ChatStreamHandler;
use App\Repositories\SessionConversationRepository;
use App\Services\ContextWindowService;
use App\Services\ModelMetadataService;
use App\Services\OllamaClient;

session_start();

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

$config = AppConfig::fromEnvironment();
$ollamaClient = new OllamaClient(
    $config->ollamaBaseUrl,
    $config->ollamaConnectTimeout,
    $config->ollamaResponseTimeout
);
$contextWindowService = new ContextWindowService($config->contextTokenLimit);
$conversationRepository = new SessionConversationRepository();
$modelMetadataService = new ModelMetadataService(
    $ollamaClient,
    $config->modelMetadataCacheTtl
);

return [
    'config' => $config,
    'ollama_client' => $ollamaClient,
    'context_window' => $contextWindowService,
    'conversation_repository' => $conversationRepository,
    'model_metadata_service' => $modelMetadataService,
    'chat_stream_handler' => new ChatStreamHandler(
        $config,
        $ollamaClient,
        $conversationRepository,
        $contextWindowService
    ),
];
