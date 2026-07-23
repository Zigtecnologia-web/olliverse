<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Http\ChatStreamHandler;
use App\Repositories\SqliteChatExportRepository;
use App\Repositories\SqliteChatHistoryRepository;
use App\Repositories\SqliteDocumentChunkRepository;
use App\Repositories\SqliteConversationRepository;
use App\Repositories\SqlitePersonaRepository;
use App\Services\ContextWindowService;
use App\Services\DocumentationService;
use App\Services\ModelMetadataService;
use App\Services\ModelSelector;
use App\Services\OllamaClient;
use App\Services\PluginManager;
use App\Services\PdfExportService;
use App\Services\PromptGeneratorService;
use App\Services\RagIngestionService;
use App\Services\RagRetrievalService;
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
/** @var PromptGeneratorService $promptGeneratorService */
$promptGeneratorService = $app['prompt_generator_service'];
/** @var SqliteDocumentChunkRepository $documentChunkRepository */
$documentChunkRepository = $app['document_chunk_repository'];
/** @var RagIngestionService $ragIngestionService */
$ragIngestionService = $app['rag_ingestion_service'];
/** @var RagRetrievalService $ragRetrievalService */
$ragRetrievalService = $app['rag_retrieval_service'];
/** @var PluginManager $pluginManager */
$pluginManager = $app['plugin_manager'];
/** @var \PDO $pdo */
$pdo = $app['pdo'];

if (($_GET['view'] ?? '') === 'docs') {
    $documentationMode = ($_GET['doc'] ?? 'produto') === 'tecnico' ? 'tecnico' : 'produto';
    $documentationSource = $documentationMode === 'tecnico' ? 'README.md' : 'Produto.md';
    $documentationService = new DocumentationService(__DIR__ . '/Doc/' . $documentationSource);
    $documentationHtml = $documentationService->html();
    $returnChatId = (int) ($_GET['chat_id'] ?? 0);

    require __DIR__ . '/views/documentation.php';
    exit;
}

$availableModels = $ollamaClient->listModels();
$defaultModel = ModelSelector::defaultModel($availableModels, $config->preferredModels);
$personaRepository = new SqlitePersonaRepository($pdo, $config->defaultSystemPrompt);
$chatHistoryRepository = new SqliteChatHistoryRepository($pdo);

if (($_GET['action'] ?? '') === 'history') {
    jsonResponse([
        'success' => true,
        'chats' => $chatHistoryRepository->all(),
    ]);
}

