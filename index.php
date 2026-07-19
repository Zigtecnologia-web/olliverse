<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Http\ChatStreamHandler;
use App\Repositories\SqliteConversationRepository;
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
/** @var ModelMetadataService $modelMetadataService */
$modelMetadataService = $app['model_metadata_service'];
/** @var \PDO $pdo */
$pdo = $app['pdo'];

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

if (isset($_GET['new']) || (isset($_GET['clear']) && $_GET['clear'] === '1')) {
    $currentChatId = (int) ($_GET['chat_id'] ?? 0);
    $systemPromptForNewChat = SqliteConversationRepository::systemPromptForChat($pdo, $currentChatId)
        ?? $config->defaultSystemPrompt;

    redirectToChat(SqliteConversationRepository::createChat(
        $pdo,
        $defaultModel,
        $systemPromptForNewChat
    ));
}

$chatId = (int) ($_GET['chat_id'] ?? 0);

if ($chatId <= 0 || !SqliteConversationRepository::exists($pdo, $chatId)) {
    redirectToChat(SqliteConversationRepository::createChat(
        $pdo,
        $defaultModel,
        $config->defaultSystemPrompt
    ));
}

$conversationRepository = new SqliteConversationRepository(
    $pdo,
    $contextWindowService,
    $chatId,
    $defaultModel
);
$chatStreamHandler = new ChatStreamHandler(
    $config,
    $ollamaClient,
    $conversationRepository,
    $contextWindowService
);
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
$initialMessages = $conversationRepository->messages();
$initialContextUsage = $contextWindowService->usage(
    $contextWindowService->withSystemPrompt($systemPrompt, $initialMessages)
);

function redirectToChat(int $chatId): never
{
    $baseUri = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';

    header('Location: ' . $baseUri . '?chat_id=' . $chatId);
    exit;
}

function iconeEnviar(): string
{
    return IconSvg::send();
}

function iconSvg(string $name): string
{
    return IconSvg::render($name);
}
require __DIR__ . '/views/chat.php';
