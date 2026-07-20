<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final readonly class SqliteMigrator
{
    public function __construct(private PDO $pdo)
    {
    }

    public function migrate(): void
    {
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

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                role TEXT NOT NULL CHECK (role IN (\'system\', \'user\', \'assistant\')),
                content TEXT NOT NULL,
                token_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE
            )'
        );

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
                source_name TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL
            )'
        );

        if (!$this->hasColumn('document_chunks', 'document_id')) {
            $this->pdo->exec('ALTER TABLE document_chunks ADD COLUMN document_id INTEGER NULL');
        }

        $this->attachExistingChunksToDocuments();

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_chat_id_id ON messages(chat_id, id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chats_updated_at ON chats(updated_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chats_persona_id ON chats(persona_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_document_chunks_document_id ON document_chunks(document_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_document_chunks_source_name ON document_chunks(source_name)');

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

    private function seedPersonas(): void
    {
        $now = date('Y-m-d H:i:s');
        $personas = [
            [
                'name' => 'Assistente técnico prestativo',
                'description' => 'Ajuda geral com respostas claras, úteis e objetivas.',
                'prompt_content' => 'Você é um assistente técnico prestativo.',
            ],
            [
                'name' => 'Assistente de Código',
                'description' => 'Apoia arquitetura, depuração e implementação com cuidado técnico.',
                'prompt_content' => 'Você é um assistente de código sênior. Ajude com soluções claras, seguras e objetivas, priorizando PHP, arquitetura limpa, depuração cuidadosa e exemplos práticos quando necessário.',
            ],
            [
                'name' => 'Escritor',
                'description' => 'Revisa, estrutura e melhora textos com fluidez e precisão.',
                'prompt_content' => 'Você é um escritor cuidadoso. Ajude a revisar, estruturar e melhorar textos com clareza, fluidez, precisão e tom adequado ao público.',
            ],
            [
                'name' => 'Analista de Dados',
                'description' => 'Interpreta informações, métricas e hipóteses com raciocínio estatístico.',
                'prompt_content' => 'Você é um analista de dados. Ajude a interpretar informações, criar hipóteses, explicar métricas e propor análises com raciocínio estatístico claro.',
            ],
        ];

        $statement = $this->pdo->prepare(
            'INSERT INTO personas (name, description, prompt_content, is_public, created_at, updated_at)
             SELECT :name, :description, :prompt_content, 1, :created_at, :updated_at
             WHERE NOT EXISTS (SELECT 1 FROM personas WHERE prompt_content = :prompt_content LIMIT 1)'
        );

        foreach ($personas as $persona) {
            $statement->execute([
                'name' => $persona['name'],
                'description' => $persona['description'],
                'prompt_content' => $persona['prompt_content'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function attachExistingChunksToDocuments(): void
    {
        $sources = $this->pdo
            ->query('SELECT source_name, MIN(created_at) AS created_at FROM document_chunks GROUP BY source_name')
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
        $statement = $this->pdo->prepare('SELECT id FROM rag_documents WHERE source_name = :source_name LIMIT 1');
        $statement->execute(['source_name' => $sourceName]);
        $id = $statement->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO rag_documents (source_name, created_at)
             VALUES (:source_name, :created_at)'
        );
        $statement->execute([
            'source_name' => $sourceName,
            'created_at' => $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
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
