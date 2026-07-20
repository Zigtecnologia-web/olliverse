<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final readonly class SqliteChatExportRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{chat: array<string, mixed>, messages: array<int, array<string, string>>}|null
     */
    public function markdownPayload(int $chatId): ?array
    {
        $chatStatement = $this->pdo->prepare(
            'SELECT id, title, model_used, created_at, updated_at
             FROM chats
             WHERE id = :id
             LIMIT 1'
        );
        $chatStatement->execute(['id' => $chatId]);
        $chat = $chatStatement->fetch();

        if (!is_array($chat)) {
            return null;
        }

        $messageStatement = $this->pdo->prepare(
            'SELECT role, content
             FROM messages
             WHERE chat_id = :chat_id
             ORDER BY id ASC'
        );
        $messageStatement->execute(['chat_id' => $chatId]);

        return [
            'chat' => [
                'id' => (int) $chat['id'],
                'title' => (string) $chat['title'],
                'model_used' => (string) $chat['model_used'],
                'created_at' => (string) $chat['created_at'],
                'updated_at' => (string) $chat['updated_at'],
            ],
            'messages' => array_map(
                static fn (array $message): array => [
                    'role' => (string) $message['role'],
                    'content' => (string) $message['content'],
                ],
                $messageStatement->fetchAll()
            ),
        ];
    }

    /**
     * @param array{chat: array<string, mixed>, messages: array<int, array<string, string>>} $payload
     */
    public function toMarkdown(array $payload): string
    {
        $chat = $payload['chat'];
        $lines = [
            '# Conversa: ' . ((string) $chat['title'] ?: 'Nova conversa'),
            'Data: ' . (string) $chat['created_at'] . ' | Modelo: ' . (string) $chat['model_used'],
            '',
        ];

        foreach ($payload['messages'] as $message) {
            $role = $message['role'] === 'user' ? 'Usuário' : 'Assistente';

            $lines[] = '**' . $role . ':** ' . $message['content'];
            $lines[] = '---';
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    public function filename(array $chat): string
    {
        $title = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', (string) ($chat['title'] ?? 'conversa')) ?? 'conversa';
        $title = trim($title, '-_.') ?: 'conversa';

        return sprintf('olliverse-%s-%d.md', strtolower($title), (int) $chat['id']);
    }
}
