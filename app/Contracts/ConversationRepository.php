<?php

declare(strict_types=1);

namespace App\Contracts;

interface ConversationRepository
{
    public function chatId(): int;

    /**
     * @return array<int, array<string, string>>
     */
    public function messages(): array;

    /**
     * @param array<int, array<string, string>> $messages
     */
    public function replaceMessages(array $messages): void;

    public function systemPrompt(string $defaultPrompt): string;

    public function replaceSystemPrompt(string $systemPrompt, string $defaultPrompt): string;

    public function clear(): void;

    /**
     * @return array<string, mixed>|null
     */
    public function chatSummary(): ?array;

    /**
     * @param array<int, array<string, string>> $messages
     */
    public function replaceConversation(array $messages, string $systemPrompt, string $model): bool;
}
