<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\AppConfig;
use App\Contracts\ConversationRepository;
use App\Services\ContextWindowService;
use App\Services\OllamaClient;
use App\Services\OllamaStreamException;
use App\Services\PluginManager;
use App\Services\RagRetrievalService;
use App\Support\ErrorMessage;
use Throwable;

final readonly class ChatStreamHandler
{
    public function __construct(
        private AppConfig $config,
        private OllamaClient $ollamaClient,
        private ConversationRepository $conversationRepository,
        private ContextWindowService $contextWindowService,
        private ?RagRetrievalService $ragRetrievalService = null,
        private ?PluginManager $pluginManager = null,
    ) {
    }

    /**
     * @param array<int, string> $availableModels
     * @param array<int, int> $ragDocumentIds
     */
    public function handle(
        string $prompt,
        string $selectedModel,
        array $availableModels,
        bool $ragEnabled = false,
        array $ragDocumentIds = []
    ): never {
        ini_set('display_errors', '0');
        $this->applyExecutionLimit();

        $headersSent = false;
        $systemPrompt = $this->conversationRepository->systemPrompt($this->config->defaultSystemPrompt);
        $conversationMessages = $this->conversationRepository->messages();

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        register_shutdown_function(static function () use (&$headersSent): void {
            $error = error_get_last();

            if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            if (!$headersSent) {
                NdjsonResponse::start();
                $headersSent = true;
            }

            NdjsonResponse::emit([
                'type' => 'error',
                'message' => ErrorMessage::technical($error['message']),
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

            $conversationMessages[] = [
                'role' => 'user',
                'content' => $prompt,
            ];

            $effectiveSystemPrompt = $this->withPluginPrompts($systemPrompt);
            $messagesForContext = $this->contextWindowService->withSystemPrompt($effectiveSystemPrompt, $conversationMessages);
            $ragChunks = $ragEnabled && $this->ragRetrievalService !== null
                ? $this->ragRetrievalService->retrieve($prompt, 3, $ragDocumentIds)
                : [];
            $effectiveSystemPrompt = $this->ragRetrievalService?->augmentSystemPrompt($effectiveSystemPrompt, $ragChunks) ?? $effectiveSystemPrompt;
            $contextWasTrimmed = $this->contextWindowService->trimExcess($conversationMessages, $effectiveSystemPrompt);
            $messagesForContext = $this->contextWindowService->withSystemPrompt($effectiveSystemPrompt, $conversationMessages);

            NdjsonResponse::start();
            $headersSent = true;

            if ($ragEnabled && $this->ragRetrievalService !== null) {
                NdjsonResponse::emit([
                    'type' => 'rag_metadata',
                    'sources' => $this->ragRetrievalService->metadata($ragChunks),
                ]);
            }

            try {
                $assistantResponse = $this->ollamaClient->streamChat([
                    'model' => $selectedModel,
                    'messages' => $messagesForContext,
                    'stream' => true,
                ] + $this->chatOptions(), static function (array $payload): void {
                    NdjsonResponse::emit($payload);
                });
            } catch (OllamaStreamException $error) {
                $this->emitRequestError($error->getMessage(), $effectiveSystemPrompt, $messagesForContext);
                exit;
            }

            if ($assistantResponse === '') {
                NdjsonResponse::emit([
                    'type' => 'error',
                    'message' => 'A IA não retornou conteúdo.',
                    'context_reset' => false,
                    'context_usage' => $this->contextWindowService->usage($messagesForContext),
                ]);
                exit;
            }

            $conversationMessages[] = [
                'role' => 'assistant',
                'content' => $assistantResponse,
            ];

            $contextWasTrimmed = $this->contextWindowService->trimExcess($conversationMessages, $systemPrompt) || $contextWasTrimmed;
            $contextWasTrimmed = $this->conversationRepository->replaceConversation(
                $conversationMessages,
                $systemPrompt,
                $selectedModel
            ) || $contextWasTrimmed;

            NdjsonResponse::emit([
                'type' => 'meta',
                'chat' => $this->conversationRepository->chatSummary(),
                'context_usage' => $this->contextWindowService->usage(
                    $this->contextWindowService->withSystemPrompt($effectiveSystemPrompt, $conversationMessages)
                ),
                'context_trimmed' => $contextWasTrimmed,
            ]);

            exit;
        } catch (Throwable $error) {
            if (!$headersSent) {
                NdjsonResponse::start();
            }

            NdjsonResponse::emit([
                'type' => 'error',
                'message' => ErrorMessage::technical($error->getMessage()),
                'context_reset' => false,
                'context_usage' => $this->contextWindowService->usage(
                    $this->contextWindowService->withSystemPrompt($systemPrompt, $conversationMessages)
                ),
            ]);
            exit;
        }
    }

    /**
     * @param array<int, array<string, string>> $messagesForContext
     */
    private function emitRequestError(string $requestError, string $systemPrompt, array $messagesForContext): void
    {
        if (ErrorMessage::isContextWindowError($requestError)) {
            $this->conversationRepository->replaceMessages([]);

            NdjsonResponse::emit([
                'type' => 'error',
                'message' => 'O contexto ficou grande demais e foi resetado automaticamente para manter a fluidez.',
                'context_reset' => true,
                'context_usage' => $this->contextWindowService->usage([
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                ]),
            ]);
            return;
        }

        NdjsonResponse::emit([
            'type' => 'error',
            'message' => ErrorMessage::technical($requestError),
            'context_reset' => false,
            'context_usage' => $this->contextWindowService->usage($messagesForContext),
        ]);
    }

    private function applyExecutionLimit(): void
    {
        ini_set('max_execution_time', (string) $this->config->ollamaResponseTimeout);

        if (function_exists('set_time_limit')) {
            set_time_limit($this->config->ollamaResponseTimeout);
        }
    }

    private function withPluginPrompts(string $systemPrompt): string
    {
        $pluginPrompts = $this->pluginManager?->activePrompts() ?? [];

        if ($pluginPrompts === []) {
            return $systemPrompt;
        }

        return trim($systemPrompt . "\n\n" . implode("\n\n", $pluginPrompts));
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function chatOptions(): array
    {
        if (!$this->pluginManager?->isActive('data_analyst')) {
            return [];
        }

        return [
            'options' => [
                'temperature' => 0.1,
            ],
        ];
    }
}
