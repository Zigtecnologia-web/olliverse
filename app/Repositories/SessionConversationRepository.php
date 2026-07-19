<?php

declare(strict_types=1);

namespace App\Repositories;

final class SessionConversationRepository
{
    private const MESSAGES_KEY = 'ollama_chat_messages';
    private const SYSTEM_PROMPT_KEY = 'system_prompt';

    /**
     * @return array<int, array<string, string>>
     */
    public function messages(): array
    {
        $this->ensureMessages();

        return $_SESSION[self::MESSAGES_KEY];
    }

    /**
     * @param array<int, array<string, string>> $messages
     */
    public function replaceMessages(array $messages): void
    {
        $_SESSION[self::MESSAGES_KEY] = $this->conversationMessagesOnly($messages);
    }

    public function systemPrompt(string $defaultPrompt): string
    {
        $currentPrompt = trim((string) ($_SESSION[self::SYSTEM_PROMPT_KEY] ?? ''));

        if ($currentPrompt === '') {
            $_SESSION[self::SYSTEM_PROMPT_KEY] = $defaultPrompt;
        }

        return $_SESSION[self::SYSTEM_PROMPT_KEY];
    }

    public function replaceSystemPrompt(string $systemPrompt, string $defaultPrompt): string
    {
        $_SESSION[self::SYSTEM_PROMPT_KEY] = trim($systemPrompt) !== '' ? trim($systemPrompt) : $defaultPrompt;

        return $_SESSION[self::SYSTEM_PROMPT_KEY];
    }

    public function clear(): void
    {
        unset($_SESSION[self::MESSAGES_KEY], $_SESSION[self::SYSTEM_PROMPT_KEY]);
    }

    public function ensureMessages(): void
    {
        if (!isset($_SESSION[self::MESSAGES_KEY]) || !is_array($_SESSION[self::MESSAGES_KEY])) {
            $_SESSION[self::MESSAGES_KEY] = [];
            return;
        }

        $_SESSION[self::MESSAGES_KEY] = $this->conversationMessagesOnly($_SESSION[self::MESSAGES_KEY]);
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
}
