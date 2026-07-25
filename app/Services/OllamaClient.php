<?php

declare(strict_types=1);

namespace App\Services;

final readonly class OllamaClient
{
    public function __construct(
        private string $baseUrl,
        private int $connectTimeout,
        private int $responseTimeout,
        private array $nonChatModels = [],
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function listModels(): array
    {
        $result = $this->getJson('/api/tags', 5);

        if (!isset($result['models']) || !is_array($result['models'])) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $model): string => $model['name'] ?? '',
            $result['models']
        ), fn (string $modelName): bool => $this->isChatModel($modelName)));
    }

    private function isChatModel(string $modelName): bool
    {
        $normalizedModel = $this->normalizeModelName($modelName);

        foreach ($this->nonChatModels as $nonChatModel) {
            if ($normalizedModel === $this->normalizeModelName((string) $nonChatModel)) {
                return false;
            }
        }

        return preg_match('/(^|[-_:])(embed|embedding)([-_:]|$)/i', $modelName) !== 1;
    }

    private function normalizeModelName(string $modelName): string
    {
        $modelName = strtolower(trim($modelName));

        return str_ends_with($modelName, ':latest') ? substr($modelName, 0, -7) : $modelName;
    }

    public function modelSizeGb(string $modelName): ?float
    {
        $result = $this->getJson('/api/tags', 5);

        if (!isset($result['models']) || !is_array($result['models'])) {
            return null;
        }

        foreach ($result['models'] as $model) {
            if (($model['name'] ?? '') === $modelName) {
                return SizeParser::bytesToGb((float) ($model['size'] ?? 0));
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function showModel(string $modelName): array
    {
        try {
            return $this->postJson('/api/show', [
                'name' => $modelName,
            ], $this->connectTimeout);
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * @return array<int, float>
     */
    public function embedding(string $modelName, string $text): array
    {
        $result = $this->postJson('/api/embeddings', [
            'model' => $modelName,
            'prompt' => $text,
        ], $this->responseTimeout);

        $embedding = $result['embedding'] ?? null;

        if (!is_array($embedding)) {
            return [];
        }

        return array_map(static fn (mixed $value): float => (float) $value, $embedding);
    }

    public function generate(string $modelName, string $prompt): string
    {
        $result = $this->postJson('/api/generate', [
            'model' => $modelName,
            'prompt' => $prompt,
            'stream' => false,
        ], $this->responseTimeout);

        $response = $result['response'] ?? '';

        if (!is_string($response) || trim($response) === '') {
            throw new \RuntimeException('Ollama retornou uma resposta vazia.');
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(array<string, mixed>): void $onPayload
     */
    public function streamChat(array $payload, callable $onPayload): string
    {
        $assistantResponse = '';
        $streamBuffer = '';
        $streamError = '';

        $this->extendExecutionLimit($this->responseTimeout);

        $ch = curl_init($this->baseUrl . '/api/chat');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->responseTimeout);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use (&$assistantResponse, &$streamBuffer, &$streamError, $onPayload): int {
            $streamBuffer .= $chunk;

            while (($lineEnd = strpos($streamBuffer, "\n")) !== false) {
                $line = trim(substr($streamBuffer, 0, $lineEnd));
                $streamBuffer = substr($streamBuffer, $lineEnd + 1);

                self::processStreamLine($line, $assistantResponse, $streamError, $onPayload);
            }

            return strlen($chunk);
        });

        curl_exec($ch);
        $curlError = curl_error($ch);

        if (trim($streamBuffer) !== '') {
            self::processStreamLine(trim($streamBuffer), $assistantResponse, $streamError, $onPayload);
        }

        $requestError = $curlError !== '' ? $curlError : $streamError;

        if ($requestError !== '') {
            throw new OllamaStreamException($requestError);
        }

        return $assistantResponse;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $path, int $timeout): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);

        if ($curlError !== '') {
            return [];
        }

        $result = json_decode(is_string($response) ? $response : '', true);

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $payload, int $timeout): array
    {
        $this->extendExecutionLimit($timeout);

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);

        if ($curlError !== '') {
            throw new \RuntimeException('Falha ao conectar no Ollama: ' . $curlError);
        }

        $result = json_decode(is_string($response) ? $response : '', true);

        if (!is_array($result)) {
            throw new \RuntimeException('Ollama retornou uma resposta inválida.');
        }

        if (($result['error'] ?? '') !== '') {
            throw new \RuntimeException((string) $result['error']);
        }

        return $result;
    }

    private function extendExecutionLimit(int $timeout): void
    {
        $executionLimit = max(30, $timeout + $this->connectTimeout + 5);
        @ini_set('max_execution_time', (string) $executionLimit);

        if (function_exists('set_time_limit')) {
            @set_time_limit($executionLimit);
        }
    }

    /**
     * @param callable(array<string, mixed>): void $onPayload
     */
    private static function processStreamLine(
        string $line,
        string &$assistantResponse,
        string &$streamError,
        callable $onPayload
    ): void {
        if ($line === '') {
            return;
        }

        $result = json_decode($line, true);

        if (!is_array($result)) {
            return;
        }

        $error = $result['error'] ?? '';

        if ($error !== '') {
            $streamError = (string) $error;
            return;
        }

        $content = $result['message']['content'] ?? '';

        if ($content === '') {
            return;
        }

        $assistantResponse .= $content;
        $onPayload([
            'type' => 'chunk',
            'content' => $content,
        ]);
    }
}
