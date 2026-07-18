<?php
session_start();

$ollamaBaseUrl = 'http://localhost:11434';
$defaultSystemPrompt = 'Você é um assistente técnico prestativo.';
const CONTEXT_TOKEN_LIMIT = 8000;
const OLLAMA_CONNECT_TIMEOUT = 10;
const OLLAMA_RESPONSE_TIMEOUT = 180;
const MODEL_METADATA_CACHE_TTL = 3600;

function buscarModelosOllama(string $ollamaBaseUrl): array
{
    $ch = curl_init($ollamaBaseUrl . '/api/tags');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);

    if (curl_error($ch)) {
        curl_close($ch);
        return [];
    }

    curl_close($ch);

    $result = json_decode($response, true);

    if (!isset($result['models']) || !is_array($result['models'])) {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn (array $model): string => $model['name'] ?? '',
        $result['models']
    )));
}

function modeloPadrao(array $modelos): string
{
    $preferencias = ['llama3.2:latest', 'llama3.2', 'qwen2.5:0.5b'];

    foreach ($preferencias as $preferencia) {
        if (in_array($preferencia, $modelos, true)) {
            return $preferencia;
        }
    }

    return $modelos[0] ?? 'llama3.2:latest';
}

function executarOllamaShowVerbose(string $modelName): string
{
    $command = 'ollama show --verbose ' . escapeshellarg($modelName) . ' 2>&1';
    $output = shell_exec($command);

    return is_string($output) ? $output : '';
}

function extrairValorVerbose(string $verboseOutput, array $patterns): ?string
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $verboseOutput, $matches) === 1) {
            return trim($matches[1]);
        }
    }

    return null;
}

function bytesParaGb(int|float|null $bytes): ?float
{
    if (!$bytes || $bytes <= 0) {
        return null;
    }

    return round($bytes / 1024 / 1024 / 1024, 1);
}

function parseTamanhoParaGb(?string $size): ?float
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
        default => bytesParaGb($value),
    };
}

function buscarTamanhoModeloOllama(string $ollamaBaseUrl, string $modelName): ?float
{
    $ch = curl_init($ollamaBaseUrl . '/api/tags');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);

    if (curl_error($ch)) {
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    $result = json_decode($response, true);

    if (!isset($result['models']) || !is_array($result['models'])) {
        return null;
    }

    foreach ($result['models'] as $model) {
        if (($model['name'] ?? '') === $modelName) {
            return bytesParaGb((float) ($model['size'] ?? 0));
        }
    }

    return null;
}

function parseOllamaShowVerbose(string $modelName, string $verboseOutput, ?float $fallbackSizeGb = null): array
{
    $sizeGb = parseTamanhoParaGb(extrairValorVerbose($verboseOutput, [
        '/^\s*(?:size|model size)\s+([\d.,]+\s*(?:bytes?|kb|kib|mb|mib|gb|gib|tb|tib)?)/mi',
    ])) ?? $fallbackSizeGb;

    $family = extrairValorVerbose($verboseOutput, [
        '/^\s*general\.architecture\s+([^\r\n]+)/mi',
        '/^\s*architecture\s+([^\r\n]+)/mi',
        '/^\s*family\s+([^\r\n]+)/mi',
        '/^\s*famil(?:y|ia)\s+([^\r\n]+)/mi',
    ]);

    $contextLength = extrairValorVerbose($verboseOutput, [
        '/^\s*(?:context length|context_length)\s+(\d+)/mi',
        '/^\s*[a-z0-9_.-]+\.context_length\s+(\d+)/mi',
    ]);

    $quantization = extrairValorVerbose($verboseOutput, [
        '/^\s*quantization\s+([A-Z0-9_]+)/mi',
        '/^\s*general\.file_type\s+([^\r\n]+)/mi',
    ]);

    return [
        'model' => $modelName,
        'size_gb' => $sizeGb,
        'family' => $family ?: null,
        'context_length' => $contextLength !== null ? (int) $contextLength : null,
        'quantization' => $quantization ?: null,
    ];
}

function buscarMetadadosModelo(string $ollamaBaseUrl, string $modelName): array
{
    if (!isset($_SESSION['ollama_model_metadata_cache']) || !is_array($_SESSION['ollama_model_metadata_cache'])) {
        $_SESSION['ollama_model_metadata_cache'] = [];
    }

    $cached = $_SESSION['ollama_model_metadata_cache'][$modelName] ?? null;

    if (is_array($cached) && time() - (int) ($cached['cached_at'] ?? 0) < MODEL_METADATA_CACHE_TTL) {
        return $cached['metadata'];
    }

    $fallbackSizeGb = buscarTamanhoModeloOllama($ollamaBaseUrl, $modelName);
    $verboseOutput = executarOllamaShowVerbose($modelName);
    $metadata = parseOllamaShowVerbose($modelName, $verboseOutput, $fallbackSizeGb);

    $_SESSION['ollama_model_metadata_cache'][$modelName] = [
        'cached_at' => time(),
        'metadata' => $metadata,
    ];

    return $metadata;
}

function iconeEnviar(): string
{
    return iconSvg('arrow-up');
}

function iconSvg(string $name): string
{
    $icons = [
        'arrow-up' => '<path d="m5 12 7-7 7 7"></path><path d="M12 19V5"></path>',
        'info' => '<circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path>',
        'settings' => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831 2.34 2.34 0 0 1 2.33-4.033 2.34 2.34 0 0 0 3.319-1.915"></path><circle cx="12" cy="12" r="3"></circle>',
        'x' => '<path d="M18 6 6 18"></path><path d="m6 6 12 12"></path>',
    ];

    return '<svg aria-hidden="true" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24">' . ($icons[$name] ?? $icons['arrow-up']) . '</svg>';
}

function somenteHistoricoConversacional(array $messages): array
{
    return array_values(array_filter(
        $messages,
        static fn (array $message): bool => in_array($message['role'] ?? '', ['user', 'assistant'], true)
    ));
}

function estimarTokensHistorico(array $messages): int
{
    $totalCharacters = 0;

    foreach ($messages as $message) {
        $content = (string) ($message['content'] ?? '');
        $totalCharacters += function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    }

    return (int) ceil($totalCharacters / 4);
}

function contextoUso(array $messages): array
{
    $tokens = estimarTokensHistorico($messages);

    return [
        'tokens' => $tokens,
        'limit' => CONTEXT_TOKEN_LIMIT,
        'percentage' => min(100, (int) round(($tokens / CONTEXT_TOKEN_LIMIT) * 100)),
    ];
}

function limparHistoricoExcedente(array &$conversationMessages, string $systemPrompt): bool
{
    $trimmed = false;

    do {
        $messagesForContext = array_merge(
            [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
            ],
            $conversationMessages
        );

        if (estimarTokensHistorico($messagesForContext) <= CONTEXT_TOKEN_LIMIT) {
            return $trimmed;
        }

        if (count($conversationMessages) <= 1) {
            return $trimmed;
        }

        array_shift($conversationMessages);
        $trimmed = true;
    } while (true);
}

function erroJanelaContexto(string $error): bool
{
    return preg_match('/context|window|token|exceed|exceeded|too long|maximum/i', $error) === 1;
}

function aplicarLimiteRequisicaoOllama(): void
{
    ini_set('max_execution_time', (string) OLLAMA_RESPONSE_TIMEOUT);

    if (function_exists('set_time_limit')) {
        set_time_limit(OLLAMA_RESPONSE_TIMEOUT);
    }
}

function emitirNdjson(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
    flush();
}

function mensagemErroTecnico(string $message): string
{
    if (preg_match('/maximum execution time|execution time|timed out|timeout/i', $message) === 1) {
        return 'A resposta demorou mais que o limite configurado. Tente novamente ou reduza o prompt.';
    }

    return trim(strip_tags($message)) ?: 'Erro interno ao processar a resposta.';
}

