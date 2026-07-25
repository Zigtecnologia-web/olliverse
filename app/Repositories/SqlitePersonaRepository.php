<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;

final readonly class SqlitePersonaRepository
{
    private const BASE_PERSONA_NAME = 'Assistente Geral';
    private const BASE_PERSONA_DESCRIPTION = 'Configuração padrão para tarefas gerais.';
    private const BASE_PERSONA_PROMPT = 'Você é um assistente técnico, analítico e pragmático. Entenda a intenção da solicitação antes de responder. Priorize clareza, precisão e objetividade. Explique trade-offs quando existirem, não faça suposições sem evidências e deixe explícitas as incertezas quando necessário. Adapte a profundidade e a linguagem ao contexto e ao nível técnico do usuário.';
    private const DUPLICATE_NAME_MESSAGE = 'Já existe uma persona com esse nome. Escolha um nome diferente.';

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

        $this->assertUniqueName($name);

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

        if ($this->isBasePersona($persona)) {
            $this->assertBasePersonaPurpose($name, $description);
        }

        $this->assertUniqueName($name, $personaId);

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

        if ((int) $fallback['id'] === $personaId || $this->isBasePersona($persona)) {
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
        $statement = $this->pdo->query(
            'SELECT id, name, description, prompt_content, is_public
             FROM personas
             ORDER BY id ASC'
        );
        $baseName = $this->normalizePersonaName(self::BASE_PERSONA_NAME);

        foreach ($statement->fetchAll() as $persona) {
            if ($this->normalizePersonaName((string) ($persona['name'] ?? '')) === $baseName) {
                return $this->formatPersona($persona);
            }
        }

        return $this->createBasePersona();
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

    /**
     * @return array<string, mixed>
     */
    private function createBasePersona(): array
    {
        $existing = $this->findByNormalizedName(self::BASE_PERSONA_NAME);

        if ($existing !== null) {
            return $existing;
        }

        $now = $this->now();
        $statement = $this->pdo->prepare(
            'INSERT INTO personas (name, description, prompt_content, is_public, created_at, updated_at)
             VALUES (:name, :description, :prompt_content, 1, :created_at, :updated_at)'
        );
        $statement->execute([
            'name' => self::BASE_PERSONA_NAME,
            'description' => self::BASE_PERSONA_DESCRIPTION,
            'prompt_content' => self::BASE_PERSONA_PROMPT,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find((int) $this->pdo->lastInsertId()) ?? [
            'id' => 0,
            'name' => self::BASE_PERSONA_NAME,
            'description' => self::BASE_PERSONA_DESCRIPTION,
            'prompt_content' => self::BASE_PERSONA_PROMPT,
            'is_public' => true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByNormalizedName(string $name): ?array
    {
        $normalizedName = $this->normalizePersonaName($name);
        $statement = $this->pdo->query(
            'SELECT id, name, description, prompt_content, is_public
             FROM personas
             ORDER BY id ASC'
        );

        foreach ($statement->fetchAll() as $persona) {
            if ($this->normalizePersonaName((string) ($persona['name'] ?? '')) === $normalizedName) {
                return $this->formatPersona($persona);
            }
        }

        return null;
    }

    private function assertUniqueName(string $name, ?int $ignorePersonaId = null): void
    {
        $normalizedName = $this->normalizePersonaName($name);
        $statement = $this->pdo->query('SELECT id, name FROM personas');

        foreach ($statement->fetchAll() as $persona) {
            $personaId = (int) ($persona['id'] ?? 0);

            if ($ignorePersonaId !== null && $personaId === $ignorePersonaId) {
                continue;
            }

            if ($this->normalizePersonaName((string) ($persona['name'] ?? '')) === $normalizedName) {
                throw new RuntimeException(self::DUPLICATE_NAME_MESSAGE);
            }
        }
    }

    private function assertBasePersonaPurpose(string $name, string $description): void
    {
        if ($this->normalizePersonaName($name) !== $this->normalizePersonaName(self::BASE_PERSONA_NAME)
            || trim($description) !== self::BASE_PERSONA_DESCRIPTION
        ) {
            throw new RuntimeException('A persona padrão não pode ter nome ou descrição alterados.');
        }
    }

    /**
     * @param array<string, mixed> $persona
     */
    private function isBasePersona(array $persona): bool
    {
        return (bool) ($persona['is_public'] ?? false)
            || $this->normalizePersonaName((string) ($persona['name'] ?? '')) === $this->normalizePersonaName(self::BASE_PERSONA_NAME);
    }

    private function normalizePersonaName(string $name): string
    {
        $name = strtr(trim($name), [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ç' => 'C', 'ç' => 'c', 'Ñ' => 'N', 'ñ' => 'n',
        ]);
        $transliterated = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : false;

        return strtolower($transliterated === false ? $name : $transliterated);
    }
}
