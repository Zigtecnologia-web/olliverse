<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;

final readonly class SqliteWorkspaceRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array{id: int, name: string, icon: string, created_at: string}>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, icon, created_at
             FROM workspaces
             ORDER BY id ASC'
        );

        return array_map(
            static fn (array $workspace): array => self::normalize($workspace),
            $statement->fetchAll()
        );
    }

    /**
     * @return array{id: int, name: string, icon: string, created_at: string}
     */
    public function default(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, icon, created_at
             FROM workspaces
             ORDER BY id ASC
             LIMIT 1'
        );
        $workspace = $statement->fetch();

        if (!is_array($workspace)) {
            throw new RuntimeException('Workspace padrao nao encontrado.');
        }

        return self::normalize($workspace);
    }

    /**
     * @return array{id: int, name: string, icon: string, created_at: string}|null
     */
    public function find(int $workspaceId): ?array
    {
        if ($workspaceId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, name, icon, created_at
             FROM workspaces
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $workspaceId]);
        $workspace = $statement->fetch();

        return is_array($workspace) ? self::normalize($workspace) : null;
    }

    /**
     * @return array{id: int, name: string, icon: string, created_at: string}
     */
    public function create(string $name, string $icon): array
    {
        $name = $this->normalizeName($name);
        $icon = $this->normalizeIcon($icon);
        $now = date('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO workspaces (name, icon, created_at)
             VALUES (:name, :icon, :created_at)'
        );
        $statement->execute([
            'name' => $name,
            'icon' => $icon,
            'created_at' => $now,
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'name' => $name,
            'icon' => $icon,
            'created_at' => $now,
        ];
    }

    /**
     * @param array<string, mixed> $workspace
     * @return array{id: int, name: string, icon: string, created_at: string}
     */
    private static function normalize(array $workspace): array
    {
        return [
            'id' => (int) $workspace['id'],
            'name' => (string) $workspace['name'],
            'icon' => (string) ($workspace['icon'] ?? ''),
            'created_at' => (string) ($workspace['created_at'] ?? ''),
        ];
    }

    private function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        if ($name === '') {
            throw new RuntimeException('Informe um nome para o workspace.');
        }

        if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 36) {
            $name = mb_substr($name, 0, 36, 'UTF-8');
        } elseif (!function_exists('mb_strlen') && strlen($name) > 36) {
            $name = substr($name, 0, 36);
        }

        return $name;
    }

    private function normalizeIcon(string $icon): string
    {
        $icon = trim($icon);

        if ($icon === '') {
            return '#';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($icon, 0, 3, 'UTF-8');
        }

        return substr($icon, 0, 3);
    }
}