$availableModels = buscarModelosOllama($ollamaBaseUrl);
$defaultModel = modeloPadrao($availableModels);

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

    echo json_encode(buscarMetadadosModelo($ollamaBaseUrl, $selectedModel), JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    unset($_SESSION['ollama_chat_messages']);
    unset($_SESSION['system_prompt']);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

if (!isset($_SESSION['ollama_chat_messages']) || !is_array($_SESSION['ollama_chat_messages'])) {
    $_SESSION['ollama_chat_messages'] = [];
} else {
    $_SESSION['ollama_chat_messages'] = somenteHistoricoConversacional($_SESSION['ollama_chat_messages']);
}

if (!isset($_SESSION['system_prompt']) || trim((string) $_SESSION['system_prompt']) === '') {
    $_SESSION['system_prompt'] = $defaultSystemPrompt;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['system_prompt'])) {
    $systemPrompt = trim($_POST['system_prompt']);
    $_SESSION['system_prompt'] = $systemPrompt !== '' ? $systemPrompt : $defaultSystemPrompt;

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'system_prompt' => $_SESSION['system_prompt'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prompt'])) {
    ini_set('display_errors', '0');
    aplicarLimiteRequisicaoOllama();

    $url = $ollamaBaseUrl . '/api/chat';
    $prompt = trim($_POST['prompt']);
    $selectedModel = trim($_POST['model'] ?? $defaultModel);
    $assistantResponse = '';
    $streamBuffer = '';
    $headersSent = false;

    set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });

    register_shutdown_function(static function () use (&$headersSent): void {
        $error = error_get_last();

        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        if (!$headersSent) {
            header('Content-Type: application/x-ndjson; charset=UTF-8');
            header('Cache-Control: no-cache');
            $headersSent = true;
        }

        emitirNdjson([
            'type' => 'error',
            'message' => mensagemErroTecnico($error['message']),
            'context_reset' => false,
        ]);
    });

    try {

    if ($prompt === '') {
        http_response_code(422);
        echo 'Mensagem vazia.';
        exit;
    }

    if ($availableModels && !in_array($selectedModel, $availableModels, true)) {
        http_response_code(400);
        echo 'Modelo selecionado não está disponível no Ollama local.';
        exit;
    }

    $systemPrompt = $_SESSION['system_prompt'];
    $conversationMessages = $_SESSION['ollama_chat_messages'];
    $conversationMessages[] = [
        'role' => 'user',
        'content' => $prompt,
    ];

    $contextWasTrimmed = limparHistoricoExcedente($conversationMessages, $systemPrompt);
    $messagesForContext = array_merge(
        [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
        ],
        $conversationMessages
    );

    $data = [
        'model'  => $selectedModel,
        'messages' => $messagesForContext,
        'stream' => true,
    ];

    header('Content-Type: application/x-ndjson; charset=UTF-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    $headersSent = true;

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, OLLAMA_CONNECT_TIMEOUT);
    curl_setopt($ch, CURLOPT_TIMEOUT, OLLAMA_RESPONSE_TIMEOUT);
    $streamError = '';

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use (&$assistantResponse, &$streamBuffer, &$streamError): int {
        $streamBuffer .= $chunk;

        while (($lineEnd = strpos($streamBuffer, "\n")) !== false) {
            $line = trim(substr($streamBuffer, 0, $lineEnd));
            $streamBuffer = substr($streamBuffer, $lineEnd + 1);

            if ($line === '') {
                continue;
            }

            $result = json_decode($line, true);
            if (!is_array($result)) {
                continue;
            }

            $error = $result['error'] ?? '';

            if ($error !== '') {
                $streamError = (string) $error;
                continue;
            }

            $content = $result['message']['content'] ?? '';

            if ($content !== '') {
                $assistantResponse .= $content;
                emitirNdjson([
                    'type' => 'chunk',
                    'content' => $content,
                ]);
            }
        }

        return strlen($chunk);
    });

    curl_exec($ch);
    $curlError = curl_error($ch);

    if (trim($streamBuffer) !== '') {
        $result = json_decode(trim($streamBuffer), true);
        if (!is_array($result)) {
            $result = [];
        }

        $error = $result['error'] ?? '';

        if ($error !== '') {
            $streamError = (string) $error;
        }

        $content = $result['message']['content'] ?? '';

        if ($content !== '') {
            $assistantResponse .= $content;
            emitirNdjson([
                'type' => 'chunk',
                'content' => $content,
            ]);
        }
    }
    
    curl_close($ch);

    $requestError = $curlError !== '' ? $curlError : $streamError;

    if ($requestError !== '') {
        if (erroJanelaContexto($requestError)) {
            $_SESSION['ollama_chat_messages'] = [];

            emitirNdjson([
                'type' => 'error',
                'message' => 'O contexto ficou grande demais e foi resetado automaticamente para manter a fluidez.',
                'context_reset' => true,
                'context_usage' => contextoUso([
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                ]),
            ]);
            exit;
        }

        emitirNdjson([
            'type' => 'error',
            'message' => mensagemErroTecnico($requestError),
            'context_reset' => false,
            'context_usage' => contextoUso($messagesForContext),
        ]);
        exit;
    }

    if ($assistantResponse === '') {
        emitirNdjson([
            'type' => 'error',
            'message' => 'A IA não retornou conteúdo.',
            'context_reset' => false,
            'context_usage' => contextoUso($messagesForContext),
        ]);
        exit;
    }

    $conversationMessages[] = [
        'role' => 'assistant',
        'content' => $assistantResponse,
    ];

    $contextWasTrimmed = limparHistoricoExcedente($conversationMessages, $systemPrompt) || $contextWasTrimmed;

    $_SESSION['ollama_chat_messages'] = $conversationMessages;

    emitirNdjson([
        'type' => 'meta',
        'context_usage' => contextoUso(array_merge(
            [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
            ],
            $conversationMessages
        )),
        'context_trimmed' => $contextWasTrimmed,
    ]);

    exit;
    } catch (Throwable $error) {
        if (!$headersSent) {
            header('Content-Type: application/x-ndjson; charset=UTF-8');
            header('Cache-Control: no-cache');
            $headersSent = true;
        }

        emitirNdjson([
            'type' => 'error',
            'message' => mensagemErroTecnico($error->getMessage()),
            'context_reset' => false,
            'context_usage' => contextoUso(array_merge(
                [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt ?? ($_SESSION['system_prompt'] ?? ''),
                    ],
                ],
                $conversationMessages ?? ($_SESSION['ollama_chat_messages'] ?? [])
            )),
        ]);
        exit;
    }
}

