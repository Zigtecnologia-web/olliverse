<?php

declare(strict_types=1);

namespace App\Services;

final readonly class ModelMetadataService
{
    public function __construct(
        private OllamaClient $ollamaClient,
        private int $cacheTtl,
        private int $fallbackContextLength,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(string $modelName): array
    {
        if (!isset($_SESSION['ollama_model_metadata_cache']) || !is_array($_SESSION['ollama_model_metadata_cache'])) {
            $_SESSION['ollama_model_metadata_cache'] = [];
        }

        $cached = $_SESSION['ollama_model_metadata_cache'][$modelName] ?? null;

        if (is_array($cached) && time() - (int) ($cached['cached_at'] ?? 0) < $this->cacheTtl) {
            return $cached['metadata'];
        }

        $fallbackSizeGb = $this->ollamaClient->modelSizeGb($modelName);
        $metadata = $this->parseShowResponse(
            $modelName,
            $this->ollamaClient->showModel($modelName),
            $fallbackSizeGb
        );

        $_SESSION['ollama_model_metadata_cache'][$modelName] = [
            'cached_at' => time(),
            'metadata' => $metadata,
        ];

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseShowResponse(string $modelName, array $showResponse, ?float $fallbackSizeGb = null): array
    {
        $details = isset($showResponse['details']) && is_array($showResponse['details'])
            ? $showResponse['details']
            : [];
        $modelInfo = isset($showResponse['model_info']) && is_array($showResponse['model_info'])
            ? $showResponse['model_info']
            : [];
        $parameters = is_string($showResponse['parameters'] ?? null) ? $showResponse['parameters'] : '';
        $modelfile = is_string($showResponse['modelfile'] ?? null) ? $showResponse['modelfile'] : '';

        $family = $this->firstString([
            $details['family'] ?? null,
            $modelInfo['general.architecture'] ?? null,
            $modelInfo['architecture'] ?? null,
        ]);
        $contextLength = $this->extractContextLength($modelInfo, $parameters, $modelfile);
        $quantization = $this->firstString([
            $details['quantization_level'] ?? null,
            $details['quantization'] ?? null,
            $modelInfo['general.file_type'] ?? null,
        ]);

        return [
            'model' => $modelName,
            'size_gb' => $fallbackSizeGb,
            'family' => $family ?: null,
            'context_length' => $contextLength ?? $this->fallbackContextLength,
            'context_fallback' => $contextLength === null,
            'quantization' => $quantization ?: null,
        ];
    }

    /**
     * @param array<string, mixed> $modelInfo
     */
    private function extractContextLength(array $modelInfo, string $parameters, string $modelfile): ?int
    {
        foreach ($modelInfo as $key => $value) {
            if (!is_scalar($value) || !str_ends_with((string) $key, '.context_length')) {
                continue;
            }

            $contextLength = $this->positiveInt($value);

            if ($contextLength !== null) {
                return $contextLength;
            }
        }

        foreach ([$parameters, $modelfile] as $source) {
            if (preg_match('/^\s*(?:PARAMETER\s+)?num_ctx\s+(\d+)/mi', $source, $matches) === 1) {
                return $this->positiveInt($matches[1]);
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }
}
