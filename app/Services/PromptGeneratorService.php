<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final readonly class PromptGeneratorService
{
    private const REQUIRED_KEYS = [
        'name',
        'role',
        'persona_traits',
        'skills',
        'directives',
    ];

    public function __construct(private OllamaClient $ollamaClient)
    {
    }

    public function generate(string $modelName, string $name, string $description): string
    {
        $name = trim($name);
        $description = trim($description);

        if ($name === '' || $description === '') {
            throw new RuntimeException('Informe nome e descrição antes de gerar o prompt.');
        }

        $response = $this->ollamaClient->generate($modelName, $this->metaPrompt($name, $description));
        $yaml = $this->extractYaml($response);

        $this->assertRequiredKeys($yaml);

        return $yaml;
    }

    private function metaPrompt(string $name, string $description): string
    {
        return <<<PROMPT
Você é um especialista em engenharia de prompts. Crie um System Prompt estruturado em YAML para uma persona de IA com as seguintes informações:
Nome: {$name}
Descrição: {$description}
O YAML deve obrigatoriamente conter as chaves: name, role, persona_traits, skills e directives.
Responda apenas com o bloco YAML puro, sem explicações adicionais.
PROMPT;
    }

    private function extractYaml(string $response): string
    {
        $response = trim($response);

        if ($response === '') {
            throw new RuntimeException('Ollama retornou uma resposta vazia.');
        }

        if (preg_match('/```(?:ya?ml)?\s*([\s\S]*?)```/i', $response, $match) === 1) {
            return trim($match[1]);
        }

        $lines = preg_split('/\R/', $response) ?: [];
        $yamlLines = [];
        $capturing = false;

        foreach ($lines as $line) {
            if (!$capturing && preg_match('/^\s*(name|role|persona_traits|skills|directives)\s*:/', $line) === 1) {
                $capturing = true;
            }

            if ($capturing) {
                $yamlLines[] = rtrim($line);
            }
        }

        $yaml = trim(implode("\n", $yamlLines));

        return $yaml !== '' ? $yaml : $response;
    }

    private function assertRequiredKeys(string $yaml): void
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (preg_match('/^' . preg_quote($key, '/') . '\s*:/m', $yaml) !== 1) {
                throw new RuntimeException('O YAML gerado não contém a chave obrigatória: ' . $key . '.');
            }
        }
    }
}
