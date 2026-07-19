<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final readonly class SqliteConnection
{
    public function __construct(private string $databasePath)
    {
    }

    public function pdo(): PDO
    {
        $directory = dirname($this->databasePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $this->databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
