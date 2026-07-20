<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SqliteDocumentChunkRepository;
use RuntimeException;

final readonly class RagRetrievalService
{
    public function __construct(
        private SqliteDocumentChunkRepository $repository,
        private OllamaClient $ollamaClient,
        private string $embeddingModel,
    ) {
    }

    /**
     * @param array<int, int> $documentIds
     * @return array<int, array{id: int, document_id: int, source_name: string, content: string, score: float}>
     */
    public function retrieve(string $question, int $limit = 3, array $documentIds = []): array
    {
        try {
            $embedding = $this->ollamaClient->embedding($this->embeddingModel, $question);
        } catch (RuntimeException $error) {
            throw new RuntimeException($this->friendlyEmbeddingError($error->getMessage()));
        }

        if ($embedding === []) {
            return [];
        }

        return array_values(array_filter(
            $this->repository->topSimilarChunks($embedding, $limit, $documentIds),
            static fn (array $chunk): bool => $chunk['score'] > 0
        ));
    }

    /**
     * @param array<int, array{id: int, document_id: int, source_name: string, content: string, score: float}> $chunks
     */
    public function augmentSystemPrompt(string $systemPrompt, array $chunks): string
    {
        if ($chunks === []) {
            return $systemPrompt;
        }

        $context = array_map(
            static fn (array $chunk, int $index): string => sprintf(
                "[Fonte %d: %s]\n%s",
                $index + 1,
                $chunk['source_name'],
                $chunk['content']
            ),
            $chunks,
            array_keys($chunks)
        );

        return trim($systemPrompt) . "\n\nContexto local recuperado via RAG:\n"
            . implode("\n\n---\n\n", $context)
            . "\n\nUse o contexto local apenas quando ele for relevante para a pergunta. "
            . "Quando usar esse contexto, responda de forma clara e cite o nome da fonte.";
    }

    /**
     * @param array<int, array{id: int, document_id: int, source_name: string, content: string, score: float}> $chunks
     * @return array<int, array{document_id: int, source_name: string, score: float}>
     */
    public function metadata(array $chunks): array
    {
        $sources = [];

        foreach ($chunks as $chunk) {
            $sourceName = $chunk['source_name'];

            if (isset($sources[$sourceName]) && $sources[$sourceName]['score'] >= $chunk['score']) {
                continue;
            }

            $sources[$sourceName] = [
                'document_id' => (int) $chunk['document_id'],
                'source_name' => $sourceName,
                'score' => round($chunk['score'], 4),
            ];
        }

        return array_values($sources);
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
