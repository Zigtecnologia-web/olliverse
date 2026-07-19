<?php

declare(strict_types=1);

namespace App\Services;

final readonly class ContextWindowService
{
    public function __construct(private int $tokenLimit)
    {
    }

    /**
     * @param array<int, array<string, string>> $messages
     * @return array{tokens: int, limit: int, percentage: int}
     */
    public function usage(array $messages): array
    {
        $tokens = $this->estimateTokens($messages);

        return [
            'tokens' => $tokens,
            'limit' => $this->tokenLimit,
            'percentage' => min(100, (int) round(($tokens / $this->tokenLimit) * 100)),
        ];
    }

    /**
     * @param array<int, array<string, string>> $messages
     */
    public function estimateTokens(array $messages): int
    {
        $totalCharacters = 0;

        foreach ($messages as $message) {
            $content = (string) ($message['content'] ?? '');
            $totalCharacters += function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
        }

        return (int) ceil($totalCharacters / 4);
    }

    /**
     * @param array<int, array<string, string>> $conversationMessages
     */
    public function trimExcess(array &$conversationMessages, string $systemPrompt): bool
    {
        $trimmed = false;

        do {
            $messagesForContext = $this->withSystemPrompt($systemPrompt, $conversationMessages);

            if ($this->estimateTokens($messagesForContext) <= $this->tokenLimit) {
                return $trimmed;
            }

            if (count($conversationMessages) <= 1) {
                return $trimmed;
            }

            array_shift($conversationMessages);
            $trimmed = true;
        } while (true);
    }

    /**
     * @param array<int, array<string, string>> $messages
     * @return array<int, array<string, string>>
     */
    public function withSystemPrompt(string $systemPrompt, array $messages): array
    {
        return array_merge(
            [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
            ],
            $messages
        );
    }
}
