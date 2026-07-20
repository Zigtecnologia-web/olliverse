<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Database\SqliteConnection;
use App\Database\SqliteMigrator;
use App\Repositories\SqliteDocumentChunkRepository;
use App\Services\ContextWindowService;
use App\Services\ModelMetadataService;
use App\Services\OllamaClient;
use App\Services\PromptGeneratorService;
use App\Services\RagChunkerService;
use App\Services\RagIngestionService;
use App\Services\RagRetrievalService;
use App\Services\VectorSimilarityService;

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
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$ollamaClient = new OllamaClient(
    $config->ollamaBaseUrl,
    $config->ollamaConnectTimeout,
    $config->ollamaResponseTimeout,
    [$config->ragEmbeddingModel]
);
$contextWindowService = new ContextWindowService($config->contextTokenLimit);
$pdo = (new SqliteConnection($config->sqliteDatabasePath))->pdo();
(new SqliteMigrator($pdo))->migrate();
$vectorSimilarityService = new VectorSimilarityService();
$documentChunkRepository = new SqliteDocumentChunkRepository($pdo, $vectorSimilarityService);
$modelMetadataService = new ModelMetadataService(
    $ollamaClient,
    $config->modelMetadataCacheTtl
);
$promptGeneratorService = new PromptGeneratorService($ollamaClient);
$ragIngestionService = new RagIngestionService(
    $documentChunkRepository,
    new RagChunkerService(),
    $contextWindowService,
    $ollamaClient,
    $config->ragEmbeddingModel
);
$ragRetrievalService = new RagRetrievalService(
    $documentChunkRepository,
    $ollamaClient,
    $config->ragEmbeddingModel
);

return [
    'config' => $config,
    'ollama_client' => $ollamaClient,
    'context_window' => $contextWindowService,
    'pdo' => $pdo,
    'model_metadata_service' => $modelMetadataService,
    'prompt_generator_service' => $promptGeneratorService,
    'document_chunk_repository' => $documentChunkRepository,
    'rag_ingestion_service' => $ragIngestionService,
    'rag_retrieval_service' => $ragRetrievalService,
];
