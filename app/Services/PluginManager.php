<?php

declare(strict_types=1);

namespace App\Services;

final class PluginManager
{
    /** @var array<string, array<string, mixed>> */
    private array $plugins;

    public function __construct(private readonly string $pluginsPath)
    {
        $this->plugins = $this->loadManifests();
        $_SESSION['olliverse_plugins'] ??= [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_values(array_map(function (array $plugin): array {
            $slug = (string) $plugin['slug'];

            return $plugin + [
                'active' => $this->isActive($slug),
                'assets' => $this->assetPaths($slug),
            ];
        }, $this->plugins));
    }

    public function isActive(string $slug): bool
    {
        return in_array($slug, $this->activeSlugs(), true);
    }

    public function setActive(string $slug, bool $active): void
    {
        if (!isset($this->plugins[$slug])) {
            throw new \RuntimeException('Plugin não encontrado.');
        }

        $activeSlugs = $this->activeSlugs();

        if ($active && !in_array($slug, $activeSlugs, true)) {
            $activeSlugs[] = $slug;
        }

        if (!$active) {
            $activeSlugs = array_values(array_filter(
                $activeSlugs,
                static fn (string $activeSlug): bool => $activeSlug !== $slug
            ));
        }

        $_SESSION['olliverse_plugins'] = $activeSlugs;
    }

    /**
     * @return array<int, string>
     */
    public function activePrompts(): array
    {
        $prompts = [];

        foreach ($this->activeSlugs() as $slug) {
            $promptPath = $this->pluginsPath . '/' . $slug . '/includes/prompt.php';

            if (!isset($this->plugins[$slug]) || !is_file($promptPath)) {
                continue;
            }

            $prompt = require $promptPath;

            if (is_string($prompt) && trim($prompt) !== '') {
                $prompts[] = trim($prompt);
            }

            if ($slug === 'data_analyst') {
                $datasetPrompt = $this->dataAnalystRagSamplePrompt();

                if ($datasetPrompt !== '') {
                    $prompts[] = $datasetPrompt;
                }
            }
        }

        return $prompts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activePlugins(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $plugin): bool => (bool) ($plugin['active'] ?? false)
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadManifests(): array
    {
        $plugins = [];

        foreach (glob($this->pluginsPath . '/*/manifest.json') ?: [] as $manifestPath) {
            $json = file_get_contents($manifestPath);
            $manifest = is_string($json) ? json_decode($json, true) : null;

            if (!is_array($manifest) || !is_string($manifest['slug'] ?? null)) {
                continue;
            }

            $slug = basename(dirname($manifestPath));

            if ($slug !== $manifest['slug']) {
                continue;
            }

            $plugins[$slug] = $manifest;
        }

        return $plugins;
    }

    /**
     * @return array<int, string>
     */
    private function activeSlugs(): array
    {
        $activeSlugs = $_SESSION['olliverse_plugins'] ?? [];

        if (!is_array($activeSlugs)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $slug): string => (string) $slug, $activeSlugs),
            fn (string $slug): bool => isset($this->plugins[$slug])
        ));
    }

    /**
     * @return array{style: string|null, script: string|null}
     */
    private function assetPaths(string $slug): array
    {
        $basePath = 'plugins/' . rawurlencode($slug) . '/assets';

        return [
            'style' => is_file($this->pluginsPath . '/' . $slug . '/assets/style.css') ? $basePath . '/style.css' : null,
            'script' => is_file($this->pluginsPath . '/' . $slug . '/assets/script.js') ? $basePath . '/script.js' : null,
        ];
    }

    private function dataAnalystRagSamplePrompt(): string
    {
        $analyticsDataset = $this->dataAnalystAnalyticsPrompt();

        if ($analyticsDataset !== '') {
            return $analyticsDataset;
        }

        $dataset = $_SESSION['olliverse_data_analyst_rag_sample'] ?? null;

        if (!is_array($dataset)) {
            return '';
        }

        $sourceNames = $dataset['source_names'] ?? [];
        $sample = trim((string) ($dataset['sample'] ?? ''));

        if (!is_array($sourceNames) || $sample === '') {
            return '';
        }

        $sourceLabel = implode(', ', array_map(
            static fn (mixed $sourceName): string => (string) $sourceName,
            $sourceNames
        ));

        return trim(
            "Base de dados selecionada via RAG/SQLite para analise:\n"
            . "Documentos: {$sourceLabel}\n"
            . "Amostra recuperada:\n"
            . "```text\n{$sample}\n```"
        );
    }

    private function dataAnalystAnalyticsPrompt(): string
    {
        $dataset = $_SESSION['olliverse_data_analyst_dataset'] ?? null;

        if (!is_array($dataset) || !is_array($dataset['datasets'] ?? null)) {
            return '';
        }

        $lines = [
            'Base de dados selecionada via camada analitica local:',
            'Use SQL SELECT sobre as tabelas abaixo para estruturar agregacoes antes de responder.',
            'Quando gerar graficos, prefira consultas que retornem categoria e valor numerico.',
        ];

        foreach ($dataset['datasets'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $columns = $item['columns'] ?? [];
            $columnLabel = is_array($columns) ? implode(', ', array_map(
                static fn (mixed $column): string => (string) $column,
                $columns
            )) : '';

            $lines[] = sprintf(
                '- Documento %d (%s): tabela `%s`, colunas: %s, linhas: %d, motor: %s',
                (int) ($item['document_id'] ?? 0),
                (string) ($item['source_name'] ?? ''),
                (string) ($item['table_name'] ?? ''),
                $columnLabel,
                (int) ($item['row_count'] ?? 0),
                (string) ($item['engine'] ?? 'local')
            );
        }

        return trim(implode("\n", $lines));
    }
}
