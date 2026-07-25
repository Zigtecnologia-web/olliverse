<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final readonly class SqliteChatHistoryRepository
{
    public function __construct(
        private PDO $pdo,
        private int $workspaceId = 1,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->chatsForWhere('WHERE c.workspace_id = :workspace_id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return $this->all();
        }

        return $this->chatsForWhere(
            'WHERE c.workspace_id = :workspace_id
                AND (
                    c.title LIKE :query ESCAPE \'\\\'
                OR EXISTS (
                    SELECT 1
                    FROM messages search_messages
                    WHERE search_messages.chat_id = c.id
                        AND search_messages.content LIKE :query ESCAPE \'\\\'
                ))',
            ['query' => '%' . $this->escapeLike($query) . '%']
        );
    }

    /**
     * @param array<string, string> $parameters
     * @return array<int, array<string, mixed>>
     */
    private function chatsForWhere(string $whereSql, array $parameters = []): array
    {
        $sql = 'SELECT
            c.id,
            c.title,
            c.model_used,
            c.created_at,
            c.updated_at,
            (
                SELECT m.content
                FROM messages m
                WHERE m.chat_id = c.id AND m.role = \'user\'
                ORDER BY m.id ASC
                LIMIT 1
            ) AS first_user_message,
            (
                SELECT COUNT(*)
                FROM messages m
                WHERE m.chat_id = c.id
            ) AS message_count
         FROM chats c
         ' . $whereSql . '
         ORDER BY datetime(c.updated_at) DESC, c.id DESC';

        $parameters['workspace_id'] = $this->workspaceId;

        if ($parameters !== []) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
        } else {
            $statement = $this->pdo->query($sql);
        }

        return array_map(
            fn (array $chat): array => $this->normalize($chat),
            $statement->fetchAll()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $chatId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                c.id,
                c.title,
                c.model_used,
                c.created_at,
                c.updated_at,
                (
                    SELECT m.content
                    FROM messages m
                    WHERE m.chat_id = c.id AND m.role = \'user\'
                    ORDER BY m.id ASC
                    LIMIT 1
                ) AS first_user_message,
                (
                    SELECT COUNT(*)
                    FROM messages m
                    WHERE m.chat_id = c.id
                ) AS message_count
             FROM chats c
             WHERE c.id = :id AND c.workspace_id = :workspace_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $chatId,
            'workspace_id' => $this->workspaceId,
        ]);
        $chat = $statement->fetch();

        return is_array($chat) ? $this->normalize($chat) : null;
    }

    public function delete(int $chatId): bool
    {
        if ($chatId <= 0) {
            return false;
        }

        $statement = $this->pdo->prepare('DELETE FROM chats WHERE id = :id AND workspace_id = :workspace_id');
        $statement->execute([
            'id' => $chatId,
            'workspace_id' => $this->workspaceId,
        ]);

        if (isset($_SESSION['olliverse_persona_cache'][$chatId])) {
            unset($_SESSION['olliverse_persona_cache'][$chatId]);
        }

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $chat
     * @return array<string, mixed>
     */
    private function normalize(array $chat): array
    {
        $firstUserMessage = trim((string) ($chat['first_user_message'] ?? ''));
        $title = trim((string) ($chat['title'] ?? ''));

        if ($title === '' || $title === 'Nova conversa') {
            $title = $firstUserMessage !== '' ? $this->shorten($firstUserMessage, 40) : 'Nova conversa';
        }

        return [
            'id' => (int) $chat['id'],
            'title' => $title,
            'model_used' => (string) ($chat['model_used'] ?? ''),
            'created_at' => (string) ($chat['created_at'] ?? ''),
            'updated_at' => (string) ($chat['updated_at'] ?? ''),
            'message_count' => (int) ($chat['message_count'] ?? 0),
        ];
    }

    private function shorten(string $content, int $limit): string
    {
        $normalized = preg_replace('/\s+/', ' ', $content) ?? $content;

        if (function_exists('mb_strlen') && mb_strlen($normalized, 'UTF-8') > $limit) {
            return mb_substr($normalized, 0, $limit - 3, 'UTF-8') . '...';
        }

        if (!function_exists('mb_strlen') && strlen($normalized) > $limit) {
            return substr($normalized, 0, $limit - 3) . '...';
        }

        return $normalized;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
