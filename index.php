<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Http\ChatStreamHandler;
use App\Repositories\SessionConversationRepository;
use App\Services\ContextWindowService;
use App\Services\ModelMetadataService;
use App\Services\ModelSelector;
use App\Services\OllamaClient;
use App\Support\IconSvg;

$app = require __DIR__ . '/bootstrap/app.php';

/** @var AppConfig $config */
$config = $app['config'];
/** @var OllamaClient $ollamaClient */
$ollamaClient = $app['ollama_client'];
/** @var ContextWindowService $contextWindowService */
$contextWindowService = $app['context_window'];
/** @var SessionConversationRepository $conversationRepository */
$conversationRepository = $app['conversation_repository'];
/** @var ModelMetadataService $modelMetadataService */
$modelMetadataService = $app['model_metadata_service'];
/** @var ChatStreamHandler $chatStreamHandler */
$chatStreamHandler = $app['chat_stream_handler'];

$availableModels = $ollamaClient->listModels();
$defaultModel = ModelSelector::defaultModel($availableModels, $config->preferredModels);

if (($_GET['action'] ?? '') === 'model_metadata') {
    $selectedModel = trim((string) ($_GET['model'] ?? ''));

    header('Content-Type: application/json; charset=UTF-8');

    if ($selectedModel === '' || ($availableModels && !in_array($selectedModel, $availableModels, true))) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Modelo inválido ou indisponível no Ollama local.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode($modelMetadataService->metadata($selectedModel), JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    $conversationRepository->clear();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

$conversationRepository->ensureMessages();
$systemPrompt = $conversationRepository->systemPrompt($config->defaultSystemPrompt);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['system_prompt'])) {
    $systemPrompt = $conversationRepository->replaceSystemPrompt(
        (string) $_POST['system_prompt'],
        $config->defaultSystemPrompt
    );

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'system_prompt' => $systemPrompt,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prompt'])) {
    $chatStreamHandler->handle(
        trim((string) $_POST['prompt']),
        trim((string) ($_POST['model'] ?? $defaultModel)),
        $availableModels
    );
}

$initialAssistantMessage = 'Olá! O Olliverse local está pronto. O que deseja processar ou refatorar hoje?';
$initialContextUsage = $contextWindowService->usage(
    $contextWindowService->withSystemPrompt($systemPrompt, $conversationRepository->messages())
);

function iconeEnviar(): string
{
    return IconSvg::send();
}

function iconSvg(string $name): string
{
    return IconSvg::render($name);
}
require __DIR__ . '/views/chat.php';
