<?php

declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Parsedown;
use RuntimeException;

final class PdfExportService
{
    private Parsedown $parsedown;

    public function __construct(private string $templatePath)
    {
        $this->parsedown = new Parsedown();
        $this->parsedown->setSafeMode(true);
        $this->parsedown->setBreaksEnabled(true);
    }

    /**
     * @param array{chat: array<string, mixed>, messages: array<int, array<string, string>>} $payload
     */
    public function render(array $payload): string
    {
        $html = $this->renderHtml($payload);
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }

    public function filename(array $chat): string
    {
        $title = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', (string) ($chat['title'] ?? 'conversa')) ?? 'conversa';
        $title = trim($title, '-_.') ?: 'conversa';

        return sprintf('olliverse-%s-%d.pdf', strtolower($title), (int) $chat['id']);
    }

    /**
     * @param array{chat: array<string, mixed>, messages: array<int, array<string, string>>} $payload
     */
    private function renderHtml(array $payload): string
    {
        if (!is_file($this->templatePath)) {
            throw new RuntimeException('Template de PDF não encontrado.');
        }

        $chat = $payload['chat'];
        $messages = array_map(
            fn (array $message): array => [
                'role' => $message['role'] === 'user' ? 'user' : 'assistant',
                'label' => $message['role'] === 'user' ? 'Usuário' : 'Olliverse',
                'html' => $this->parsedown->text($message['content']),
            ],
            $payload['messages']
        );

        ob_start();
        require $this->templatePath;

        return (string) ob_get_clean();
    }
}