if (in_array(($_GET['action'] ?? ''), ['search', 'search_history'], true)) {
    $searchQuery = trim((string) ($_GET['q'] ?? ''));
    $searchedChats = $searchQuery === '' ? $chatHistoryRepository->all() : $chatHistoryRepository->search($searchQuery);

    jsonResponse([
        'success' => true,
        'query' => $searchQuery,
        'chats' => $searchedChats,
        'chat_ids' => array_map(static fn (array $chat): int => (int) $chat['id'], $searchedChats),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'update_chat_title') {
    try {
        $titleChatId = (int) ($_POST['chat_id'] ?? 0);
        $selectedModel = trim((string) ($_POST['model'] ?? $defaultModel));
        $postedTitle = trim((string) ($_POST['title'] ?? ''));

        if ($titleChatId <= 0 || !SqliteConversationRepository::exists($pdo, $titleChatId)) {
            throw new RuntimeException('Conversa não encontrada para atualizar o título.');
        }

        if ($selectedModel === '' || ($availableModels && !in_array($selectedModel, $availableModels, true))) {
            $selectedModel = $defaultModel;
        }

        $titleConversationRepository = new SqliteConversationRepository(
            $pdo,
            $contextWindowService,
            $titleChatId,
            $defaultModel
        );

        if ($postedTitle === '') {
            if (!$titleConversationRepository->shouldGenerateTitle()) {
                jsonResponse([
                    'success' => true,
                    'skipped' => true,
                    'chat' => $chatHistoryRepository->find($titleChatId),
                    'chats' => $chatHistoryRepository->all(),
                ]);
            }

            $postedTitle = generateChatTitle(
                $ollamaClient,
                $selectedModel,
                $titleConversationRepository->messages()
            );
        }

        $updatedTitle = $titleConversationRepository->updateTitle($postedTitle);

        jsonResponse([
            'success' => true,
            'title' => $updatedTitle,
            'chat' => $chatHistoryRepository->find($titleChatId),
            'chats' => $chatHistoryRepository->all(),
        ]);
    } catch (Throwable $error) {
        jsonResponse([
            'success' => false,
            'error' => $error->getMessage(),
        ], 422);
    }
}

if (in_array(($_GET['action'] ?? ''), ['export', 'export_md'], true)) {
    $exportChatId = (int) ($_GET['chat_id'] ?? 0);
    $exportRepository = new SqliteChatExportRepository($pdo);
    $payload = $exportRepository->markdownPayload($exportChatId);

    if ($payload === null) {
        http_response_code(404);
        echo 'Conversa não encontrada.';
        exit;
    }

    header('Content-Type: text/markdown; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $exportRepository->filename($payload['chat']) . '"');
    echo $exportRepository->toMarkdown($payload);
    exit;
}

if (($_GET['action'] ?? '') === 'export_pdf') {
    $exportChatId = (int) ($_GET['chat_id'] ?? 0);
    $exportRepository = new SqliteChatExportRepository($pdo);
    $payload = $exportRepository->markdownPayload($exportChatId);
    $chartImages = [];

    if ($payload === null) {
        http_response_code(404);
        echo 'Conversa não encontrada.';
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $postedImages = json_decode((string) ($_POST['chart_images'] ?? '[]'), true);

        if (is_array($postedImages)) {
            $chartImages = array_values(array_filter($postedImages, 'is_string'));
        }
    }

    $pdfExportService = new PdfExportService(__DIR__ . '/views/pdf/chat_template.php');

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdfExportService->filename($payload['chat']) . '"');
    echo $pdfExportService->render($payload, $chartImages);
    exit;
}

if (($_GET['action'] ?? '') === 'chat_data') {
    $requestedChatId = (int) ($_GET['chat_id'] ?? 0);

    if ($requestedChatId <= 0 || !SqliteConversationRepository::exists($pdo, $requestedChatId)) {
        jsonResponse([
            'success' => false,
            'error' => 'Conversa não encontrada.',
        ], 404);
    }

    $requestedConversationRepository = new SqliteConversationRepository(
        $pdo,
        $contextWindowService,
        $requestedChatId,
        $defaultModel
    );
    $requestedSystemPrompt = $requestedConversationRepository->systemPrompt($config->defaultSystemPrompt);
    $requestedMessages = $requestedConversationRepository->messages();
    $requestedPersona = $personaRepository->activeForChat($requestedChatId);

    jsonResponse([
        'success' => true,
        'chat' => $chatHistoryRepository->find($requestedChatId),
        'messages' => $requestedMessages,
        'active_persona' => $requestedPersona,
        'context_usage' => $contextWindowService->usage(
            $contextWindowService->withSystemPrompt($requestedSystemPrompt, $requestedMessages)
        ),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'new_chat') {
    try {
        $currentChatId = (int) ($_POST['chat_id'] ?? 0);
        $selectedModel = trim((string) ($_POST['model'] ?? $defaultModel));

        if ($selectedModel === '' || ($availableModels && !in_array($selectedModel, $availableModels, true))) {
            $selectedModel = $defaultModel;
        }

        $activePersona = $personaRepository->activeForChat($currentChatId);
        $newChatId = SqliteConversationRepository::createChat(
            $pdo,
            $selectedModel,
            (string) $activePersona['prompt_content'],
            (int) $activePersona['id']
        );

        jsonResponse([
            'success' => true,
            'chat' => $chatHistoryRepository->find($newChatId),
            'messages' => [],
            'active_persona' => $personaRepository->activeForChat($newChatId),
            'chats' => $chatHistoryRepository->all(),
            'context_usage' => $contextWindowService->usage(
                $contextWindowService->withSystemPrompt((string) $activePersona['prompt_content'], [])
            ),
        ]);
    } catch (Throwable $error) {
        jsonResponse([
            'success' => false,
            'error' => $error->getMessage(),
        ], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete_chat') {
    $deleteChatId = (int) ($_POST['chat_id'] ?? 0);

    if (!$chatHistoryRepository->delete($deleteChatId)) {
        jsonResponse([
            'success' => false,
            'error' => 'Conversa não encontrada para exclusão.',
        ], 404);
    }

    jsonResponse([
        'success' => true,
        'deleted_chat_id' => $deleteChatId,
        'chats' => $chatHistoryRepository->all(),
    ]);
}

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

if (in_array(($_GET['action'] ?? ''), ['rag_documents', 'rag_documents_list'], true)) {
    jsonResponse([
        'success' => true,
        'documents' => $documentChunkRepository->sources(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'rag_ingest') {
    try {
        $upload = uploadedRagFile();
        $result = $ragIngestionService->ingest(
            (string) $upload['name'],
            (string) $upload['content']
        );

        jsonResponse([
            'success' => true,
            'document' => $result,
            'documents' => $documentChunkRepository->sources(),
        ]);
    } catch (Throwable $error) {
        jsonResponse([
            'success' => false,
            'error' => $error->getMessage(),
        ], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_GET['action'] ?? ''), ['rag_delete', 'rag_document_delete'], true)) {
    try {
        $deleted = $documentChunkRepository->deleteDocument((int) ($_POST['document_id'] ?? 0));

        if (!$deleted) {
            throw new RuntimeException('Documento não encontrado para exclusão.');
        }

        jsonResponse([
            'success' => true,
            'documents' => $documentChunkRepository->sources(),
        ]);
    } catch (Throwable $error) {
        jsonResponse([
            'success' => false,
            'error' => $error->getMessage(),
        ], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'plugin_toggle') {
    try {
        $pluginManager->setActive(
            (string) ($_POST['plugin'] ?? ''),
            ($_POST['active'] ?? '0') === '1'
        );

        jsonResponse([
            'success' => true,
            'plugins' => $pluginManager->all(),
            'active_plugins' => $pluginManager->activePlugins(),
            'active_plugin_prompts' => $pluginManager->activePrompts(),
        ]);
    } catch (Throwable $error) {
        jsonResponse([
            'success' => false,
            'error' => $error->getMessage(),
        ], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'data_insights') {
    try {
        if (!$pluginManager->isActive('data_analyst')) {
            throw new RuntimeException('Ative o plugin de Análise de Dados antes de inspecionar arquivos.');
        }

        $selectedModel = trim((string) ($_POST['model'] ?? $defaultModel));

        if ($selectedModel === '' || ($availableModels && !in_array($selectedModel, $availableModels, true))) {
            throw new RuntimeException('Modelo inválido ou indisponível no Ollama local.');
        }

        $ragDocumentIds = selectedRagDocumentIds();

        if ($ragDocumentIds === []) {
            unset($_SESSION['olliverse_data_analyst_rag_sample']);
            throw new RuntimeException('Selecione pelo menos um documento com conteúdo preparado.');
        }

        $sampleChunks = $documentChunkRepository->sampleChunks(8, $ragDocumentIds);

        if ($sampleChunks === []) {
            unset($_SESSION['olliverse_data_analyst_rag_sample']);
            throw new RuntimeException('O documento selecionado não tem conteúdo preparado.');
        }

        $sample = dataInsightRagSample($sampleChunks);
        $inspectionPrompt = dataInsightInspectionPrompt(
            dataInsightSourceNames($sampleChunks),
            $sample
        );
        $rawInspection = $ollamaClient->generate($selectedModel, $inspectionPrompt);
        $inspection = normalizeDataInsights($rawInspection);
        $_SESSION['olliverse_data_analyst_rag_sample'] = [
            'source_names' => dataInsightSourceNames($sampleChunks),
            'sample' => $sample,
        ];
        unset($_SESSION['olliverse_data_analyst_dataset']);

        jsonResponse([
            'success' => true,
            'sources' => dataInsightSourceNames($sampleChunks),
            'inspection' => $inspection,
        ]);
    } catch (Throwable $error) {
        jsonResponse([
            'success' => false,
            'error' => $error->getMessage(),
        ], 422);
    }
}

if (isset($_GET['new']) || (isset($_GET['clear']) && $_GET['clear'] === '1')) {
    $currentChatId = (int) ($_GET['chat_id'] ?? 0);
    $activePersona = $personaRepository->activeForChat($currentChatId);

    redirectToChat(SqliteConversationRepository::createChat(
        $pdo,
        $defaultModel,
        (string) $activePersona['prompt_content'],
        (int) $activePersona['id']
    ));
}

$chatId = (int) ($_GET['chat_id'] ?? 0);

if ($chatId <= 0 || !SqliteConversationRepository::exists($pdo, $chatId)) {
    $activePersona = $personaRepository->activeForChat(0);

    redirectToChat(SqliteConversationRepository::createChat(
        $pdo,
        $defaultModel,
        (string) $activePersona['prompt_content'],
        (int) $activePersona['id']
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
    $contextWindowService,
    $ragRetrievalService,
    $pluginManager
);
$systemPrompt = $conversationRepository->systemPrompt($config->defaultSystemPrompt);
$personas = $personaRepository->all();
$activePersona = $personaRepository->activeForChat($chatId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['persona_action'])) {
    try {
        $personaAction = (string) $_POST['persona_action'];
        $persona = null;

        if ($personaAction === 'select') {
            $persona = $personaRepository->setChatPersona($chatId, (int) ($_POST['persona_id'] ?? 0));
        } elseif ($personaAction === 'create') {
            $persona = $personaRepository->create(
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                (string) ($_POST['prompt_content'] ?? '')
            );
            $persona = $personaRepository->setChatPersona($chatId, (int) $persona['id']);
        } elseif ($personaAction === 'update') {
            $persona = $personaRepository->update(
                (int) ($_POST['persona_id'] ?? 0),
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                (string) ($_POST['prompt_content'] ?? '')
            );

            if ((int) $activePersona['id'] === (int) $persona['id']) {
                $persona = $personaRepository->setChatPersona($chatId, (int) $persona['id']);
            }
        } elseif ($personaAction === 'delete') {
            $personaRepository->delete((int) ($_POST['persona_id'] ?? 0));
            $persona = $personaRepository->activeForChat($chatId);
        } else {
            throw new RuntimeException('Ação de persona inválida.');
        }

        jsonResponse([
            'success' => true,
            'persona' => $persona,
            'personas' => $personaRepository->all(),
            'context_usage' => $contextWindowService->usage(
                $contextWindowService->withSystemPrompt(
                    (string) $persona['prompt_content'],
                    $conversationRepository->messages()
                )
            ),
        ]);
    } catch (Throwable $error) {
        jsonResponse([
            'success' => false,
            'error' => $error->getMessage(),
        ], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'prompt_generate') {
    try {
        $selectedModel = trim((string) ($_POST['model'] ?? $defaultModel));

        if ($selectedModel === '' || ($availableModels && !in_array($selectedModel, $availableModels, true))) {
            throw new RuntimeException('Modelo inválido ou indisponível no Ollama local.');
        }

        jsonResponse([
            'success' => true,
            'prompt_content' => $promptGeneratorService->generate(
                $selectedModel,
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['description'] ?? '')
            ),
        ]);
    } catch (Throwable $error) {
        jsonResponse([
            'success' => false,
            'error' => $error->getMessage(),
        ], 422);
    }
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'rag_context') {
    try {
        $prompt = trim((string) ($_POST['prompt'] ?? ''));
        $ragDocumentIds = selectedRagDocumentIds();

        if ($prompt === '') {
            throw new RuntimeException('Mensagem vazia para consultar documentos.');
        }

        if ($ragDocumentIds === []) {
            jsonResponse([
                'success' => true,
                'system_prompt' => $systemPrompt,
                'sources' => [],
            ]);
        }

        $ragChunks = $ragRetrievalService->retrieve(
            $prompt,
            3,
            $ragDocumentIds
        );

        jsonResponse([
            'success' => true,
            'system_prompt' => $ragRetrievalService->augmentSystemPrompt($systemPrompt, $ragChunks),
            'sources' => $ragRetrievalService->metadata($ragChunks),
        ]);
    } catch (Throwable $error) {
        jsonResponse([
            'success' => false,
            'error' => $error->getMessage(),
        ], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'web_ai_persist') {
    try {
        $prompt = trim((string) ($_POST['prompt'] ?? ''));
        $assistantResponse = trim((string) ($_POST['assistant_response'] ?? ''));
        $webAiModel = trim((string) ($_POST['web_ai_model'] ?? 'web_ai'));

        if ($prompt === '' || $assistantResponse === '') {
            throw new RuntimeException('Mensagem Web AI incompleta para persistencia.');
        }

        $messages = $conversationRepository->messages();
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];
        $messages[] = [
            'role' => 'assistant',
            'content' => $assistantResponse,
        ];

        $contextWasTrimmed = $conversationRepository->replaceConversation(
            $messages,
            $systemPrompt,
            'web_ai:' . ($webAiModel !== '' ? $webAiModel : 'browser')
        );

        $persistedMessages = $conversationRepository->messages();

        jsonResponse([
            'success' => true,
            'chat' => $chatHistoryRepository->find($chatId),
            'messages' => $persistedMessages,
            'context_usage' => $contextWindowService->usage(
                $contextWindowService->withSystemPrompt($systemPrompt, $persistedMessages)
            ),
            'context_trimmed' => $contextWasTrimmed,
        ]);
    } catch (Throwable $error) {
        jsonResponse([
            'success' => false,
            'error' => $error->getMessage(),
        ], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prompt'])) {
    $ragDocumentIds = selectedRagDocumentIds();

    $chatStreamHandler->handle(
        trim((string) $_POST['prompt']),
        trim((string) ($_POST['model'] ?? $defaultModel)),
        $availableModels,
        $ragDocumentIds !== [],
        $ragDocumentIds
    );
}

$initialAssistantMessage = 'Olá! O Olliverse local está pronto. O que deseja processar ou refatorar hoje?';
$initialMessages = $conversationRepository->messages();
$initialRagDocuments = $documentChunkRepository->sources();
$initialChatHistory = $chatHistoryRepository->all();
$availablePlugins = $pluginManager->all();
$activePlugins = $pluginManager->activePlugins();
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

/**
 * @param array<string, mixed> $payload
 */
function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @param array<int, array<string, string>> $messages
 */
function generateChatTitle(OllamaClient $ollamaClient, string $model, array $messages): string
{
    $conversationPreview = chatTitleConversationPreview($messages);

    if ($conversationPreview === '') {
        return 'Nova conversa';
    }

    $rawTitle = $ollamaClient->generate($model, implode("\n", [
        'Crie um titulo curto em portugues para esta conversa.',
        'Use apenas 3 a 5 palavras.',
        'Nao use aspas, markdown, pontuacao final ou prefixos como "Titulo:".',
        '',
        'Conversa:',
        $conversationPreview,
        '',
        'Titulo:',
    ]));

    return normalizeChatTitle($rawTitle);
}

/**
 * @param array<int, array<string, string>> $messages
 */
function chatTitleConversationPreview(array $messages): string
{
    $previewLines = [];

    foreach ($messages as $message) {
        $role = ($message['role'] ?? '') === 'assistant' ? 'Assistente' : 'Usuario';
        $content = trim(preg_replace('/\s+/', ' ', (string) ($message['content'] ?? '')) ?? '');

        if ($content === '') {
            continue;
        }

        $previewLines[] = $role . ': ' . shortenPlainText($content, 260);

        if (count($previewLines) >= 4) {
            break;
        }
    }

    return implode("\n", $previewLines);
}

function normalizeChatTitle(string $title): string
{
    $title = preg_replace('/\s+/', ' ', trim($title)) ?? '';
    $title = preg_replace('/^(titulo|título|title)\s*:\s*/iu', '', $title) ?? $title;
    $title = trim($title, "\"'`*_#.:;,- ");
    preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_+-]*/u', $title, $matches);
    $words = array_slice($matches[0] ?? [], 0, 5);

    if ($words === []) {
        return 'Nova conversa';
    }

    return shortenPlainText(implode(' ', $words), 64);
}

function shortenPlainText(string $content, int $limit): string
{
    $content = trim(preg_replace('/\s+/', ' ', $content) ?? $content);

    if (function_exists('mb_strlen') && mb_strlen($content, 'UTF-8') > $limit) {
        return rtrim(mb_substr($content, 0, $limit - 3, 'UTF-8')) . '...';
    }

    if (!function_exists('mb_strlen') && strlen($content) > $limit) {
        return rtrim(substr($content, 0, $limit - 3)) . '...';
    }

    return $content;
}

/**
 * @return array{name: string, content: string}
 */
function uploadedRagFile(): array
{
    $file = $_FILES['document'] ?? null;

    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Envie um arquivo de texto para adicionar.');
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('O arquivo precisa ter até 2 MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if (!is_file($tmpName) || !is_readable($tmpName)) {
        throw new RuntimeException('Não foi possível acessar o arquivo enviado.');
    }

    $content = file_get_contents($tmpName);

    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('Não foi possível ler texto do arquivo.');
    }

    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content) === 1) {
        throw new RuntimeException('Por enquanto, o RAG aceita apenas arquivos de texto.');
    }

    return [
        'name' => basename((string) ($file['name'] ?? 'documento.txt')),
        'content' => $content,
    ];
}

/**
 * @param array<int, array{id: int, document_id: int, source_name: string, content: string, token_count: int}> $chunks
 * @return array<int, string>
 */
function dataInsightSourceNames(array $chunks): array
{
    return array_values(array_unique(array_map(
        static fn (array $chunk): string => (string) $chunk['source_name'],
        $chunks
    )));
}

/**
 * @param array<int, array{id: int, document_id: int, source_name: string, content: string, token_count: int}> $chunks
 */
function dataInsightRagSample(array $chunks): string
{
    $sample = array_map(
        static fn (array $chunk, int $index): string => sprintf(
            "[Amostra %d | Documento %d | %s]\n%s",
            $index + 1,
            $chunk['document_id'],
            $chunk['source_name'],
            trim($chunk['content'])
        ),
        $chunks,
        array_keys($chunks)
    );

    return substr(implode("\n\n---\n\n", $sample), 0, 12000);
}

/**
 * @param array<int, string> $sourceNames
 */
function dataInsightInspectionPrompt(array $sourceNames, string $sample): string
{
    $promptPath = __DIR__ . '/plugins/data_analyst/includes/inspect_prompt.php';
    $basePrompt = is_file($promptPath) ? require $promptPath : '';

    return trim((string) $basePrompt) . "\n\n"
        . "Documentos selecionados: " . implode(', ', $sourceNames) . "\n"
        . "Amostra recuperada do SQLite/RAG:\n"
        . "```text\n{$sample}\n```";
}

/**
 * @return array{summary: string, suggestions: array<int, array{title: string, query: string, chart_type: string}>}
 */
function normalizeDataInsights(string $rawInspection): array
{
    $json = extractJsonObject($rawInspection);
    $payload = json_decode($json, true);

    if (!is_array($payload)) {
        throw new RuntimeException('A inspeção retornou um JSON inválido.');
    }

    $summary = trim((string) ($payload['summary'] ?? ''));
    $suggestions = $payload['suggestions'] ?? [];

    if ($summary === '' || !is_array($suggestions)) {
        throw new RuntimeException('A inspeção não trouxe resumo e sugestões válidas.');
    }

    $normalizedSuggestions = [];

    foreach ($suggestions as $suggestion) {
        if (!is_array($suggestion)) {
            continue;
        }

        $title = trim((string) ($suggestion['title'] ?? ''));
        $query = trim((string) ($suggestion['query'] ?? ''));
        $chartType = trim((string) ($suggestion['chart_type'] ?? 'bar'));

        if ($title === '' || $query === '' || !in_array($chartType, ['bar', 'pie', 'line'], true)) {
            continue;
        }

        $normalizedSuggestions[] = [
            'title' => $title,
            'query' => $query,
            'chart_type' => $chartType,
        ];
    }

    if ($normalizedSuggestions === []) {
        throw new RuntimeException('A inspeção não trouxe sugestões acionáveis.');
    }

    return [
        'summary' => $summary,
        'suggestions' => array_slice($normalizedSuggestions, 0, 4),
    ];
}

function extractJsonObject(string $value): string
{
    $start = strpos($value, '{');

    if ($start === false) {
        return trim($value);
    }

    $depth = 0;
    $inString = false;
    $escaped = false;
    $length = strlen($value);

    for ($index = $start; $index < $length; $index++) {
        $char = $value[$index];

        if ($escaped) {
            $escaped = false;
            continue;
        }

        if ($char === '\\') {
            $escaped = $inString;
            continue;
        }

        if ($char === '"') {
            $inString = !$inString;
            continue;
        }

        if ($inString) {
            continue;
        }

        if ($char === '{') {
            $depth++;
        }

        if ($char === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($value, $start, $index - $start + 1);
            }
        }
    }

    return trim(substr($value, $start));
}

/**
 * @return array<int, int>
 */
function selectedRagDocumentIds(): array
{
    $documentIds = $_POST['rag_document_ids'] ?? [];

    if (!is_array($documentIds)) {
        $documentIds = [$documentIds];
    }

    return array_values(array_unique(array_filter(
        array_map(static fn (mixed $documentId): int => (int) $documentId, $documentIds),
        static fn (int $documentId): bool => $documentId > 0
    )));
}
require __DIR__ . '/views/chat.php';
