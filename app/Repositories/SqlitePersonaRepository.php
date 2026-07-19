<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;

final readonly class SqlitePersonaRepository
{
    public function __construct(
        private PDO $pdo,
        private string $defaultPrompt,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, description, prompt_content, is_public
             FROM personas
             ORDER BY is_public DESC, name ASC'
        );

        return array_map([$this, 'formatPersona'], $statement->fetchAll());
    }

    /**
     * @return array<string, mixed>
     */
    public function activeForChat(int $chatId): array
    {
        $cached = $_SESSION['olliverse_persona_cache'][$chatId] ?? null;

        if (is_array($cached) && $this->validPersonaPayload($cached)) {
            return $cached;
        }

        $persona = $this->findForChat($chatId) ?? $this->fallbackPersona();
        $_SESSION['olliverse_persona_cache'][$chatId] = $persona;

        return $persona;
    }

    public function promptForChat(int $chatId): string
    {
        $prompt = trim((string) $this->activeForChat($chatId)['prompt_content']);

        return $prompt !== '' ? $prompt : $this->defaultPrompt;
    }

    public function personaIdForChat(int $chatId): ?int
    {
        if ($chatId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT persona_id FROM chats WHERE id = :id');
        $statement->execute(['id' => $chatId]);
        $id = $statement->fetchColumn();

        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function setChatPersona(int $chatId, int $personaId): array
    {
        $persona = $this->find($personaId);

        if (!$persona) {
            throw new RuntimeException('Persona não encontrada.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE chats
             SET persona_id = :persona_id, system_prompt = :system_prompt, updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'persona_id' => $persona['id'],
            'system_prompt' => $persona['prompt_content'],
            'updated_at' => $this->now(),
            'id' => $chatId,
        ]);

        $_SESSION['olliverse_persona_cache'][$chatId] = $persona;

        return $persona;
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $name, string $description, string $promptContent): array
    {
        $name = trim($name);
        $description = trim($description);
        $promptContent = trim($promptContent);

        if ($name === '') {
            throw new RuntimeException('Informe um nome para a persona.');
        }

        if ($promptContent === '') {
            throw new RuntimeException('O prompt da persona não pode ficar vazio.');
        }

        $now = $this->now();
        $statement = $this->pdo->prepare(
            'INSERT INTO personas (name, description, prompt_content, is_public, created_at, updated_at)
             VALUES (:name, :description, :prompt_content, 0, :created_at, :updated_at)'
        );
        $statement->execute([
            'name' => $name,
            'description' => $description,
            'prompt_content' => $promptContent,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find((int) $this->pdo->lastInsertId()) ?? $this->fallbackPersona();
    }

    /**
     * @return array<string, mixed>
     */
    public function update(int $personaId, string $name, string $description, string $promptContent): array
    {
        $persona = $this->find($personaId);

        if (!$persona) {
            throw new RuntimeException('Persona não encontrada.');
        }

        $name = trim($name);
        $description = trim($description);
        $promptContent = trim($promptContent);

        if ($name === '') {
            throw new RuntimeException('Informe um nome para a persona.');
        }

        if ($promptContent === '') {
            throw new RuntimeException('O prompt da persona não pode ficar vazio.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE personas
             SET name = :name, description = :description, prompt_content = :prompt_content, updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'name' => $name,
            'description' => $description,
            'prompt_content' => $promptContent,
            'updated_at' => $this->now(),
            'id' => $personaId,
        ]);

        $updated = $this->find($personaId) ?? $this->fallbackPersona();
        $this->refreshCachedChats($personaId, $updated);

        return $updated;
    }

    public function delete(int $personaId): void
    {
        $persona = $this->find($personaId);

        if (!$persona) {
            throw new RuntimeException('Persona não encontrada.');
        }

        $fallback = $this->fallbackPersona();

        if ((int) $fallback['id'] === $personaId) {
            throw new RuntimeException('A persona padrão não pode ser excluída.');
        }

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'UPDATE chats
                 SET persona_id = :fallback_id, system_prompt = :system_prompt, updated_at = :updated_at
                 WHERE persona_id = :persona_id'
            );
            $statement->execute([
                'fallback_id' => $fallback['id'],
                'system_prompt' => $fallback['prompt_content'],
                'updated_at' => $this->now(),
                'persona_id' => $personaId,
            ]);

            $statement = $this->pdo->prepare('DELETE FROM personas WHERE id = :id');
            $statement->execute(['id' => $personaId]);

            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }

        unset($_SESSION['olliverse_persona_cache']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $personaId): ?array
    {
        if ($personaId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, name, description, prompt_content, is_public
             FROM personas
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $personaId]);
        $persona = $statement->fetch();

        return is_array($persona) ? $this->formatPersona($persona) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findForChat(int $chatId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT personas.id, personas.name, personas.description, personas.prompt_content, personas.is_public
             FROM chats
             INNER JOIN personas ON personas.id = chats.persona_id
             WHERE chats.id = :chat_id
             LIMIT 1'
        );
        $statement->execute(['chat_id' => $chatId]);
        $persona = $statement->fetch();

        return is_array($persona) ? $this->formatPersona($persona) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackPersona(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, description, prompt_content, is_public
             FROM personas
             WHERE prompt_content = :prompt_content
             ORDER BY id ASC
             LIMIT 1'
        );
        $statement->execute(['prompt_content' => $this->defaultPrompt]);
        $persona = $statement->fetch();

        if (is_array($persona)) {
            return $this->formatPersona($persona);
        }

        return $this->create('Assistente técnico prestativo', 'Persona padrão do Olliverse.', $this->defaultPrompt);
    }

    /**
     * @param array<string, mixed> $persona
     */
    private function validPersonaPayload(array $persona): bool
    {
        return isset($persona['id'], $persona['name'], $persona['prompt_content'])
            && trim((string) $persona['prompt_content']) !== '';
    }

    /**
     * @param array<string, mixed> $persona
     * @return array<string, mixed>
     */
    private function formatPersona(array $persona): array
    {
        return [
            'id' => (int) $persona['id'],
            'name' => (string) $persona['name'],
            'description' => (string) ($persona['description'] ?? ''),
            'prompt_content' => (string) $persona['prompt_content'],
            'is_public' => (bool) $persona['is_public'],
        ];
    }

    /**
     * @param array<string, mixed> $persona
     */
    private function refreshCachedChats(int $personaId, array $persona): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE chats
             SET system_prompt = :system_prompt
             WHERE persona_id = :persona_id'
        );
        $statement->execute([
            'system_prompt' => $persona['prompt_content'],
            'persona_id' => $personaId,
        ]);

        if (!isset($_SESSION['olliverse_persona_cache']) || !is_array($_SESSION['olliverse_persona_cache'])) {
            return;
        }

        foreach ($_SESSION['olliverse_persona_cache'] as $chatId => $cachedPersona) {
            if (is_array($cachedPersona) && (int) ($cachedPersona['id'] ?? 0) === $personaId) {
                $_SESSION['olliverse_persona_cache'][$chatId] = $persona;
            }
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
