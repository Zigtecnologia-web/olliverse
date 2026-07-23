<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ConversationRepository;
use App\Services\ContextWindowService;
use PDO;
use RuntimeException;

final readonly class SqliteConversationRepository implements ConversationRepository
{
    public function __construct(
        private PDO $pdo,
        private ContextWindowService $contextWindowService,
        private int $chatId,
        private string $defaultModel,
    ) {
    }

    public function chatId(): int
    {
        return $this->chatId;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function messages(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT role, content FROM messages WHERE chat_id = :chat_id ORDER BY id ASC'
        );
        $statement->execute(['chat_id' => $this->chatId]);

        return array_map(
            static fn (array $message): array => [
                'role' => (string) $message['role'],
                'content' => (string) $message['content'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @param array<int, array<string, string>> $messages
     */
    public function replaceMessages(array $messages): void
    {
        $this->transaction(function () use ($messages): void {
            $this->deleteMessages();

            foreach ($this->conversationMessagesOnly($messages) as $message) {
                $this->insertMessage((string) $message['role'], (string) $message['content']);
            }

            $this->touchChat();
        });
    }

    public function systemPrompt(string $defaultPrompt): string
    {
        $personaPrompt = (new SqlitePersonaRepository($this->pdo, $defaultPrompt))->promptForChat($this->chatId);

        if (trim($personaPrompt) !== '') {
            return $personaPrompt;
        }

        $statement = $this->pdo->prepare('SELECT system_prompt FROM chats WHERE id = :id');
        $statement->execute(['id' => $this->chatId]);
        $systemPrompt = $statement->fetchColumn();

        if (!is_string($systemPrompt) || trim($systemPrompt) === '') {
            return $this->replaceSystemPrompt($defaultPrompt, $defaultPrompt);
        }

        return $systemPrompt;
    }

    public function replaceSystemPrompt(string $systemPrompt, string $defaultPrompt): string
    {
        $nextPrompt = trim($systemPrompt) !== '' ? trim($systemPrompt) : $defaultPrompt;
        $persona = (new SqlitePersonaRepository($this->pdo, $defaultPrompt))->create(
            'Persona personalizada',
            'Criada a partir da configuração rápida do chat.',
            $nextPrompt
        );
        (new SqlitePersonaRepository($this->pdo, $defaultPrompt))->setChatPersona($this->chatId, (int) $persona['id']);

        $statement = $this->pdo->prepare(
            'UPDATE chats SET system_prompt = :system_prompt, persona_id = :persona_id, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            'system_prompt' => $nextPrompt,
            'persona_id' => $persona['id'],
            'updated_at' => $this->now(),
            'id' => $this->chatId,
        ]);

        return $nextPrompt;
    }

    public function clear(): void
    {
        $this->replaceMessages([]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function chatSummary(): ?array
    {
        return (new SqliteChatHistoryRepository($this->pdo))->find($this->chatId);
    }

    public function replaceConversation(array $messages, string $systemPrompt, string $model): bool
    {
        $contextWasTrimmed = false;
        $conversationMessages = $this->conversationMessagesOnly($messages);
        $contextWasTrimmed = $this->contextWindowService->trimExcess($conversationMessages, $systemPrompt);

        $this->transaction(function () use ($conversationMessages, $model): void {
            $this->deleteMessages();

            foreach ($conversationMessages as $message) {
                $this->insertMessage((string) $message['role'], (string) $message['content']);
            }

            $this->updateChatAfterInteraction($model, $conversationMessages);
        });

        return $contextWasTrimmed;
    }

    public function updateTitle(string $title): string
    {
        $title = $this->shortenTitle($title, 64);

        if ($title === '') {
            throw new RuntimeException('Título vazio para atualização.');
        }

        $statement = $this->pdo->prepare('UPDATE chats SET title = :title WHERE id = :id');
        $statement->execute([
            'title' => $title,
            'id' => $this->chatId,
        ]);

        return $title;
    }

    public function shouldGenerateTitle(): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT
                c.title,
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
                    WHERE m.chat_id = c.id AND m.role = \'user\'
                ) AS user_message_count
             FROM chats c
             WHERE c.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $this->chatId]);
        $chat = $statement->fetch();

        if (!is_array($chat)) {
            return false;
        }

        $title = trim((string) ($chat['title'] ?? ''));
        $firstUserTitle = $this->shorten((string) ($chat['first_user_message'] ?? ''));
        $userMessageCount = (int) ($chat['user_message_count'] ?? 0);

        if ($userMessageCount < 1 || $userMessageCount > 2) {
            return false;
        }

        return $title === '' || $title === 'Nova conversa' || $title === $firstUserTitle;
    }

    public static function createChat(PDO $pdo, string $model, string $systemPrompt, ?int $personaId = null): int
    {
        $now = self::timestamp();
        $statement = $pdo->prepare(
            'INSERT INTO chats (title, model_used, system_prompt, persona_id, created_at, updated_at)
             VALUES (:title, :model_used, :system_prompt, :persona_id, :created_at, :updated_at)'
        );
        $statement->execute([
            'title' => 'Nova conversa',
            'model_used' => $model,
            'system_prompt' => $systemPrompt,
            'persona_id' => $personaId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function systemPromptForChat(PDO $pdo, int $chatId): ?string
    {
        if ($chatId <= 0) {
            return null;
        }

        $statement = $pdo->prepare('SELECT system_prompt FROM chats WHERE id = :id');
        $statement->execute(['id' => $chatId]);
        $systemPrompt = $statement->fetchColumn();

        if (!is_string($systemPrompt) || trim($systemPrompt) === '') {
            return null;
        }

        return $systemPrompt;
    }

    public static function exists(PDO $pdo, int $chatId): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM chats WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $chatId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param callable(): void $callback
     */
    private function transaction(callable $callback): void
    {
        $this->pdo->beginTransaction();

        try {
            $callback();
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    private function deleteMessages(): void
    {
        $statement = $this->pdo->prepare('DELETE FROM messages WHERE chat_id = :chat_id');
        $statement->execute(['chat_id' => $this->chatId]);
    }

    private function insertMessage(string $role, string $content): void
    {
        if (!in_array($role, ['user', 'assistant'], true)) {
            throw new RuntimeException('Tipo de mensagem inválido para persistência.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO messages (chat_id, role, content, token_count, created_at)
             VALUES (:chat_id, :role, :content, :token_count, :created_at)'
        );
        $statement->execute([
            'chat_id' => $this->chatId,
            'role' => $role,
            'content' => $content,
            'token_count' => $this->contextWindowService->estimateTokens([
                [
                    'role' => $role,
                    'content' => $content,
                ],
            ]),
            'created_at' => $this->now(),
        ]);
    }

    /**
     * @param array<int, array<string, string>> $messages
     */
    private function updateChatAfterInteraction(string $model, array $messages): void
    {
        $title = $this->currentTitleKeepsPriority() ? null : $this->titleFromMessages($messages);
        $statement = $this->pdo->prepare(
            'UPDATE chats
             SET title = COALESCE(:title, title), model_used = :model_used, updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'title' => $title,
            'model_used' => $model !== '' ? $model : $this->defaultModel,
            'updated_at' => $this->now(),
            'id' => $this->chatId,
        ]);
    }

    private function touchChat(): void
    {
        $statement = $this->pdo->prepare('UPDATE chats SET updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            'updated_at' => $this->now(),
            'id' => $this->chatId,
        ]);
    }

    /**
     * @param array<int, array<string, string>> $messages
     * @return array<int, array<string, string>>
     */
    private function conversationMessagesOnly(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            static fn (array $message): bool => in_array($message['role'] ?? '', ['user', 'assistant'], true)
        ));
    }

    /**
     * @param array<int, array<string, string>> $messages
     */
    private function titleFromMessages(array $messages): string
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }

            $content = trim((string) ($message['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            return $this->shorten($content);
        }

        return 'Nova conversa';
    }

    private function shorten(string $content): string
    {
        $normalized = preg_replace('/\s+/', ' ', $content) ?? $content;

        if (function_exists('mb_strlen') && mb_strlen($normalized, 'UTF-8') > 80) {
            return mb_substr($normalized, 0, 77, 'UTF-8') . '...';
        }

        if (!function_exists('mb_strlen') && strlen($normalized) > 80) {
            return substr($normalized, 0, 77) . '...';
        }

        return $normalized;
    }

    private function shortenTitle(string $content, int $limit): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($content)) ?? trim($content);
        $normalized = trim($normalized, "\"'`.:;,- ");

        if (function_exists('mb_strlen') && mb_strlen($normalized, 'UTF-8') > $limit) {
            return rtrim(mb_substr($normalized, 0, $limit - 3, 'UTF-8')) . '...';
        }

        if (!function_exists('mb_strlen') && strlen($normalized) > $limit) {
            return rtrim(substr($normalized, 0, $limit - 3)) . '...';
        }

        return $normalized;
    }

    private function currentTitleKeepsPriority(): bool
    {
        $statement = $this->pdo->prepare('SELECT title FROM chats WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $this->chatId]);
        $title = trim((string) $statement->fetchColumn());

        return $title !== '' && $title !== 'Nova conversa' && !$this->isFirstMessageTitle($title);
    }

    private function isFirstMessageTitle(string $title): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT content
             FROM messages
             WHERE chat_id = :chat_id AND role = \'user\'
             ORDER BY id ASC
             LIMIT 1'
        );
        $statement->execute(['chat_id' => $this->chatId]);
        $firstMessage = $statement->fetchColumn();

        return is_string($firstMessage) && $title === $this->shorten($firstMessage);
    }

    private function now(): string
    {
        return self::timestamp();
    }

    private static function timestamp(): string
    {
        return date('Y-m-d H:i:s');
    }
}
