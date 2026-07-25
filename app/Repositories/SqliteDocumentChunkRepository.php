<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\VectorSimilarityService;
use PDO;

final readonly class SqliteDocumentChunkRepository
{
    public function __construct(
        private PDO $pdo,
        private VectorSimilarityService $similarityService,
        private int $workspaceId = 1,
    ) {
    }

    /**
     * @param array<int, float|int> $embedding
     */
    public function insertChunk(int $documentId, string $sourceName, string $content, array $embedding, int $tokenCount): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO document_chunks (document_id, source_name, content, embedding_json, token_count, created_at)
             VALUES (:document_id, :source_name, :content, :embedding_json, :token_count, :created_at)'
        );
        $statement->execute([
            'document_id' => $documentId,
            'source_name' => $sourceName,
            'content' => $content,
            'embedding_json' => json_encode(array_values($embedding)),
            'token_count' => $tokenCount,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<int, array{content: string, embedding: array<int, float>, token_count: int}> $chunks
     */
    public function replaceSourceChunks(string $sourceName, array $chunks): int
    {
        $this->pdo->beginTransaction();

        try {
            $documentId = $this->findOrCreateDocument($sourceName);
            $statement = $this->pdo->prepare('DELETE FROM document_chunks WHERE document_id = :document_id');
            $statement->execute(['document_id' => $documentId]);

            foreach ($chunks as $chunk) {
                $this->insertChunk($documentId, $sourceName, $chunk['content'], $chunk['embedding'], $chunk['token_count']);
            }

            $this->pdo->commit();

            return $documentId;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    /**
     * @return array<int, array{id: int, source_name: string, chunks: int, created_at: string, estimated_bytes: int}>
     */
    public function sources(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT rag_documents.id,
                    rag_documents.source_name,
                    COUNT(document_chunks.id) AS chunks,
                    rag_documents.created_at,
                    COALESCE(SUM(LENGTH(document_chunks.content) + LENGTH(document_chunks.embedding_json)), 0) AS estimated_bytes
             FROM rag_documents
             LEFT JOIN document_chunks ON document_chunks.document_id = rag_documents.id
             WHERE rag_documents.workspace_id = :workspace_id
             GROUP BY rag_documents.id, rag_documents.source_name, rag_documents.created_at
             ORDER BY rag_documents.created_at DESC, rag_documents.source_name ASC'
        );
        $statement->execute(['workspace_id' => $this->workspaceId]);

        return array_map(
            static fn (array $source): array => [
                'id' => (int) $source['id'],
                'source_name' => (string) $source['source_name'],
                'chunks' => (int) $source['chunks'],
                'created_at' => (string) $source['created_at'],
                'estimated_bytes' => (int) $source['estimated_bytes'],
            ],
            $statement->fetchAll()
        );
    }

    public function deleteDocument(int $documentId): bool
    {
        if ($documentId <= 0) {
            return false;
        }

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'DELETE FROM document_chunks
                 WHERE document_id = :document_id
                    AND EXISTS (
                        SELECT 1 FROM rag_documents
                        WHERE rag_documents.id = document_chunks.document_id
                            AND rag_documents.workspace_id = :workspace_id
                    )'
            );
            $statement->execute([
                'document_id' => $documentId,
                'workspace_id' => $this->workspaceId,
            ]);

            $statement = $this->pdo->prepare('DELETE FROM rag_documents WHERE id = :id AND workspace_id = :workspace_id');
            $statement->execute([
                'id' => $documentId,
                'workspace_id' => $this->workspaceId,
            ]);
            $deleted = $statement->rowCount() > 0;

            $this->pdo->commit();

            return $deleted;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    /**
     * @param array<int, float|int> $queryEmbedding
     * @param array<int, int> $documentIds
     * @return array<int, array{id: int, document_id: int, source_name: string, content: string, score: float}>
     */
    public function topSimilarChunks(array $queryEmbedding, int $limit = 3, array $documentIds = []): array
    {
        $documentIds = array_values(array_unique(array_filter($documentIds, static fn (int $id): bool => $id > 0)));
        $sql = 'SELECT document_chunks.id, document_chunks.document_id, document_chunks.source_name, document_chunks.content, document_chunks.embedding_json
                FROM document_chunks
                INNER JOIN rag_documents ON rag_documents.id = document_chunks.document_id
                WHERE document_chunks.document_id IS NOT NULL
                    AND rag_documents.workspace_id = :workspace_id';
        $params = ['workspace_id' => $this->workspaceId];

        if ($documentIds !== []) {
            $placeholders = [];

            foreach ($documentIds as $index => $documentId) {
                $parameterName = ':document_id_' . $index;
                $placeholders[] = $parameterName;
                $params[$parameterName] = $documentId;
            }

            $sql .= ' AND document_chunks.document_id IN (' . implode(', ', $placeholders) . ')';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rankedChunks = [];

        foreach ($statement->fetchAll() as $chunk) {
            $embedding = json_decode((string) $chunk['embedding_json'], true);

            if (!is_array($embedding)) {
                continue;
            }

            $rankedChunks[] = [
                'id' => (int) $chunk['id'],
                'document_id' => (int) $chunk['document_id'],
                'source_name' => (string) $chunk['source_name'],
                'content' => (string) $chunk['content'],
                'score' => $this->similarityService->cosine($queryEmbedding, $embedding),
            ];
        }

        usort(
            $rankedChunks,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score']
        );

        return array_slice($rankedChunks, 0, $limit);
    }

    /**
     * @param array<int, int> $documentIds
     * @return array<int, array{id: int, document_id: int, source_name: string, content: string, token_count: int}>
     */
    public function sampleChunks(int $limit = 8, array $documentIds = []): array
    {
        $documentIds = array_values(array_unique(array_filter($documentIds, static fn (int $id): bool => $id > 0)));
        $limit = max(1, min(20, $limit));
        $sql = 'SELECT document_chunks.id, document_chunks.document_id, document_chunks.source_name, document_chunks.content, document_chunks.token_count
                FROM document_chunks
                INNER JOIN rag_documents ON rag_documents.id = document_chunks.document_id
                WHERE document_chunks.document_id IS NOT NULL
                    AND rag_documents.workspace_id = :workspace_id';
        $params = ['workspace_id' => $this->workspaceId];

        if ($documentIds !== []) {
            $placeholders = [];

            foreach ($documentIds as $index => $documentId) {
                $parameterName = ':document_id_' . $index;
                $placeholders[] = $parameterName;
                $params[$parameterName] = $documentId;
            }

            $sql .= ' AND document_chunks.document_id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY document_chunks.document_id ASC, document_chunks.id ASC LIMIT ' . $limit;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map(
            static fn (array $chunk): array => [
                'id' => (int) $chunk['id'],
                'document_id' => (int) $chunk['document_id'],
                'source_name' => (string) $chunk['source_name'],
                'content' => (string) $chunk['content'],
                'token_count' => (int) $chunk['token_count'],
            ],
            $statement->fetchAll()
        );
    }

    private function findOrCreateDocument(string $sourceName): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM rag_documents
             WHERE source_name = :source_name AND workspace_id = :workspace_id
             LIMIT 1'
        );
        $statement->execute([
            'source_name' => $sourceName,
            'workspace_id' => $this->workspaceId,
        ]);
        $id = $statement->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO rag_documents (workspace_id, source_name, created_at)
             VALUES (:workspace_id, :source_name, :created_at)'
        );
        $statement->execute([
            'workspace_id' => $this->workspaceId,
            'source_name' => $sourceName,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
