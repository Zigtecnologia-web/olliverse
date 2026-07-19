<?php

declare(strict_types=1);

namespace App\Services;

final readonly class OllamaClient
{
    public function __construct(
        private string $baseUrl,
        private int $connectTimeout,
        private int $responseTimeout,
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
        )));
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
     * @param array<string, mixed> $payload
     * @param callable(array<string, mixed>): void $onPayload
     */
    public function streamChat(array $payload, callable $onPayload): string
    {
        $assistantResponse = '';
        $streamBuffer = '';
        $streamError = '';

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

        curl_close($ch);

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

        if (curl_error($ch)) {
            curl_close($ch);
            return [];
        }

        curl_close($ch);

        $result = json_decode(is_string($response) ? $response : '', true);

        return is_array($result) ? $result : [];
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
