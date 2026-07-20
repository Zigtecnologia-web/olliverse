<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final readonly class SqliteSearchRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, int>
     */
    public function chatIdsForQuery(string $query): array
    {
        $tokens = $this->tokens($query);

        if ($tokens === []) {
            return [];
        }

        $matches = count($tokens) > 1
            ? ['NEAR(' . implode(' ', $tokens) . ')', implode(' AND ', $tokens)]
            : [$tokens[0]];

        foreach ($matches as $match) {
            $ids = $this->chatIdsForMatch($match);

            if ($ids !== [] || count($tokens) === 1) {
                return $ids;
            }
        }

        return [];
    }

    /**
     * @return array<int, int>
     */
    private function chatIdsForMatch(string $match): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT chat_id
             FROM messages_fts
             WHERE messages_fts MATCH :query
             ORDER BY chat_id DESC'
        );
        $statement->execute(['query' => $match]);

        return array_map(
            static fn (mixed $chatId): int => (int) $chatId,
            $statement->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}_-]+/u', $query, $matches);

        return array_values(array_filter(
            array_map(static fn (string $token): string => '"' . str_replace('"', '""', trim($token)) . '"', $matches[0] ?? []),
            static fn (string $token): bool => $token !== ''
        ));
    }
}
