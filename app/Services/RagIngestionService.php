<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SqliteDocumentChunkRepository;
use RuntimeException;

final readonly class RagIngestionService
{
    public function __construct(
        private SqliteDocumentChunkRepository $repository,
        private RagChunkerService $chunkerService,
        private ContextWindowService $contextWindowService,
        private OllamaClient $ollamaClient,
        private string $embeddingModel,
    ) {
    }

    /**
     * @return array{id: int, source_name: string, chunks: int}
     */
    public function ingest(string $sourceName, string $text): array
    {
        $sourceName = trim($sourceName);
        $chunks = $this->chunkerService->chunk($text);

        if ($sourceName === '') {
            throw new RuntimeException('Nome do documento inválido.');
        }

        if ($chunks === []) {
            throw new RuntimeException('O documento não possui texto suficiente para preparar a consulta.');
        }

        $preparedChunks = [];

        foreach ($chunks as $chunk) {
            try {
                $embedding = $this->ollamaClient->embedding($this->embeddingModel, $chunk);
            } catch (RuntimeException $error) {
                throw new RuntimeException($this->friendlyEmbeddingError($error->getMessage()));
            }

            if ($embedding === []) {
                throw new RuntimeException('O modelo de embeddings não retornou vetor para o documento.');
            }

            $preparedChunks[] = [
                'content' => $chunk,
                'embedding' => $embedding,
                'token_count' => $this->contextWindowService->estimateTokens([
                    [
                        'role' => 'user',
                        'content' => $chunk,
                    ],
                ]),
            ];
        }

        $documentId = $this->repository->replaceSourceChunks($sourceName, $preparedChunks);

        return [
            'id' => $documentId,
            'source_name' => $sourceName,
            'chunks' => count($preparedChunks),
        ];
    }

    private function friendlyEmbeddingError(string $message): string
    {
        if (str_contains($message, 'not found') || str_contains($message, 'try pulling it first')) {
            return 'O modelo de leitura de documentos não está instalado no Ollama. Rode: ollama pull '
                . $this->embeddingModel;
        }

        return $message;
    }
}
