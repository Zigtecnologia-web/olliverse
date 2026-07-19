<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Database\SqliteConnection;
use App\Database\SqliteMigrator;
use App\Services\ContextWindowService;
use App\Services\ModelMetadataService;
use App\Services\OllamaClient;

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
    $config->ollamaResponseTimeout
);
$contextWindowService = new ContextWindowService($config->contextTokenLimit);
$pdo = (new SqliteConnection($config->sqliteDatabasePath))->pdo();
(new SqliteMigrator($pdo))->migrate();
$modelMetadataService = new ModelMetadataService(
    $ollamaClient,
    $config->modelMetadataCacheTtl
);

return [
    'config' => $config,
    'ollama_client' => $ollamaClient,
    'context_window' => $contextWindowService,
    'pdo' => $pdo,
    'model_metadata_service' => $modelMetadataService,
];
