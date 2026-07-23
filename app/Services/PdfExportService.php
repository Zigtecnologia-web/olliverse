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
    public function render(array $payload, array $chartImages = []): string
    {
        $html = $this->renderHtml($payload, $chartImages);
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
    private function renderHtml(array $payload, array $chartImages = []): string
    {
        if (!is_file($this->templatePath)) {
            throw new RuntimeException('Template de PDF não encontrado.');
        }

        $chat = $payload['chat'];
        $chartQueue = array_values($chartImages);
        $messages = array_map(
            function (array $message) use (&$chartQueue): array {
                $html = $this->parsedown->text($message['content']);

                if ($message['role'] !== 'user' && $chartQueue !== []) {
                    $html = $this->injectChartImages($html, $chartQueue);
                }

                return [
                    'role' => $message['role'] === 'user' ? 'user' : 'assistant',
                    'label' => $message['role'] === 'user' ? 'Usuário' : 'Olliverse',
                    'html' => $html,
                ];
            },
            $payload['messages']
        );

        ob_start();
        require $this->templatePath;

        return (string) ob_get_clean();
    }

    private function injectChartImages(string $html, array &$chartQueue): string
    {
        return (string) preg_replace_callback(
            '/<pre><code class="language-json-chart">.*?<\/code><\/pre>/s',
            static function (array $matches) use (&$chartQueue): string {
                $image = array_shift($chartQueue);

                if (!is_string($image) || preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $image) !== 1) {
                    return $matches[0];
                }

                return sprintf(
                    '<figure class="chart-export-figure"><img src="%s" alt="Gráfico exportado da conversa"></figure>',
                    htmlspecialchars($image, ENT_QUOTES, 'UTF-8')
                );
            },
            $html
        );
    }
}
