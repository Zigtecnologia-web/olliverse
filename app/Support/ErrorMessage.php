<?php

declare(strict_types=1);

namespace App\Support;

final class ErrorMessage
{
    public static function technical(string $message): string
    {
        if (preg_match('/maximum execution time|execution time|timed out|timeout/i', $message) === 1) {
            return 'A resposta demorou mais que o limite configurado. Tente novamente ou reduza o prompt.';
        }

        return trim(strip_tags($message)) ?: 'Erro interno ao processar a resposta.';
    }

    public static function isContextWindowError(string $error): bool
    {
        return preg_match('/context|window|token|exceed|exceeded|too long|maximum/i', $error) === 1;
    }
}
