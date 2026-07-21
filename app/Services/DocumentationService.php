<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class DocumentationService
{
    public function __construct(private readonly string $markdownPath)
    {
    }

    public function html(): string
    {
        if (!is_file($this->markdownPath) || !is_readable($this->markdownPath)) {
            throw new RuntimeException('Documentacao nao encontrada.');
        }

        $markdown = file_get_contents($this->markdownPath);

        if (!is_string($markdown)) {
            throw new RuntimeException('Nao foi possivel ler a documentacao.');
        }

        if (class_exists(\Parsedown::class)) {
            $parser = new \Parsedown();
            $parser->setSafeMode(true);

            return $this->addHeadingIds($parser->text($markdown));
        }

        return '<pre>' . htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8') . '</pre>';
    }

    private function addHeadingIds(string $html): string
    {
        $seen = [];

        return preg_replace_callback(
            '/<h([1-3])>(.*?)<\/h\1>/s',
            function (array $matches) use (&$seen): string {
                $slug = $this->slug(strip_tags($matches[2]));
                $count = $seen[$slug] ?? 0;
                $seen[$slug] = $count + 1;

                if ($count > 0) {
                    $slug .= '-' . ($count + 1);
                }

                return '<h' . $matches[1] . ' id="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">' . $matches[2] . '</h' . $matches[1] . '>';
            },
            $html
        ) ?? $html;
    }

    private function slug(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $decoded);
        $normalized = strtolower(is_string($ascii) ? $ascii : $decoded);
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        return $normalized !== '' ? $normalized : 'secao';
    }
}
