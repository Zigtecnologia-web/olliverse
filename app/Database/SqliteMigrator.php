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
            'CREATE TABLE IF NOT EXISTS chats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                model_used TEXT NOT NULL,
                system_prompt TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

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

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_chat_id_id ON messages(chat_id, id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chats_updated_at ON chats(updated_at)');
    }
}
