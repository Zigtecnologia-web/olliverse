<?php

declare(strict_types=1);

namespace App\Services;

final readonly class ModelMetadataService
{
    public function __construct(
        private OllamaClient $ollamaClient,
        private int $cacheTtl,
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
        $metadata = $this->parseShowVerbose(
            $modelName,
            $this->executeShowVerbose($modelName),
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
    private function parseShowVerbose(string $modelName, string $verboseOutput, ?float $fallbackSizeGb = null): array
    {
        $sizeGb = SizeParser::sizeToGb($this->extractValue($verboseOutput, [
            '/^\s*(?:size|model size)\s+([\d.,]+\s*(?:bytes?|kb|kib|mb|mib|gb|gib|tb|tib)?)/mi',
        ])) ?? $fallbackSizeGb;

        $family = $this->extractValue($verboseOutput, [
            '/^\s*general\.architecture\s+([^\r\n]+)/mi',
            '/^\s*architecture\s+([^\r\n]+)/mi',
            '/^\s*family\s+([^\r\n]+)/mi',
            '/^\s*famil(?:y|ia)\s+([^\r\n]+)/mi',
        ]);

        $contextLength = $this->extractValue($verboseOutput, [
            '/^\s*(?:context length|context_length)\s+(\d+)/mi',
            '/^\s*[a-z0-9_.-]+\.context_length\s+(\d+)/mi',
        ]);

        $quantization = $this->extractValue($verboseOutput, [
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

    private function executeShowVerbose(string $modelName): string
    {
        $command = 'ollama show --verbose ' . escapeshellarg($modelName) . ' 2>&1';
        $output = shell_exec($command);

        return is_string($output) ? $output : '';
    }

    /**
     * @param array<int, string> $patterns
     */
    private function extractValue(string $verboseOutput, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $verboseOutput, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }
}
