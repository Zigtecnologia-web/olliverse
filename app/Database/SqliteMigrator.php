<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final readonly class SqliteMigrator
{
    private const BASE_PERSONA_NAME = 'Assistente Geral';
    private const BASE_PERSONA_DESCRIPTION = 'Configuração padrão para tarefas gerais.';
    private const BASE_PERSONA_PROMPT = 'Você é um assistente técnico, analítico e pragmático. Entenda a intenção da solicitação antes de responder. Priorize clareza, precisão e objetividade. Explique trade-offs quando existirem, não faça suposições sem evidências e deixe explícitas as incertezas quando necessário. Adapte a profundidade e a linguagem ao contexto e ao nível técnico do usuário.';
    private const LEGACY_PUBLIC_PERSONA_NAMES = [
        'Assistente técnico prestativo',
        'Assistente de Código',
        'Escritor',
        'Analista de Dados',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS workspaces (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                icon TEXT,
                created_at TEXT NOT NULL
            )'
        );
        $defaultWorkspaceId = $this->ensureDefaultWorkspace();

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS personas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                prompt_content TEXT NOT NULL,
                is_public INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS chats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                model_used TEXT NOT NULL,
                system_prompt TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        if (!$this->hasColumn('chats', 'persona_id')) {
            $this->pdo->exec('ALTER TABLE chats ADD COLUMN persona_id INTEGER NULL');
        }

        if (!$this->hasColumn('chats', 'workspace_id')) {
            $this->pdo->exec('ALTER TABLE chats ADD COLUMN workspace_id INTEGER NULL');
        }

        $statement = $this->pdo->prepare('UPDATE chats SET workspace_id = :workspace_id WHERE workspace_id IS NULL');
        $statement->execute(['workspace_id' => $defaultWorkspaceId]);

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                role TEXT NOT NULL CHECK (role IN (\'system\', \'user\', \'assistant\')),
                content TEXT NOT NULL,
                token_count INTEGER NOT NULL DEFAULT 0,
                response_duration_ms INTEGER NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE
            )'
        );

        if (!$this->hasColumn('messages', 'response_duration_ms')) {
            $this->pdo->exec('ALTER TABLE messages ADD COLUMN response_duration_ms INTEGER NULL');
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS document_chunks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NULL,
                source_name TEXT NOT NULL,
                content TEXT NOT NULL,
                embedding_json TEXT NOT NULL,
                token_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS rag_documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workspace_id INTEGER NULL,
                source_name TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );

        if (!$this->hasColumn('document_chunks', 'document_id')) {
            $this->pdo->exec('ALTER TABLE document_chunks ADD COLUMN document_id INTEGER NULL');
        }

        $this->ensureWorkspaceAwareRagDocuments($defaultWorkspaceId);
        $this->migrateAnalyticsDatasets();
        $this->attachExistingChunksToDocuments();
        $this->ensureWorkspaceAwareRagDocuments($defaultWorkspaceId);
        $this->deleteOrphanedAnalyticsDatasets();

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_chat_id_id ON messages(chat_id, id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chats_updated_at ON chats(updated_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chats_persona_id ON chats(persona_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chats_workspace_id ON chats(workspace_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_rag_documents_workspace_name ON rag_documents(workspace_id, source_name)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_document_chunks_document_id ON document_chunks(document_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_document_chunks_source_name ON document_chunks(source_name)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_datasets_workspace_document ON analytics_datasets(workspace_id, document_id)');

        $this->migrateMessageSearch();

        $this->seedPersonas();
        $this->attachExistingChatsToPersonas();
    }

    private function migrateMessageSearch(): void
    {
        $this->pdo->exec(
            'CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts
             USING fts5(content, chat_id UNINDEXED)'
        );
        $this->pdo->exec('DROP TRIGGER IF EXISTS trg_messages_ai');
        $this->pdo->exec('DROP TRIGGER IF EXISTS trg_messages_ad');
        $this->pdo->exec('DROP TRIGGER IF EXISTS trg_messages_au');
        $this->pdo->exec(
            'CREATE TRIGGER trg_messages_ai AFTER INSERT ON messages BEGIN
                INSERT INTO messages_fts(rowid, content, chat_id)
                VALUES (new.id, new.content, new.chat_id);
            END'
        );
        $this->pdo->exec(
            'CREATE TRIGGER trg_messages_ad AFTER DELETE ON messages BEGIN
                DELETE FROM messages_fts WHERE rowid = old.id;
            END'
        );
        $this->pdo->exec(
            'CREATE TRIGGER trg_messages_au AFTER UPDATE OF content, chat_id ON messages BEGIN
                DELETE FROM messages_fts WHERE rowid = old.id;
                INSERT INTO messages_fts(rowid, content, chat_id)
                VALUES (new.id, new.content, new.chat_id);
            END'
        );
        $this->pdo->exec('DELETE FROM messages_fts');
        $this->pdo->exec(
            'INSERT INTO messages_fts(rowid, content, chat_id)
             SELECT id, content, chat_id FROM messages'
        );
    }

    private function migrateAnalyticsDatasets(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS analytics_datasets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workspace_id INTEGER NOT NULL,
                document_id INTEGER NOT NULL,
                source_name TEXT NOT NULL,
                table_name TEXT NOT NULL,
                columns_json TEXT NOT NULL,
                rows_json TEXT NOT NULL,
                row_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
    }

    private function hasColumn(string $table, string $column): bool
    {
        $statement = $this->pdo->query('PRAGMA table_info(' . $table . ')');

        foreach ($statement->fetchAll() as $field) {
            if (($field['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    private function ensureDefaultWorkspace(): int
    {
        $workspaceId = $this->pdo->query('SELECT id FROM workspaces ORDER BY id ASC LIMIT 1')->fetchColumn();

        if ($workspaceId !== false) {
            return (int) $workspaceId;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO workspaces (name, icon, created_at)
             VALUES (:name, :icon, :created_at)'
        );
        $statement->execute([
            'name' => 'Geral',
            'icon' => '#',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function ensureWorkspaceAwareRagDocuments(int $defaultWorkspaceId): void
    {
        if (!$this->hasColumn('rag_documents', 'workspace_id')) {
            $this->pdo->exec('ALTER TABLE rag_documents ADD COLUMN workspace_id INTEGER NULL');
        }

        $statement = $this->pdo->prepare('UPDATE rag_documents SET workspace_id = :workspace_id WHERE workspace_id IS NULL');
        $statement->execute(['workspace_id' => $defaultWorkspaceId]);

        if (!$this->hasGlobalUniqueRagSourceIndex()) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE rag_documents_workspace_migration (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workspace_id INTEGER NULL,
                source_name TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'INSERT INTO rag_documents_workspace_migration (id, workspace_id, source_name, created_at)
             SELECT id, COALESCE(workspace_id, ' . $defaultWorkspaceId . '), source_name, created_at
             FROM rag_documents'
        );
        $this->pdo->exec('DROP TABLE rag_documents');
        $this->pdo->exec('ALTER TABLE rag_documents_workspace_migration RENAME TO rag_documents');
    }

    private function hasGlobalUniqueRagSourceIndex(): bool
    {
        $indexes = $this->pdo->query('PRAGMA index_list(rag_documents)')->fetchAll();

        foreach ($indexes as $index) {
            if (($index['unique'] ?? 0) != 1) {
                continue;
            }

            $columns = $this->pdo
                ->query('PRAGMA index_info(' . (string) $index['name'] . ')')
                ->fetchAll();

            if (count($columns) === 1 && (($columns[0]['name'] ?? '') === 'source_name')) {
                return true;
            }
        }

        return false;
    }

    private function seedPersonas(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            $basePersonaId = $this->findPersonaIdByNormalizedName(self::BASE_PERSONA_NAME);

            if ($basePersonaId === null) {
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
                $basePersonaId = (int) $this->pdo->lastInsertId();
            } else {
                $statement = $this->pdo->prepare(
                    'UPDATE personas
                     SET name = :name, description = :description, prompt_content = :prompt_content, is_public = 1, updated_at = :updated_at
                     WHERE id = :id'
                );
                $statement->execute([
                    'name' => self::BASE_PERSONA_NAME,
                    'description' => self::BASE_PERSONA_DESCRIPTION,
                    'prompt_content' => self::BASE_PERSONA_PROMPT,
                    'updated_at' => $now,
                    'id' => $basePersonaId,
                ]);
            }

            $this->moveDuplicateBasePersonasToBase($basePersonaId, $now);
            $this->moveLegacyPublicPersonasToBase($basePersonaId, $now);

            $statement = $this->pdo->prepare(
                'UPDATE personas
                 SET is_public = CASE WHEN id = :base_persona_id THEN 1 ELSE 0 END
                 WHERE is_public = 1 OR id = :base_persona_id'
            );
            $statement->execute(['base_persona_id' => $basePersonaId]);

            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    private function moveDuplicateBasePersonasToBase(int $basePersonaId, string $now): void
    {
        $baseName = $this->normalizePersonaName(self::BASE_PERSONA_NAME);
        $statement = $this->pdo->query('SELECT id, name FROM personas ORDER BY id ASC');

        foreach ($statement->fetchAll() as $persona) {
            $personaId = (int) ($persona['id'] ?? 0);

            if ($personaId === $basePersonaId) {
                continue;
            }

            if ($this->normalizePersonaName((string) ($persona['name'] ?? '')) !== $baseName) {
                continue;
            }

            $statement = $this->pdo->prepare(
                'UPDATE chats
                 SET persona_id = :base_persona_id, system_prompt = :system_prompt, updated_at = :updated_at
                 WHERE persona_id = :duplicate_persona_id'
            );
            $statement->execute([
                'base_persona_id' => $basePersonaId,
                'system_prompt' => self::BASE_PERSONA_PROMPT,
                'updated_at' => $now,
                'duplicate_persona_id' => $personaId,
            ]);

            $statement = $this->pdo->prepare('DELETE FROM personas WHERE id = :id');
            $statement->execute(['id' => $personaId]);
        }
    }

    private function moveLegacyPublicPersonasToBase(int $basePersonaId, string $now): void
    {
        $legacyIds = [];
        $statement = $this->pdo->query('SELECT id, name FROM personas WHERE is_public = 1');

        foreach ($statement->fetchAll() as $persona) {
            $id = (int) ($persona['id'] ?? 0);

            if ($id === $basePersonaId) {
                continue;
            }

            if (in_array((string) ($persona['name'] ?? ''), self::LEGACY_PUBLIC_PERSONA_NAMES, true)) {
                $legacyIds[] = $id;
            }
        }

        if ($legacyIds === []) {
            return;
        }

        foreach ($legacyIds as $legacyId) {
            $statement = $this->pdo->prepare(
                'UPDATE chats
                 SET persona_id = :base_persona_id, system_prompt = :system_prompt, updated_at = :updated_at
                 WHERE persona_id = :legacy_persona_id'
            );
            $statement->execute([
                'base_persona_id' => $basePersonaId,
                'system_prompt' => self::BASE_PERSONA_PROMPT,
                'updated_at' => $now,
                'legacy_persona_id' => $legacyId,
            ]);

            $statement = $this->pdo->prepare('DELETE FROM personas WHERE id = :id');
            $statement->execute(['id' => $legacyId]);
        }
    }

    private function findPersonaIdByNormalizedName(string $name): ?int
    {
        $needle = $this->normalizePersonaName($name);
        $statement = $this->pdo->query('SELECT id, name FROM personas ORDER BY id ASC');

        foreach ($statement->fetchAll() as $persona) {
            if ($this->normalizePersonaName((string) ($persona['name'] ?? '')) === $needle) {
                return (int) $persona['id'];
            }
        }

        return null;
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

    private function attachExistingChunksToDocuments(): void
    {
        $sources = $this->pdo
            ->query(
                'SELECT source_name, MIN(created_at) AS created_at
                 FROM document_chunks
                 WHERE document_id IS NULL
                 GROUP BY source_name'
            )
            ->fetchAll();

        foreach ($sources as $source) {
            $sourceName = (string) ($source['source_name'] ?? '');

            if ($sourceName === '') {
                continue;
            }

            $documentId = $this->findOrCreateRagDocument($sourceName, (string) ($source['created_at'] ?? date('Y-m-d H:i:s')));
            $statement = $this->pdo->prepare(
                'UPDATE document_chunks
                 SET document_id = :document_id
                 WHERE source_name = :source_name AND document_id IS NULL'
            );
            $statement->execute([
                'document_id' => $documentId,
                'source_name' => $sourceName,
            ]);
        }
    }

    private function findOrCreateRagDocument(string $sourceName, string $createdAt): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM rag_documents
             WHERE source_name = :source_name AND workspace_id = :workspace_id
             LIMIT 1'
        );
        $statement->execute([
            'source_name' => $sourceName,
            'workspace_id' => $this->defaultWorkspaceId(),
        ]);
        $id = $statement->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO rag_documents (workspace_id, source_name, created_at)
             VALUES (:workspace_id, :source_name, :created_at)'
        );
        $statement->execute([
            'workspace_id' => $this->defaultWorkspaceId(),
            'source_name' => $sourceName,
            'created_at' => $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function defaultWorkspaceId(): int
    {
        $workspaceId = $this->pdo->query('SELECT id FROM workspaces ORDER BY id ASC LIMIT 1')->fetchColumn();

        return $workspaceId === false ? 1 : (int) $workspaceId;
    }

    private function deleteOrphanedAnalyticsDatasets(): void
    {
        $this->pdo->exec(
            'DELETE FROM analytics_datasets
             WHERE NOT EXISTS (
                SELECT 1 FROM rag_documents
                WHERE rag_documents.id = analytics_datasets.document_id
                    AND rag_documents.workspace_id = analytics_datasets.workspace_id
             )'
        );
    }

    private function attachExistingChatsToPersonas(): void
    {
        $chats = $this->pdo
            ->query('SELECT id, system_prompt FROM chats WHERE persona_id IS NULL')
            ->fetchAll();

        foreach ($chats as $chat) {
            $prompt = trim((string) ($chat['system_prompt'] ?? ''));

            if ($prompt === '') {
                continue;
            }

            $personaId = $this->findPersonaIdByPrompt($prompt) ?? $this->createMigratedPersona((int) $chat['id'], $prompt);
            $statement = $this->pdo->prepare('UPDATE chats SET persona_id = :persona_id WHERE id = :id');
            $statement->execute([
                'persona_id' => $personaId,
                'id' => (int) $chat['id'],
            ]);
        }
    }

    private function findPersonaIdByPrompt(string $prompt): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM personas WHERE prompt_content = :prompt_content LIMIT 1');
        $statement->execute(['prompt_content' => $prompt]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function createMigratedPersona(int $chatId, string $prompt): int
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO personas (name, description, prompt_content, is_public, created_at, updated_at)
             VALUES (:name, :description, :prompt_content, 0, :created_at, :updated_at)'
        );
        $statement->execute([
            'name' => 'Persona migrada #' . $chatId,
            'description' => 'Criada automaticamente a partir do prompt salvo nesta conversa.',
            'prompt_content' => $prompt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