$initialAssistantMessage = 'Olá! O Olliverse local está pronto. O que deseja processar ou refatorar hoje?';
$initialContextUsage = contextoUso(array_merge(
    [
        [
            'role' => 'system',
            'content' => $_SESSION['system_prompt'],
        ],
    ],
    $_SESSION['ollama_chat_messages']
));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Olliverse</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/highlight.js/styles/github-dark.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
        }

        body {
            background-color: #121214;
            color: #e1e1e6;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            padding: 20px;
        }

        .chat-container {
            width: 100%;
            max-width: 800px;
            height: 85vh;
            background-color: #1e1e24;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid #29292e;
        }

        .chat-header {
            padding: 16px 20px;
            background-color: #29292e;
            border-bottom: 1px solid #323238;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .chat-header h1 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #04d361; /* Verde estilo terminal hacker/moderno */
            white-space: nowrap;
        }

        .model-field {
            align-items: center;
            display: flex;
            gap: 8px;
            min-width: 250px;
            position: relative;
        }

        .model-field label {
            color: #a8a8b3;
            font-size: 0.78rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .model-picker {
            flex: 1;
            min-width: 0;
            position: relative;
        }

        .model-menu-button {
            align-items: center;
            background-color: #323238;
            border: 1px solid #3f3f46;
            border-radius: 6px;
            color: #a8a8b3;
            cursor: pointer;
            display: flex;
            font-family: monospace;
            font-size: 0.78rem;
            justify-content: space-between;
            min-width: 150px;
            outline: none;
            padding: 7px 8px;
            width: 100%;
        }

        .model-menu-button::after {
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 5px solid currentColor;
            content: '';
            flex: 0 0 auto;
            margin-left: 10px;
        }

        .model-menu-button:focus,
        .model-picker.open .model-menu-button {
            border-color: #04d361;
            color: #e1e1e6;
        }

        .model-menu-button:disabled {
            color: #7c7c8a;
            cursor: not-allowed;
            opacity: 0.75;
        }

        .model-menu-list {
            background-color: #25252b;
            border: 1px solid #3f3f46;
            border-radius: 6px;
            box-shadow: 0 18px 38px rgba(0, 0, 0, 0.36);
            display: none;
            left: 0;
            max-height: 240px;
            overflow-y: auto;
            padding: 6px;
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            z-index: 30;
        }

        .model-picker.open .model-menu-list {
            display: block;
        }

        .model-option {
            background: transparent;
            border: 0;
            border-radius: 5px;
            color: #c4c4cc;
            cursor: pointer;
            display: block;
            font-family: monospace;
            font-size: 0.78rem;
            overflow-wrap: anywhere;
            padding: 8px;
            text-align: left;
            width: 100%;
        }

        .model-option:hover,
        .model-option:focus,
        .model-option.active {
            background-color: rgba(4, 211, 97, 0.1);
            color: #e1e1e6;
            outline: none;
        }

        .model-info-btn {
            align-items: center;
            background: transparent;
            border: 1px solid #3f3f46;
            border-radius: 6px;
            color: #c4c4cc;
            cursor: pointer;
            display: inline-flex;
            flex: 0 0 34px;
            height: 34px;
            justify-content: center;
            outline: none;
            transition: border-color 0.2s, color 0.2s, background-color 0.2s;
            width: 34px;
        }

        .model-info-btn:hover,
        .model-info-btn:focus {
            background-color: rgba(4, 211, 97, 0.08);
            border-color: #04d361;
            color: #04d361;
        }

        .model-info-btn:disabled {
            color: #7c7c8a;
            cursor: not-allowed;
            opacity: 0.75;
        }

        .model-info-btn svg {
            height: 17px;
            width: 17px;
        }

        .model-details-title {
            color: #e1e1e6;
            font-family: monospace;
            font-size: 0.9rem;
            overflow-wrap: anywhere;
        }

        .model-details-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .model-details-item {
            background-color: #25252b;
            border: 1px solid #323238;
            border-radius: 8px;
            min-width: 0;
            padding: 12px;
        }

        .model-details-label {
            color: #7c7c8a;
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .model-details-value {
            color: #c4c4cc;
            display: block;
            font-family: monospace;
            font-size: 0.95rem;
            margin-top: 2px;
            overflow-wrap: anywhere;
        }

        .model-details-status {
            color: #7c7c8a;
            font-size: 0.85rem;
        }

        .model-details-status.error {
            color: #f87171;
        }

        .new-chat-btn {
            background: transparent;
            color: #e1e1e6;
            border: 1px solid #3f3f46;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: border-color 0.2s, color 0.2s, background-color 0.2s;
            white-space: nowrap;
        }

        .new-chat-btn:hover,
        .new-chat-btn:focus {
            background-color: rgba(4, 211, 97, 0.08);
            border-color: #04d361;
            color: #04d361;
            outline: none;
        }

        .new-chat-btn:disabled {
            color: #7c7c8a;
            border-color: #323238;
            cursor: not-allowed;
            background: transparent;
        }

        .header-actions {
            align-items: center;
            display: flex;
            gap: 8px;
        }

        .config-btn {
            align-items: center;
            background: transparent;
            border: 1px solid #3f3f46;
            border-radius: 6px;
            color: #e1e1e6;
            cursor: pointer;
            display: inline-flex;
            height: 36px;
            justify-content: center;
            transition: border-color 0.2s, color 0.2s, background-color 0.2s;
            width: 36px;
        }

        .config-btn:hover,
        .config-btn:focus {
            background-color: rgba(4, 211, 97, 0.08);
            border-color: #04d361;
            color: #04d361;
            outline: none;
        }

        .config-btn svg {
            height: 17px;
            width: 17px;
        }

        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* Custom Scrollbar */
        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }
        .chat-messages::-webkit-scrollbar-track {
            background: #1e1e24;
        }
        .chat-messages::-webkit-scrollbar-thumb {
            background: #323238;
            border-radius: 3px;
        }

        .message-group {
            max-width: 85%;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .message-group.user {
            align-items: flex-end;
            align-self: flex-end;
        }

        .message-group.assistant {
            align-items: flex-start;
            align-self: flex-start;
        }

        .message-group.error {
            align-items: center;
            align-self: center;
        }

        .message {
            max-width: 100%;
            padding: 12px 16px;
            border-radius: 8px;
            line-height: 1.5;
            font-size: 0.95rem;
            word-break: break-word;
            white-space: pre-wrap;
        }

        .message.user {
            background-color: #04d361;
            color: #0a0a0c;
            align-self: flex-end;
            border-bottom-right-radius: 2px;
        }

        .message.user .message-text {
            display: block;
        }

        .message-actions {
            display: flex;
            gap: 6px;
        }

        .message-action-btn {
            align-items: center;
            background-color: #202027;
            border: 1px solid #3f3f46;
            border-radius: 6px;
            color: #a8a8b3;
            cursor: pointer;
            display: inline-flex;
            height: 30px;
            justify-content: center;
            opacity: 0.8;
            position: relative;
            transition: border-color 0.2s, color 0.2s, opacity 0.2s, transform 0.2s;
            width: 30px;
        }

        .message-action-btn:hover,
        .message-action-btn:focus {
            border-color: #04d361;
            color: #04d361;
            opacity: 1;
            outline: none;
            transform: translateY(-1px);
        }

        .message-action-btn.copied {
            border-color: #04d361;
            color: #04d361;
        }

        .message-action-btn svg {
            height: 16px;
            width: 16px;
        }

        .message-action-btn::after {
            background-color: #121214;
            border: 1px solid #323238;
            border-radius: 6px;
            bottom: calc(100% + 8px);
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.28);
            color: #e1e1e6;
            content: attr(data-tooltip);
            font-size: 0.74rem;
            font-weight: 600;
            left: 50%;
            line-height: 1.2;
            opacity: 0;
            padding: 7px 9px;
            pointer-events: none;
            position: absolute;
            transform: translate(-50%, 4px);
            transition: opacity 0.18s, transform 0.18s;
            white-space: nowrap;
            z-index: 3;
        }

        .message-action-btn::before {
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-top: 6px solid #121214;
            bottom: calc(100% + 3px);
            content: '';
            left: 50%;
            opacity: 0;
            pointer-events: none;
            position: absolute;
            transform: translate(-50%, 4px);
            transition: opacity 0.18s, transform 0.18s;
            z-index: 4;
        }

        .message-action-btn:hover::after,
        .message-action-btn:focus::after,
        .message-action-btn:hover::before,
        .message-action-btn:focus::before {
            opacity: 1;
            transform: translate(-50%, 0);
        }

        .message-action-btn::before,
        .message-action-btn::after {
            display: none;
        }

        .floating-tooltip {
            background-color: #121214;
            border: 1px solid #323238;
            border-radius: 6px;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.36);
            color: #e1e1e6;
            font-size: 0.74rem;
            font-weight: 600;
            left: 0;
            line-height: 1.2;
            opacity: 0;
            padding: 7px 9px;
            pointer-events: none;
            position: fixed;
            top: 0;
            transform: translate(-50%, 4px);
            transition: opacity 0.18s, transform 0.18s;
            white-space: nowrap;
            z-index: 9999;
        }

        .floating-tooltip.visible {
            opacity: 1;
            transform: translate(-50%, 0);
        }

        .message.assistant {
            background-color: #29292e;
            color: #e1e1e6;
            align-self: flex-start;
            border-bottom-left-radius: 2px;
            border: 1px solid #323238;
            white-space: normal;
        }

        .message.error {
            background-color: #f75a68;
            color: #fff;
            align-self: center;
            text-align: center;
            font-size: 0.85rem;
        }

        .chat-messages > .message {
            max-width: 85%;
        }

        .message.assistant p,
        .message.assistant ul,
        .message.assistant ol {
            margin-bottom: 12px;
        }

        .message.assistant p:last-child,
        .message.assistant ul:last-child,
        .message.assistant ol:last-child,
        .message.assistant pre:last-child {
            margin-bottom: 0;
        }

        .message.assistant ul,
        .message.assistant ol {
            padding-left: 22px;
        }

        .message.assistant code {
            background-color: #121214;
            border: 1px solid #323238;
            border-radius: 4px;
            color: #e1e1e6;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 0.9em;
            padding: 2px 5px;
        }

        .message.assistant pre {
            background-color: #121214;
            border: 1px solid #323238;
            border-radius: 8px;
            margin: 12px 0;
            overflow-x: auto;
            padding: 14px;
        }

        .message.assistant pre code {
            background: transparent;
            border: none;
            border-radius: 0;
            color: inherit;
            display: block;
            font-size: 0.9rem;
            line-height: 1.6;
            padding: 0;
            white-space: pre;
        }

        .message.assistant a {
            color: #04d361;
        }

        .code-keyword {
            color: #ff7b72;
        }

        .code-string {
            color: #a5d6ff;
        }

        .code-comment {
            color: #8b949e;
            font-style: italic;
        }

        .code-number {
            color: #79c0ff;
        }

        .chat-input-area {
            padding: 16px 20px;
            background-color: #1e1e24;
            border-top: 1px solid #29292e;
        }

        .context-status {
            align-items: center;
            color: #a8a8b3;
            display: flex;
            font-size: 0.78rem;
            gap: 10px;
            margin-bottom: 12px;
        }

        .context-bar {
            background-color: #121214;
            border: 1px solid #29292e;
            border-radius: 999px;
            flex: 1;
            height: 8px;
            min-width: 80px;
            overflow: hidden;
        }

        .context-bar-fill {
            background-color: #04d361;
            height: 100%;
            transition: background-color 0.2s, width 0.2s;
            width: 0%;
        }

        .context-bar-fill.warning {
            background-color: #fba94c;
        }

        .context-bar-fill.danger {
            background-color: #f75a68;
        }

        .context-label {
            font-weight: 650;
            min-width: 96px;
            text-align: right;
            white-space: nowrap;
        }

        .input-form {
            display: flex;
            gap: 12px;
        }

        .chat-input {
            flex: 1;
            background-color: #121214;
            border: 1px solid #29292e;
            color: #e1e1e6;
            padding: 14px 16px;
            border-radius: 6px;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .chat-input:focus {
            border-color: #04d361;
        }

        .send-btn {
            align-items: center;
            background-color: #04d361;
            border: none;
            border-radius: 50%;
            color: #0a0a0c;
            cursor: pointer;
            display: inline-flex;
            flex: 0 0 48px;
            height: 48px;
            justify-content: center;
            transition: background-color 0.2s;
            width: 48px;
        }

        .send-btn:hover {
            background-color: #05e66b;
        }

        .send-btn:focus {
            box-shadow: 0 0 0 3px rgba(4, 211, 97, 0.24);
            outline: none;
        }

        .send-btn:disabled {
            background-color: #29292e;
            color: #7c7c8a;
            cursor: not-allowed;
        }

        .send-btn svg {
            height: 20px;
            width: 20px;
        }

        .modal-backdrop {
            align-items: center;
            background-color: rgba(10, 10, 12, 0.72);
            display: none;
            inset: 0;
            justify-content: center;
            padding: 20px;
            position: fixed;
            z-index: 9000;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .skill-modal {
            background-color: #1e1e24;
            border: 1px solid #323238;
            border-radius: 8px;
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.45);
            max-width: 560px;
            width: 100%;
        }

        .modal-header,
        .modal-footer {
            align-items: center;
            display: flex;
            justify-content: space-between;
            padding: 16px 18px;
        }

        .modal-header {
            border-bottom: 1px solid #29292e;
        }

        .modal-header h2 {
            color: #e1e1e6;
            font-size: 1rem;
            font-weight: 650;
        }

        .modal-close-btn {
            align-items: center;
            background: transparent;
            border: 1px solid #3f3f46;
            border-radius: 6px;
            color: #a8a8b3;
            cursor: pointer;
            display: inline-flex;
            height: 32px;
            justify-content: center;
            width: 32px;
        }

        .modal-close-btn:hover,
        .modal-close-btn:focus {
            border-color: #04d361;
            color: #04d361;
            outline: none;
        }

        .modal-close-btn svg {
            height: 16px;
            width: 16px;
        }

        .modal-body {
            display: grid;
            gap: 14px;
            padding: 18px;
        }

        .skill-field {
            display: grid;
            gap: 8px;
        }

        .skill-field label {
            color: #a8a8b3;
            font-size: 0.82rem;
            font-weight: 650;
        }

        .skill-select,
        .skill-textarea {
            background-color: #121214;
            border: 1px solid #323238;
            border-radius: 6px;
            color: #e1e1e6;
            font-size: 0.92rem;
            outline: none;
            padding: 11px 12px;
            width: 100%;
        }

        .skill-select:focus,
        .skill-textarea:focus {
            border-color: #04d361;
        }

        .skill-textarea {
            line-height: 1.5;
            min-height: 130px;
            resize: vertical;
        }

        .skill-status {
            color: #04d361;
            font-size: 0.82rem;
            min-height: 1rem;
        }

        .modal-footer {
            border-top: 1px solid #29292e;
            justify-content: flex-end;
        }

        .save-config-btn {
            background-color: #04d361;
            border: none;
            border-radius: 6px;
            color: #0a0a0c;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 700;
            padding: 10px 14px;
        }

        .save-config-btn:hover,
        .save-config-btn:focus {
            background-color: #05e66b;
            outline: none;
        }

        .typing-indicator {
            display: flex;
            gap: 4px;
            align-items: center;
            padding: 4px 8px;
        }

        .typing-dot {
            width: 6px;
            height: 6px;
            background-color: #a8a8b3;
            border-radius: 50%;
            animation: bounce 1.4s infinite ease-in-out both;
        }

        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }

        @keyframes bounce {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1.0); }
        }

        @media (max-width: 560px) {
            body {
                padding: 12px;
            }

            .chat-container {
                height: 92vh;
            }

            .chat-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .header-title {
                flex-wrap: wrap;
            }

            .new-chat-btn {
                width: 100%;
            }

            .header-actions {
                width: 100%;
            }

            .config-btn {
                flex: 0 0 36px;
            }

            .model-field {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="chat-container">
    <div class="chat-header">
        <div class="header-title">
            <h1>Olliverse</h1>
            <div class="model-field">
                <label for="modelMenuButton">Modelo</label>
                <input type="hidden" id="modelSelect" value="<?php echo htmlspecialchars($defaultModel, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="model-picker" id="modelPicker">
                    <button type="button" id="modelMenuButton" class="model-menu-button" <?php echo $availableModels ? '' : 'disabled'; ?>>
                        <span id="selectedModelLabel"><?php echo htmlspecialchars($availableModels ? $defaultModel : 'Nenhum modelo encontrado', ENT_QUOTES, 'UTF-8'); ?></span>
                    </button>
                    <div id="modelMenuList" class="model-menu-list" role="listbox" aria-labelledby="modelMenuButton">
                    <?php if ($availableModels): ?>
                        <?php foreach ($availableModels as $modelName): ?>
                            <button type="button" class="model-option <?php echo $modelName === $defaultModel ? 'active' : ''; ?>" role="option" data-model="<?php echo htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8'); ?>" aria-selected="<?php echo $modelName === $defaultModel ? 'true' : 'false'; ?>">
                                <?php echo htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <button type="button" class="model-option" disabled>Nenhum modelo encontrado</button>
                    <?php endif; ?>
                    </div>
                </div>
                <button type="button" id="modelInfoBtn" class="model-info-btn" aria-label="Informações do modelo" title="Informações do modelo" <?php echo $availableModels ? '' : 'disabled'; ?>><?php echo iconSvg('info'); ?></button>
            </div>
        </div>
        <div class="header-actions">
            <button type="button" id="settingsBtn" class="config-btn" aria-label="Configurações" title="Configurações"><?php echo iconSvg('settings'); ?></button>
            <button type="button" id="newChatBtn" class="new-chat-btn">+ Nova conversa</button>
        </div>
    </div>

    <div class="chat-messages" id="chatMessages">
        <div class="message assistant"><?php echo htmlspecialchars($initialAssistantMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>

    <div class="chat-input-area">
        <div class="context-status" aria-live="polite">
            <div class="context-bar" aria-hidden="true">
                <div id="contextBarFill" class="context-bar-fill"></div>
            </div>
            <span id="contextLabel" class="context-label">Contexto 0%</span>
        </div>
        <form class="input-form" id="chatForm">
            <input type="text" id="userInput" class="chat-input" placeholder="Escreva sua mensagem aqui..." autocomplete="off" required>
            <button type="submit" id="sendBtn" class="send-btn" aria-label="Enviar mensagem" title="Enviar mensagem"><?php echo iconeEnviar(); ?></button>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="modelInfoModal" aria-hidden="true">
    <div class="skill-modal" role="dialog" aria-modal="true" aria-labelledby="modelInfoModalTitle">
        <div class="modal-header">
            <h2 id="modelInfoModalTitle">Modelo</h2>
            <button type="button" id="closeModelInfoModalBtn" class="modal-close-btn" aria-label="Fechar informações do modelo"><?php echo iconSvg('x'); ?></button>
        </div>
        <div class="modal-body">
            <div class="model-details-title" id="modelInfoTitle"><?php echo htmlspecialchars($availableModels ? $defaultModel : 'Sem modelo', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="model-details-status" id="modelInfoStatus">Carregando metadados...</div>
            <div class="model-details-grid" id="modelInfoGrid" hidden>
                <div class="model-details-item">
                    <span class="model-details-label">Tamanho</span>
                    <span class="model-details-value" id="modelInfoSize">-</span>
                </div>
                <div class="model-details-item">
                    <span class="model-details-label">Família</span>
                    <span class="model-details-value" id="modelInfoFamily">-</span>
                </div>
                <div class="model-details-item">
                    <span class="model-details-label">Contexto</span>
                    <span class="model-details-value" id="modelInfoContext">-</span>
                </div>
                <div class="model-details-item">
                    <span class="model-details-label">Quantização</span>
                    <span class="model-details-value" id="modelInfoQuantization">-</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="skillModal" aria-hidden="true">
    <div class="skill-modal" role="dialog" aria-modal="true" aria-labelledby="skillModalTitle">
        <div class="modal-header">
            <h2 id="skillModalTitle">Configurações</h2>
            <button type="button" id="closeSkillModalBtn" class="modal-close-btn" aria-label="Fechar configurações"><?php echo iconSvg('x'); ?></button>
        </div>
        <form id="skillForm">
            <div class="modal-body">
                <div class="skill-field">
                    <label for="skillPreset">Skill</label>
                    <select id="skillPreset" class="skill-select">
                        <option value="tecnico">Assistente técnico prestativo</option>
                        <option value="codigo">Assistente de Código</option>
                        <option value="escritor">Escritor</option>
                        <option value="dados">Analista de Dados</option>
                        <option value="custom">Personalizada</option>
                    </select>
                </div>
                <div class="skill-field">
                    <label for="systemPromptInput">System prompt</label>
                    <textarea id="systemPromptInput" class="skill-textarea" name="system_prompt"><?php echo htmlspecialchars($_SESSION['system_prompt'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div id="skillStatus" class="skill-status" aria-live="polite"></div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="save-config-btn">Salvar Configuração</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/highlight.js/highlight.min.js"></script>
<script>
    const initialAssistantMessage = <?php echo json_encode($initialAssistantMessage, JSON_UNESCAPED_UNICODE); ?>;
    const hasAvailableModels = <?php echo json_encode((bool) $availableModels); ?>;
    const initialContextUsage = <?php echo json_encode($initialContextUsage, JSON_UNESCAPED_UNICODE); ?>;
    const skillPresets = {
        tecnico: 'Você é um assistente técnico prestativo.',
        codigo: 'Você é um assistente de código sênior. Ajude com soluções claras, seguras e objetivas, priorizando PHP, arquitetura limpa, depuração cuidadosa e exemplos práticos quando necessário.',
        escritor: 'Você é um escritor cuidadoso. Ajude a revisar, estruturar e melhorar textos com clareza, fluidez, precisão e tom adequado ao público.',
        dados: 'Você é um analista de dados. Ajude a interpretar informações, criar hipóteses, explicar métricas e propor análises com raciocínio estatístico claro.',
    };
    let activeTooltipButton = null;
    const modelMetadataCache = new Map();

    marked.setOptions({ breaks: true });
    updateContextUsage(initialContextUsage);
    initModelPicker();

    document.getElementById('chatForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const inputEl = document.getElementById('userInput');
        const modelSelect = document.getElementById('modelSelect');
        const modelMenuButton = document.getElementById('modelMenuButton');
        const modelInfoBtn = document.getElementById('modelInfoBtn');
        const sendBtn = document.getElementById('sendBtn');
        const newChatBtn = document.getElementById('newChatBtn');
        const messagesContainer = document.getElementById('chatMessages');
        const prompt = inputEl.value.trim();
        const model = modelSelect.value;

        if (!prompt || !model || !hasAvailableModels) return;

        // 1. Adiciona a mensagem do usuário na interface
        appendMessage(prompt, 'user');
        inputEl.value = '';
        
        // Desativa os campos enquanto a IA pensa
        inputEl.disabled = true;
        modelSelect.disabled = true;
        modelMenuButton.disabled = true;
        modelInfoBtn.disabled = true;
        sendBtn.disabled = true;
        newChatBtn.disabled = true;

        const assistantMessage = createStreamingAssistantMessage();
        let assistantText = '';

        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'prompt=' + encodeURIComponent(prompt) + '&model=' + encodeURIComponent(model)
        })
        .then(response => {
            if (!response.ok) throw new Error('Erro na requisição.');
            return streamAssistantResponse(response, assistantMessage, (chunk) => {
                assistantText += chunk;
            }, (payload) => {
                if (payload.type === 'meta' && payload.context_usage) {
                    updateContextUsage(payload.context_usage);
                }

                if (payload.type === 'error') {
                    if (payload.context_usage) {
                        updateContextUsage(payload.context_usage);
                    }

                    throw new Error(payload.message || 'Erro ao processar a resposta.');
                }
            });
        })
        .then(() => {
            finalizeStreamingAssistantMessage(assistantMessage, assistantText);
        })
        .catch(error => {
            assistantMessage.group.remove();
            appendMessage(error.message || 'Erro ao processar a resposta.', 'error');
            console.error(error);
        })
        .finally(() => {
            // Reativa os campos de entrada
            inputEl.disabled = false;
            modelSelect.disabled = !hasAvailableModels;
            modelMenuButton.disabled = !hasAvailableModels;
            modelInfoBtn.disabled = !hasAvailableModels;
            sendBtn.disabled = false;
            newChatBtn.disabled = false;
            inputEl.focus();
            scrollToBottom();
        });
    });

    document.getElementById('newChatBtn').addEventListener('click', function() {
        window.location.href = '?clear=1';
    });

    document.getElementById('modelInfoBtn').addEventListener('click', openModelInfoModal);
    document.getElementById('closeModelInfoModalBtn').addEventListener('click', closeModelInfoModal);
    document.getElementById('modelInfoModal').addEventListener('click', function(event) {
        if (event.target === event.currentTarget) {
            closeModelInfoModal();
        }
    });
    document.getElementById('settingsBtn').addEventListener('click', openSkillModal);
    document.getElementById('closeSkillModalBtn').addEventListener('click', closeSkillModal);
    document.getElementById('skillModal').addEventListener('click', function(event) {
        if (event.target === event.currentTarget) {
            closeSkillModal();
        }
    });
    document.getElementById('skillPreset').addEventListener('change', function(event) {
        const presetValue = event.target.value;

        if (skillPresets[presetValue]) {
            document.getElementById('systemPromptInput').value = skillPresets[presetValue];
        }
    });
    document.getElementById('systemPromptInput').addEventListener('input', function() {
        document.getElementById('skillPreset').value = 'custom';
    });
    document.getElementById('skillForm').addEventListener('submit', function(event) {
        event.preventDefault();
        saveSkillConfig();
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && document.getElementById('skillModal').classList.contains('open')) {
            closeSkillModal();
        }

        if (event.key === 'Escape' && document.getElementById('modelInfoModal').classList.contains('open')) {
            closeModelInfoModal();
        }

        if (event.key === 'Escape') {
            closeModelMenu();
        }
    });

    document.addEventListener('click', function(event) {
        const picker = document.getElementById('modelPicker');

        if (picker && !picker.contains(event.target)) {
            closeModelMenu();
        }
    });

    function initModelPicker() {
        const picker = document.getElementById('modelPicker');
        const menuButton = document.getElementById('modelMenuButton');

        if (!picker || !menuButton) {
            return;
        }

        menuButton.addEventListener('click', function() {
            if (menuButton.disabled) {
                return;
            }

            picker.classList.toggle('open');
        });

        document.querySelectorAll('.model-option[data-model]').forEach((option) => {
            option.addEventListener('click', function() {
                selectModel(option.dataset.model || '');
            });
        });
    }

    function closeModelMenu() {
        const picker = document.getElementById('modelPicker');

        if (picker) {
            picker.classList.remove('open');
        }
    }

    function selectModel(model) {
        if (!model) {
            return;
        }

        document.getElementById('modelSelect').value = model;
        document.getElementById('selectedModelLabel').textContent = model;
        document.getElementById('modelInfoTitle').textContent = model;

        document.querySelectorAll('.model-option[data-model]').forEach((option) => {
            const isSelected = option.dataset.model === model;

            option.classList.toggle('active', isSelected);
            option.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });

        closeModelMenu();

        if (document.getElementById('modelInfoModal').classList.contains('open')) {
            loadModelMetadata(model);
        }
    }

    function openModelInfoModal() {
        const modal = document.getElementById('modelInfoModal');
        const model = document.getElementById('modelSelect').value;

        document.getElementById('modelInfoTitle').textContent = model || 'Sem modelo';
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        loadModelMetadata(model);
        document.getElementById('closeModelInfoModalBtn').focus();
    }

    function closeModelInfoModal() {
        const modal = document.getElementById('modelInfoModal');

        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.getElementById('modelInfoBtn').focus();
    }

    function loadModelMetadata(model) {
        if (!model || !hasAvailableModels) {
            showModelMetadataError('Nenhum modelo disponível.');
            return;
        }

        if (modelMetadataCache.has(model)) {
            renderModelMetadata(modelMetadataCache.get(model));
            return;
        }

        setModelMetadataLoading();

        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('action', 'model_metadata');
        url.searchParams.set('model', model);

        fetch(url.toString(), {
            headers: {
                'Accept': 'application/json',
            },
        })
        .then((response) => {
            if (!response.ok) {
                throw new Error('Não foi possível carregar os metadados.');
            }

            return response.json();
        })
        .then((metadata) => {
            modelMetadataCache.set(model, metadata);
            renderModelMetadata(metadata);
        })
        .catch((error) => {
            showModelMetadataError(error.message || 'Erro ao carregar metadados.');
        });
    }

    function setModelMetadataLoading() {
        const status = document.getElementById('modelInfoStatus');

        document.getElementById('modelInfoGrid').hidden = true;
        status.classList.remove('error');
        status.hidden = false;
        status.textContent = 'Carregando metadados...';
    }

    function renderModelMetadata(metadata) {
        document.getElementById('modelInfoTitle').textContent = metadata.model || document.getElementById('modelSelect').value;
        document.getElementById('modelInfoSize').textContent = formatSizeGb(metadata.size_gb);
        document.getElementById('modelInfoFamily').textContent = metadata.family || '-';
        document.getElementById('modelInfoContext').textContent = formatNumber(metadata.context_length);
        document.getElementById('modelInfoQuantization').textContent = metadata.quantization || '-';

        document.getElementById('modelInfoStatus').hidden = true;
        document.getElementById('modelInfoGrid').hidden = false;
    }

    function showModelMetadataError(message) {
        const status = document.getElementById('modelInfoStatus');

        document.getElementById('modelInfoGrid').hidden = true;
        status.hidden = false;
        status.classList.add('error');
        status.textContent = message;
    }

    function formatSizeGb(sizeGb) {
        const value = Number(sizeGb);

        if (!Number.isFinite(value) || value <= 0) {
            return '-';
        }

        return `${value.toLocaleString('pt-BR', {
            minimumFractionDigits: value < 10 ? 1 : 0,
            maximumFractionDigits: 1,
        })} GB`;
    }

    function formatNumber(value) {
        const number = Number(value);

        if (!Number.isFinite(number) || number <= 0) {
            return '-';
        }

        return number.toLocaleString('pt-BR');
    }

    function openSkillModal() {
        const modal = document.getElementById('skillModal');

        document.getElementById('skillStatus').textContent = '';
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.getElementById('systemPromptInput').focus();
    }

    function closeSkillModal() {
        const modal = document.getElementById('skillModal');

        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.getElementById('settingsBtn').focus();
    }

    function saveSkillConfig() {
        const statusEl = document.getElementById('skillStatus');
        const promptValue = document.getElementById('systemPromptInput').value.trim();

        statusEl.textContent = 'Salvando...';

        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'system_prompt=' + encodeURIComponent(promptValue)
        })
        .then(response => {
            if (!response.ok) throw new Error('Erro ao salvar configuração.');
            return response.json();
        })
        .then(() => {
            statusEl.textContent = 'Configuração salva.';
            window.setTimeout(closeSkillModal, 700);
        })
        .catch(error => {
            statusEl.textContent = error.message;
        });
    }

    function appendMessage(text, sender, renderMarkdown = false) {
        const container = document.getElementById('chatMessages');
        const messageGroup = document.createElement('div');
        const messageDiv = document.createElement('div');

        messageGroup.className = `message-group ${sender}`;
        messageDiv.className = `message ${sender}`;
        messageGroup.appendChild(messageDiv);

        if (sender === 'user') {
            renderUserMessage(messageGroup, messageDiv, text);
        } else if (renderMarkdown) {
            renderAssistantMessage(messageGroup, messageDiv, text);
        } else {
            messageDiv.textContent = text;
        }

        container.appendChild(messageGroup);
        scrollToBottom();
    }

    function createStreamingAssistantMessage() {
        const container = document.getElementById('chatMessages');
        const messageGroup = document.createElement('div');
        const messageDiv = document.createElement('div');

        messageGroup.className = 'message-group assistant';
        messageDiv.className = 'message assistant';
        messageDiv.innerHTML = '<div class="typing-indicator"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div>';
        messageGroup.appendChild(messageDiv);
        container.appendChild(messageGroup);
        scrollToBottom();

        return {
            group: messageGroup,
            message: messageDiv,
        };
    }

    async function streamAssistantResponse(response, assistantMessage, onChunk, onPayload) {
        if (!response.body || !window.TextDecoder) {
            const text = await response.text();

            processNdjsonBuffer(text, onChunk, onPayload, assistantMessage);
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let fullText = '';
        let buffer = '';

        while (true) {
            const { done, value } = await reader.read();

            if (done) {
                break;
            }

            const chunk = decoder.decode(value, { stream: true });

            if (!chunk) {
                continue;
            }

            buffer += chunk;
            buffer = processNdjsonBuffer(buffer, (content) => {
                fullText += content;
                onChunk(content);
                renderAssistantMessageContent(assistantMessage.message, fullText);
            }, onPayload, assistantMessage);
            scrollToBottom();
        }

        const finalChunk = decoder.decode();

        if (finalChunk) {
            buffer += finalChunk;
        }

        if (buffer.trim() !== '') {
            processNdjsonLine(buffer.trim(), (content) => {
                fullText += content;
                onChunk(content);
                renderAssistantMessageContent(assistantMessage.message, fullText);
            }, onPayload);
        }
    }

    function processNdjsonBuffer(buffer, onChunk, onPayload) {
        const lines = buffer.split('\n');
        const remainder = lines.pop() || '';

        lines.forEach((line) => {
            processNdjsonLine(line, onChunk, onPayload);
        });

        return remainder;
    }

    function processNdjsonLine(line, onChunk, onPayload) {
        if (!line.trim()) {
            return;
        }

        let payload;

        try {
            payload = JSON.parse(line);
        } catch (error) {
            throw new Error(`Resposta inesperada do servidor: ${cleanServerMessage(line)}`);
        }

        if (payload.type === 'chunk') {
            onChunk(payload.content || '');
            return;
        }

        onPayload(payload);
    }

    function cleanServerMessage(message) {
        const withoutTags = message
            .replace(/<br\s*\/?>/gi, ' ')
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        return withoutTags || 'conteúdo inválido recebido.';
    }

    function updateContextUsage(contextUsage) {
        const fill = document.getElementById('contextBarFill');
        const label = document.getElementById('contextLabel');
        const percentage = Math.max(0, Math.min(100, Number(contextUsage?.percentage || 0)));
        const tokens = Number(contextUsage?.tokens || 0);
        const limit = Number(contextUsage?.limit || 0);

        fill.style.width = `${percentage}%`;
        fill.classList.toggle('warning', percentage >= 70 && percentage < 90);
        fill.classList.toggle('danger', percentage >= 90);
        label.textContent = limit > 0
            ? `Contexto ${percentage}% (${tokens}/${limit})`
            : `Contexto ${percentage}%`;
    }

    function renderAssistantMessageContent(messageDiv, text) {
        const normalizedText = normalizeCodeMarkdown(text);

        if (!window.marked || !window.DOMPurify) {
            messageDiv.textContent = normalizedText;
            return;
        }

        try {
            messageDiv.innerHTML = DOMPurify.sanitize(marked.parse(normalizedText));

            if (window.hljs) {
                messageDiv.querySelectorAll('pre code').forEach((block) => {
                    hljs.highlightElement(block);
                });
            }
        } catch (error) {
            messageDiv.textContent = normalizedText;
        }
    }

    function finalizeStreamingAssistantMessage(assistantMessage, text) {
        renderAssistantMessageContent(assistantMessage.message, text);

        if (text.trim() !== '') {
            appendCopyResponseButton(assistantMessage.group, text);
        }

        scrollToBottom();
    }

    function renderUserMessage(messageGroup, messageDiv, text) {
        const messageText = document.createElement('span');

        messageText.className = 'message-text';
        messageText.textContent = text;

        messageDiv.appendChild(messageText);
        appendReusePromptButton(messageGroup, text);
    }

    function appendReusePromptButton(messageGroup, text) {
        const actions = createMessageActions();
        const reuseButton = document.createElement('button');

        reuseButton.type = 'button';
        reuseButton.className = 'message-action-btn reuse-prompt-btn';
        reuseButton.setAttribute('aria-label', 'Reusar pergunta');
        reuseButton.setAttribute('data-tooltip', 'Reusar pergunta');
        reuseButton.innerHTML = iconSvg('refresh-cw');
        reuseButton.addEventListener('click', function() {
            reusePrompt(text);
        });
        attachActionTooltip(reuseButton);

        actions.appendChild(reuseButton);
        messageGroup.appendChild(actions);
    }

    function reusePrompt(text) {
        const inputEl = document.getElementById('userInput');

        if (inputEl.disabled) {
            return;
        }

        inputEl.value = text;
        inputEl.focus();
        inputEl.setSelectionRange(inputEl.value.length, inputEl.value.length);
    }

    function renderAssistantMessage(messageGroup, messageDiv, text) {
        const normalizedText = normalizeCodeMarkdown(text);

        if (!window.marked || !window.DOMPurify) {
            renderBasicMarkdown(messageDiv, normalizedText);
            appendCopyResponseButton(messageGroup, text);
            return;
        }

        try {
            messageDiv.innerHTML = DOMPurify.sanitize(marked.parse(normalizedText));

            if (window.hljs) {
                messageDiv.querySelectorAll('pre code').forEach((block) => {
                    hljs.highlightElement(block);
                });
            }

            appendCopyResponseButton(messageGroup, text);
        } catch (error) {
            console.error('Erro ao renderizar Markdown:', error);
            renderBasicMarkdown(messageDiv, normalizedText);
            appendCopyResponseButton(messageGroup, text);
        }
    }

    function appendCopyResponseButton(messageGroup, text) {
        const actions = createMessageActions();
        const copyButton = document.createElement('button');

        copyButton.type = 'button';
        copyButton.className = 'message-action-btn copy-response-btn';
        copyButton.setAttribute('aria-label', 'Copiar resposta');
        copyButton.setAttribute('data-tooltip', 'Copiar resposta');
        copyButton.innerHTML = iconSvg('copy');
        copyButton.addEventListener('click', function() {
            copyResponseText(text, copyButton);
        });
        attachActionTooltip(copyButton);

        actions.appendChild(copyButton);
        messageGroup.appendChild(actions);
    }

    function createMessageActions() {
        const actions = document.createElement('div');

        actions.className = 'message-actions';

        return actions;
    }

    function attachActionTooltip(button) {
        button.addEventListener('mouseenter', function() {
            showFloatingTooltip(button);
        });
        button.addEventListener('focus', function() {
            showFloatingTooltip(button);
        });
        button.addEventListener('mouseleave', hideFloatingTooltip);
        button.addEventListener('blur', hideFloatingTooltip);
    }

    function getFloatingTooltip() {
        let tooltip = document.getElementById('floatingTooltip');

        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'floatingTooltip';
            tooltip.className = 'floating-tooltip';
            document.body.appendChild(tooltip);
        }

        return tooltip;
    }

    function showFloatingTooltip(button) {
        const tooltip = getFloatingTooltip();

        activeTooltipButton = button;
        tooltip.textContent = button.getAttribute('data-tooltip') || '';
        positionFloatingTooltip(button, tooltip);
        tooltip.classList.add('visible');
    }

    function hideFloatingTooltip() {
        const tooltip = getFloatingTooltip();

        activeTooltipButton = null;
        tooltip.classList.remove('visible');
    }

    function positionFloatingTooltip(button, tooltip) {
        const rect = button.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        const shouldOpenBelow = rect.top < tooltipRect.height + 14;
        const top = shouldOpenBelow
            ? rect.bottom + 8
            : rect.top - tooltipRect.height - 8;
        const minLeft = tooltipRect.width / 2 + 8;
        const maxLeft = window.innerWidth - tooltipRect.width / 2 - 8;
        const left = Math.min(Math.max(rect.left + rect.width / 2, minLeft), maxLeft);

        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
    }

    function copyResponseText(text, copyButton) {
        copyText(text).then(() => {
            showCopyFeedback(copyButton, true);
        }).catch((error) => {
            console.error('Erro ao copiar resposta:', error);
            showCopyFeedback(copyButton, false);
        });
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise((resolve, reject) => {
            const textarea = document.createElement('textarea');

            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.left = '-9999px';
            textarea.style.position = 'fixed';
            textarea.style.top = '0';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                document.execCommand('copy') ? resolve() : reject(new Error('Cópia não permitida.'));
            } catch (error) {
                reject(error);
            } finally {
                textarea.remove();
            }
        });
    }

    function showCopyFeedback(copyButton, success) {
        copyButton.classList.toggle('copied', success);
        const label = success ? 'Resposta copiada' : 'Não foi possível copiar';

        copyButton.setAttribute('aria-label', label);
        copyButton.setAttribute('data-tooltip', label);
        copyButton.innerHTML = iconSvg(success ? 'check' : 'copy-x');

        refreshFloatingTooltip(copyButton);

        window.setTimeout(() => {
            copyButton.classList.remove('copied');
            copyButton.setAttribute('aria-label', 'Copiar resposta');
            copyButton.setAttribute('data-tooltip', 'Copiar resposta');
            copyButton.innerHTML = iconSvg('copy');
            refreshFloatingTooltip(copyButton);
        }, 1600);
    }

    function refreshFloatingTooltip(button) {
        if (activeTooltipButton !== button) {
            return;
        }

        showFloatingTooltip(button);
    }

    function iconSvg(name) {
        const icons = {
            copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"></path>',
            'copy-x': '<line x1="12" x2="18" y1="12" y2="18"></line><line x1="12" x2="18" y1="18" y2="12"></line><rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"></path>',
            check: '<path d="M20 6 9 17l-5-5"></path>',
            'refresh-cw': '<path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"></path><path d="M16 16h5v5"></path>'
        };

        return `<svg aria-hidden="true" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24">${icons[name] || icons.copy}</svg>`;
    }

    function normalizeCodeMarkdown(text) {
        let normalizedText = text
            .replace(/```markdown\s*```(\w+)\s*([\s\S]*?)```\s*```/g, '```$1\n$2\n```')
            .replace(/```markdown\s+```(\w+)\s+([\s\S]*?)```\s*```/g, '```$1\n$2\n```')
            .replace(/```(\w+)\s+([\s\S]*?)```/g, (match, language, code) => {
                if (code.includes('\n')) {
                    return match;
                }

                return `\`\`\`${language}\n${code.trim()}\n\`\`\``;
            });

        normalizedText = fenceBareCodeBlocks(normalizedText);
        normalizedText = removeRepeatedCodeBlocks(normalizedText);

        return removePlainCodeBeforeFormattedCode(normalizedText);
    }

    function fenceBareCodeBlocks(text) {
        return text
            .replace(/(^|\n)(<\?php[\s\S]*?)(?=\n(?:Aqui está|Esta função|Esse código|Explicação|Observação)\b|$)/g, (match, prefix, code) => {
                if (match.includes('```')) return match;
                return `${prefix}\`\`\`php\n${code.trim()}\n\`\`\``;
            })
            .replace(/(^|\n)((?:function|const|let|var|class)\s+[A-Za-z_$][\w$]*[\s\S]*?)(?=\n\n[A-ZÀ-Úa-zà-ú]|$)/g, (match, prefix, code) => {
                if (match.includes('```')) return match;
                return `${prefix}\`\`\`javascript\n${code.trim()}\n\`\`\``;
            });
    }

    function removeRepeatedCodeBlocks(text) {
        const seenCodeBlocks = new Set();

        return text.replace(/```(\w+)?\n([\s\S]*?)```/g, (match, language, code) => {
            const codeKey = normalizeCodeForComparison(code);

            if (seenCodeBlocks.has(codeKey)) {
                return '';
            }

            seenCodeBlocks.add(codeKey);
            return `\`\`\`${language || 'plaintext'}\n${code.trim()}\n\`\`\``;
        });
    }

    function removePlainCodeBeforeFormattedCode(text) {
        if (!text.includes('```')) {
            return text;
        }

        return text.replace(
            /((?:^|\n)(?:function|const|let|var|class)\s+[\s\S]*?)(\n\n```(?:javascript|js|php|html|css)\n[\s\S]*?```)/g,
            (match, plainCode, formattedCode) => {
                const plainSignature = getCodeSignature(plainCode);
                const formattedSignature = getCodeSignature(formattedCode);

                if (plainSignature && formattedSignature.includes(plainSignature)) {
                    return formattedCode;
                }

                return match;
            }
        );
    }

    function getCodeSignature(code) {
        const functionMatch = code.match(/function\s+([A-Za-z_$][\w$]*)/);
        const variableMatch = code.match(/(?:const|let|var)\s+([A-Za-z_$][\w$]*)/);
        const classMatch = code.match(/class\s+([A-Za-z_$][\w$]*)/);

        return functionMatch?.[1] || variableMatch?.[1] || classMatch?.[1] || '';
    }

    function normalizeCodeForComparison(code) {
        return code
            .replace(/<span[^>]*>/g, '')
            .replace(/<\/span>/g, '')
            .replace(/class=&quot;[^&]*&quot;&gt;/g, '')
            .replace(/class="[^"]*">/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function renderBasicMarkdown(messageDiv, text) {
        const parts = text.split(/```(\w+)?\n([\s\S]*?)```/g);

        parts.forEach((part, index) => {
            if (index % 3 === 0) {
                appendTextParagraphs(messageDiv, part);
                return;
            }

            if (index % 3 === 1) {
                const language = part || 'plaintext';
                const code = parts[index + 1] || '';
                appendCodeBlock(messageDiv, code, language);
            }
        });
    }

    function appendTextParagraphs(container, text) {
        text.trim().split(/\n{2,}/).forEach((paragraphText) => {
            if (!paragraphText.trim()) return;

            const paragraph = document.createElement('p');
            paragraph.textContent = paragraphText.trim();
            container.appendChild(paragraph);
        });
    }

    function appendCodeBlock(container, code, language) {
        const pre = document.createElement('pre');
        const codeEl = document.createElement('code');

        codeEl.className = `language-${language}`;
        codeEl.innerHTML = applyBasicHighlight(code, language);

        pre.appendChild(codeEl);
        container.appendChild(pre);
    }

    function applyBasicHighlight(code, language) {
        let escapedCode = escapeHtml(code);
        const protectedTokens = [];
        const languageKeywords = {
            php: 'abstract|array|as|break|case|catch|class|const|continue|default|do|echo|else|elseif|extends|final|for|foreach|function|if|implements|interface|namespace|new|private|protected|public|return|static|switch|throw|try|use|while',
            javascript: 'async|await|break|case|catch|class|const|continue|default|else|export|extends|finally|for|function|if|import|let|new|return|switch|throw|try|var|while',
            js: 'async|await|break|case|catch|class|const|continue|default|else|export|extends|finally|for|function|if|import|let|new|return|switch|throw|try|var|while',
            css: 'align-items|background|border|color|display|flex|font-size|gap|grid|height|justify-content|margin|padding|position|width',
            html: 'html|head|body|div|span|button|form|input|script|style|link|meta|title'
        };
        const keywords = languageKeywords[language] || languageKeywords.javascript;

        escapedCode = escapedCode
            .replace(/('[^'\n]*'|&quot;[^&\n]*(?:&quot;)|`[^`\n]*`)/g, (match) => protectToken(`<span class="code-string">${match}</span>`, protectedTokens))
            .replace(/(&lt;!--[\s\S]*?--&gt;|\/\/.*)/g, (match) => protectToken(`<span class="code-comment">${match}</span>`, protectedTokens))
            .replace(/\b(\d+)\b/g, '<span class="code-number">$1</span>')
            .replace(new RegExp(`\\b(${keywords})\\b`, 'g'), '<span class="code-keyword">$1</span>');

        return protectedTokens.reduce((highlightedCode, token, index) => {
            return highlightedCode.replace(createProtectedToken(index), token);
        }, escapedCode);
    }

    function protectToken(value, protectedTokens) {
        const token = createProtectedToken(protectedTokens.length);
        protectedTokens.push(value);
        return token;
    }

    function createProtectedToken(index) {
        let tokenSuffix = '';
        let currentIndex = index;

        do {
            tokenSuffix = String.fromCharCode(65 + (currentIndex % 26)) + tokenSuffix;
            currentIndex = Math.floor(currentIndex / 26) - 1;
        } while (currentIndex >= 0);

        return `@@CODETOKEN${tokenSuffix}@@`;
    }

    function escapeHtml(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function scrollToBottom() {
        const container = document.getElementById('chatMessages');
        container.scrollTop = container.scrollHeight;
    }
</script>

</body>
</html>
