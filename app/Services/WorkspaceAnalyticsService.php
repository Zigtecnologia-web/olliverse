<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;

final readonly class WorkspaceAnalyticsService
{
    private const CHAT_CONTEXT_ROWS = 40;

    public function __construct(
        private PDO $pdo,
        private StructuredDataParser $parser,
        private int $workspaceId,
        private ?string $duckDbDirectory = null,
    ) {
    }

    /**
     * @return array{id: int, source_name: string, table_name: string, columns: array<int, string>, row_count: int, engine: string}|null
     */
    public function registerDocument(int $documentId, string $sourceName, string $content): ?array
    {
        $dataset = $this->parser->parse($sourceName, $content);

        return $this->registerDataset($documentId, $sourceName, $dataset);
    }

    /**
     * @param array{columns: array<int, string>, rows: array<int, array<string, mixed>>, sample: string, row_count?: int}|null $dataset
     * @return array{id: int, source_name: string, table_name: string, columns: array<int, string>, row_count: int, engine: string}|null
     */
    public function registerDataset(int $documentId, string $sourceName, ?array $dataset): ?array
    {
        if ($dataset === null) {
            $this->deleteDocumentDataset($documentId);
            return null;
        }

        $rowCount = (int) ($dataset['row_count'] ?? count($dataset['rows']));
        $tableName = $this->tableName($documentId, $sourceName);
        $now = date('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'SELECT analytics_datasets.id, analytics_datasets.table_name
             FROM analytics_datasets
             INNER JOIN rag_documents
                ON rag_documents.id = analytics_datasets.document_id
                AND rag_documents.workspace_id = analytics_datasets.workspace_id
             WHERE analytics_datasets.workspace_id = :workspace_id
                AND analytics_datasets.document_id = :document_id
             LIMIT 1'
        );
        $statement->execute([
            'workspace_id' => $this->workspaceId,
            'document_id' => $documentId,
        ]);
        $existingDataset = $statement->fetch();
        $id = is_array($existingDataset) ? (int) $existingDataset['id'] : false;
        $payload = [
            'workspace_id' => $this->workspaceId,
            'document_id' => $documentId,
            'source_name' => $sourceName,
            'table_name' => $tableName,
            'columns_json' => json_encode($dataset['columns'], JSON_UNESCAPED_UNICODE),
            'rows_json' => json_encode($dataset['rows'], JSON_UNESCAPED_UNICODE),
            'row_count' => $rowCount,
            'updated_at' => $now,
        ];

        if ($id === false) {
            $statement = $this->pdo->prepare(
                'INSERT INTO analytics_datasets
                    (workspace_id, document_id, source_name, table_name, columns_json, rows_json, row_count, created_at, updated_at)
                 VALUES
                    (:workspace_id, :document_id, :source_name, :table_name, :columns_json, :rows_json, :row_count, :created_at, :updated_at)'
            );
            $statement->execute($payload + ['created_at' => $now]);
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $previousTableName = (string) ($existingDataset['table_name'] ?? '');

            if ($previousTableName !== '' && $previousTableName !== $tableName) {
                $this->dropPersistentTable($previousTableName);
            }

            $statement = $this->pdo->prepare(
                'UPDATE analytics_datasets
                 SET source_name = :source_name,
                     table_name = :table_name,
                     columns_json = :columns_json,
                     rows_json = :rows_json,
                     row_count = :row_count,
                     updated_at = :updated_at
                 WHERE id = :id AND workspace_id = :workspace_id AND document_id = :document_id'
            );
            $statement->execute($payload + ['id' => $id]);
        }

        $this->refreshPersistentTable($tableName, $dataset['columns'], $dataset['rows']);

        return [
            'id' => (int) $id,
            'source_name' => $sourceName,
            'table_name' => $tableName,
            'columns' => $dataset['columns'],
            'row_count' => $rowCount,
            'engine' => $this->engineName(),
        ];
    }

    public function deleteDocumentDataset(int $documentId): void
    {
        $dataset = $this->datasetForDocument($documentId);

        if (is_array($dataset)) {
            $this->dropPersistentTable((string) $dataset['table_name']);
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM analytics_datasets
             WHERE workspace_id = :workspace_id
                AND document_id = :document_id'
        );
        $statement->execute([
            'workspace_id' => $this->workspaceId,
            'document_id' => $documentId,
        ]);
    }

    /**
     * @param array<int, int> $documentIds
     * @return array<int, array{row_count: int, inspected_rows: int, columns: array<int, string>, empty_columns: array<int, array{column: string, empty_count: int, empty_percent: float}>}>
     */
    public function documentSummaries(array $documentIds = []): array
    {
        $documentIds = array_values(array_unique(array_filter($documentIds, static fn (int $id): bool => $id > 0)));
        $this->backfillMissingDatasets($documentIds);

        $summaries = [];

        foreach ($this->datasets($documentIds) as $dataset) {
            $rows = $this->rowsForDocument((int) $dataset['document_id']);
            $summaries[(int) $dataset['document_id']] = [
                'row_count' => (int) $dataset['row_count'],
                'inspected_rows' => count($rows),
                'columns' => $dataset['columns'],
                'empty_columns' => $this->emptyColumnStats($dataset['columns'], $rows),
            ];
        }

        return $summaries;
    }

    /**
     * @param array<int, int> $documentIds
     * @return array<int, array{id: int, document_id: int, source_name: string, table_name: string, columns: array<int, string>, row_count: int, sample: string, engine: string}>
     */
    public function datasets(array $documentIds = []): array
    {
        $documentIds = array_values(array_unique(array_filter($documentIds, static fn (int $id): bool => $id > 0)));
        $sql = 'SELECT analytics_datasets.id,
                       analytics_datasets.document_id,
                       analytics_datasets.source_name,
                       analytics_datasets.table_name,
                       analytics_datasets.columns_json,
                       analytics_datasets.rows_json,
                       analytics_datasets.row_count
                FROM analytics_datasets
                INNER JOIN rag_documents
                    ON rag_documents.id = analytics_datasets.document_id
                    AND rag_documents.workspace_id = analytics_datasets.workspace_id
                WHERE analytics_datasets.workspace_id = :workspace_id';
        $params = ['workspace_id' => $this->workspaceId];

        if ($documentIds !== []) {
            $placeholders = [];

            foreach ($documentIds as $index => $documentId) {
                $parameter = ':document_id_' . $index;
                $placeholders[] = $parameter;
                $params[$parameter] = $documentId;
            }

            $sql .= ' AND analytics_datasets.document_id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY analytics_datasets.updated_at DESC, analytics_datasets.source_name ASC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map(function (array $dataset): array {
            $rows = $this->decodeRows((string) $dataset['rows_json']);
            $columns = $this->decodeColumns((string) $dataset['columns_json']);

            return [
                'id' => (int) $dataset['id'],
                'document_id' => (int) $dataset['document_id'],
                'source_name' => (string) $dataset['source_name'],
                'table_name' => (string) $dataset['table_name'],
                'columns' => $columns,
                'row_count' => (int) $dataset['row_count'],
                'sample' => $this->sampleText($columns, $rows),
                'engine' => $this->engineName(),
            ];
        }, $statement->fetchAll());
    }

    /**
     * @return array{status: string, engine: string, query_executed: string, rows: array<int, array<string, mixed>>, chart_config: array<string, mixed>}
     */
    public function chartPayload(int $documentId, string $sql, string $chartType = 'bar', ?string $title = null): array
    {
        $dataset = $this->datasetForDocument($documentId);

        if ($dataset === null) {
            throw new RuntimeException('Documento sem tabela analítica preparada.');
        }

        $sql = $this->normalizeSql($sql);
        $engine = $this->analyticsPdo();
        $rows = $this->executeSql($engine['pdo'], $dataset, $sql, (bool) $engine['persistent']);
        $chart = $this->chartConfig($rows, $chartType, $title);

        return [
            'status' => 'success',
            'engine' => $engine['name'],
            'query_executed' => $sql,
            'rows' => $rows,
            'chart_config' => $chart,
        ];
    }

    /**
     * @param array<int, int> $documentIds
     */
    public function chatContext(string $question, string $modelName, OllamaClient $ollamaClient, array $documentIds = []): string
    {
        $this->backfillMissingDatasets($documentIds);
        $datasets = $this->datasets($documentIds);

        if ($datasets === [] || trim($question) === '') {
            return '';
        }

        if ($this->isRowCountQuestion($question) || $this->isColumnListQuestion($question)) {
            return $this->datasetCatalogContext($datasets);
        }

        try {
            $selection = $this->selectQueryForQuestion($question, $modelName, $ollamaClient, $datasets);

            if ($selection === null) {
                return $this->datasetCatalogContext($datasets);
            }

            $dataset = $this->datasetByDocumentId($datasets, $selection['document_id']);

            if ($dataset === null) {
                return $this->datasetCatalogContext($datasets);
            }

            $rows = $this->queryRows((int) $dataset['document_id'], $selection['sql'], self::CHAT_CONTEXT_ROWS);

            return $this->queryResultContext($dataset, $selection['sql'], $rows);
        } catch (\Throwable) {
            return $this->datasetCatalogContext($datasets);
        }
    }

    public function augmentSystemPromptWithAnalytics(string $systemPrompt, string $analyticsContext): string
    {
        $analyticsContext = trim($analyticsContext);

        if ($analyticsContext === '') {
            return $systemPrompt;
        }

        return trim($systemPrompt) . "\n\n" . $analyticsContext;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function queryRows(int $documentId, string $sql, int $limit = 200): array
    {
        $dataset = $this->datasetForDocument($documentId);

        if ($dataset === null) {
            throw new RuntimeException('Documento sem tabela analítica preparada.');
        }

        $sql = $this->normalizeSql($sql);
        $engine = $this->analyticsPdo();

        return array_slice($this->executeSql($engine['pdo'], $dataset, $sql, (bool) $engine['persistent']), 0, max(1, $limit));
    }

    private function datasetForDocument(int $documentId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT analytics_datasets.id,
                    analytics_datasets.document_id,
                    analytics_datasets.source_name,
                    analytics_datasets.table_name,
                    analytics_datasets.columns_json,
                    analytics_datasets.rows_json,
                    analytics_datasets.row_count
             FROM analytics_datasets
             INNER JOIN rag_documents
                ON rag_documents.id = analytics_datasets.document_id
                AND rag_documents.workspace_id = analytics_datasets.workspace_id
             WHERE analytics_datasets.workspace_id = :workspace_id
                AND analytics_datasets.document_id = :document_id
             LIMIT 1'
        );
        $statement->execute([
            'workspace_id' => $this->workspaceId,
            'document_id' => $documentId,
        ]);
        $dataset = $statement->fetch();

        return is_array($dataset) ? $dataset : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsForDocument(int $documentId): array
    {
        $dataset = $this->datasetForDocument($documentId);

        return is_array($dataset) ? $this->decodeRows((string) $dataset['rows_json']) : [];
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{column: string, empty_count: int, empty_percent: float}>
     */
    private function emptyColumnStats(array $columns, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $stats = [];
        $rowCount = count($rows);

        foreach ($columns as $column) {
            $emptyCount = 0;

            foreach ($rows as $row) {
                if (!array_key_exists($column, $row) || $this->isEmptyCell($row[$column])) {
                    $emptyCount++;
                }
            }

            if ($emptyCount > 0) {
                $stats[] = [
                    'column' => $column,
                    'empty_count' => $emptyCount,
                    'empty_percent' => round(($emptyCount / $rowCount) * 100, 1),
                ];
            }
        }

        usort(
            $stats,
            static fn (array $left, array $right): int => $right['empty_count'] <=> $left['empty_count']
        );

        return $stats;
    }

    private function isEmptyCell(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @param array<string, mixed> $dataset
     * @return array<int, array<string, mixed>>
     */
    private function executeSql(PDO $pdo, array $dataset, string $sql, bool $persistent = false): array
    {
        $tableName = (string) $dataset['table_name'];
        $columns = $this->decodeColumns((string) $dataset['columns_json']);
        $rows = $this->decodeRows((string) $dataset['rows_json']);
        $this->ensureTable($pdo, $tableName, $columns, $rows, $persistent);

        try {
            $statement = $pdo->query($sql);
            $result = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException $error) {
            throw new RuntimeException($this->friendlySqlError($error, $tableName, $columns));
        } finally {
            if (!$persistent) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($tableName));
            }
        }

        return array_slice($result, 0, 200);
    }

    /**
     * @param array<int, string> $columns
     */
    private function friendlySqlError(PDOException $error, string $tableName, array $columns): string
    {
        $message = $error->getMessage();

        if (preg_match('/no such column:\s*([^\s]+)/i', $message, $matches) === 1) {
            $column = trim((string) $matches[1], '"`[]');

            return sprintf(
                'A sugestão tentou usar a coluna "%s", mas ela não existe nessa tabela. Colunas disponíveis em %s: %s. Gere os insights novamente ou escolha outra sugestão.',
                $column,
                $tableName,
                implode(', ', $columns)
            );
        }

        if (str_contains(strtolower($message), 'no such table')) {
            return sprintf(
                'A sugestão tentou consultar uma tabela inválida. Use a tabela %s e estas colunas: %s.',
                $tableName,
                implode(', ', $columns)
            );
        }

        return 'Não foi possível executar a consulta analítica. Revise a sugestão e tente novamente.';
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function ensureTable(PDO $pdo, string $tableName, array $columns, array $rows, bool $persistent): void
    {
        if ($persistent && $this->duckDbTableExists($pdo, $tableName)) {
            return;
        }

        $this->createTable($pdo, $tableName, $columns, $rows, !$persistent);
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function createTable(PDO $pdo, string $tableName, array $columns, array $rows, bool $temporary): void
    {
        $columnSql = implode(', ', array_map(
            fn (string $column): string => $this->quoteIdentifier($column) . ' ' . $this->columnTypeSql($rows, $column),
            $columns
        ));

        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($tableName));
        $pdo->exec('CREATE ' . ($temporary ? 'TEMP ' : '') . 'TABLE ' . $this->quoteIdentifier($tableName) . ' (' . $columnSql . ')');

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $statement = $pdo->prepare(
            'INSERT INTO ' . $this->quoteIdentifier($tableName)
            . ' (' . implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns)) . ')'
            . ' VALUES (' . $placeholders . ')'
        );

        foreach ($rows as $row) {
            $statement->execute(array_map(static fn (string $column): mixed => $row[$column] ?? null, $columns));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function columnTypeSql(array $rows, string $column): string
    {
        return $this->isNumericColumn($rows, $column) ? 'DOUBLE' : 'VARCHAR';
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function refreshPersistentTable(string $tableName, array $columns, array $rows): void
    {
        if (!$this->supportsDuckDbPdo()) {
            return;
        }

        $this->createTable($this->duckDbPdo(), $tableName, $columns, $rows, false);
    }

    private function dropPersistentTable(string $tableName): void
    {
        if (!$this->supportsDuckDbPdo()) {
            return;
        }

        try {
            $this->duckDbPdo()->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($tableName));
        } catch (PDOException) {
        }
    }

    private function normalizeSql(string $sql): string
    {
        $sql = trim($sql);
        $sql = rtrim($sql, " \t\n\r\0\x0B;");

        if ($sql === '' || preg_match('/^\s*select\b/i', $sql) !== 1) {
            throw new RuntimeException('A consulta analítica precisa começar com SELECT.');
        }

        if (preg_match('/;\s*\S/', $sql) === 1
            || preg_match('/\b(insert|update|delete|drop|alter|create|attach|detach|pragma|vacuum)\b/i', $sql) === 1) {
            throw new RuntimeException('A consulta analítica aceita apenas SELECT.');
        }

        return $sql;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{type: string, labels: array<int, string>, datasets: array<int, array{label: string, data: array<int, float|int>}>}
     */
    private function chartConfig(array $rows, string $chartType, ?string $title): array
    {
        if ($rows === []) {
            throw new RuntimeException('A consulta não retornou linhas para o gráfico.');
        }

        $columns = array_keys($rows[0]);
        [$labelColumn, $metricColumn] = $this->detectChartColumns($rows, $columns);

        if ($metricColumn === null || $labelColumn === null) {
            throw new RuntimeException('A consulta precisa retornar uma coluna de categoria e uma coluna numérica.');
        }

        return [
            'type' => in_array($chartType, ['bar', 'pie', 'line'], true) ? $chartType : 'bar',
            'labels' => array_map(static fn (array $row): string => (string) ($row[$labelColumn] ?? ''), $rows),
            'datasets' => [
                [
                    'label' => $title ?: $this->humanize((string) $metricColumn),
                    'data' => array_map(static fn (array $row): float|int => is_float($row[$metricColumn]) || is_int($row[$metricColumn])
                        ? $row[$metricColumn]
                        : (float) $row[$metricColumn], $rows),
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $columns
     * @return array{0: string|null, 1: string|null}
     */
    private function detectChartColumns(array $rows, array $columns): array
    {
        if (count($columns) >= 2 && $this->isNumericColumn($rows, $columns[1])) {
            return [$columns[0], $columns[1]];
        }

        $metricColumn = $this->detectMetricColumn($rows, $columns);

        return [
            $this->detectLabelColumn($rows, $columns, $metricColumn),
            $metricColumn,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $columns
     */
    private function detectMetricColumn(array $rows, array $columns): ?string
    {
        $candidates = array_values(array_filter(
            $columns,
            fn (string $column): bool => $this->isNumericColumn($rows, $column)
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (string $left, string $right): int {
            $preferred = ['total', 'valor', 'quantidade', 'qtd', 'count', 'media', 'soma'];
            $leftScore = array_reduce($preferred, static fn (int $score, string $word): int => $score + (str_contains(strtolower($left), $word) ? 1 : 0), 0);
            $rightScore = array_reduce($preferred, static fn (int $score, string $word): int => $score + (str_contains(strtolower($right), $word) ? 1 : 0), 0);

            return $rightScore <=> $leftScore;
        });

        return $candidates[0];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function isNumericColumn(array $rows, string $column): bool
    {
        $numericRows = array_filter($rows, static fn (array $row): bool => is_numeric($row[$column] ?? null));

        return count($numericRows) >= max(1, (int) ceil(count($rows) * 0.7));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $columns
     */
    private function detectLabelColumn(array $rows, array $columns, ?string $metricColumn): ?string
    {
        foreach ($columns as $column) {
            if ($column === $metricColumn) {
                continue;
            }

            if (array_filter($rows, static fn (array $row): bool => trim((string) ($row[$column] ?? '')) !== '') !== []) {
                return $column;
            }
        }

        return null;
    }

    private function tableName(int $documentId, string $sourceName): string
    {
        $base = strtolower(pathinfo($sourceName, PATHINFO_FILENAME));
        $base = preg_replace('/[^a-z0-9_]+/', '_', $base) ?? 'dataset';
        $base = trim($base, '_') ?: 'dataset';

        return 'dataset_' . $documentId . '_' . substr($base, 0, 32);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function engineName(): string
    {
        return $this->supportsDuckDbPdo() ? 'duckdb-pdo' : 'sqlite-fallback';
    }

    /**
     * @return array{name: string, pdo: PDO, persistent: bool}
     */
    private function analyticsPdo(): array
    {
        if ($this->supportsDuckDbPdo()) {
            return [
                'name' => 'duckdb-pdo',
                'pdo' => $this->duckDbPdo(),
                'persistent' => true,
            ];
        }

        return [
            'name' => 'sqlite-fallback',
            'pdo' => $this->pdo,
            'persistent' => false,
        ];
    }

    private function supportsDuckDbPdo(): bool
    {
        return in_array('duckdb', PDO::getAvailableDrivers(), true);
    }

    private function duckDbPdo(): PDO
    {
        $path = $this->duckDbPath();
        $options = [];

        if (defined('PDO::DUCKDB_ATTR_CONFIG')) {
            $options[PDO::DUCKDB_ATTR_CONFIG] = [
                'extension_directory' => $this->duckDbSupportDirectory('extensions'),
                'temp_directory' => $this->duckDbSupportDirectory('tmp'),
            ];
        }

        $pdo = new PDO('duckdb:' . $path, null, null, $options);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    private function duckDbPath(): string
    {
        $directory = $this->duckDbDirectory ?: dirname(__DIR__, 2) . '/storage/analytics';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório analítico do DuckDB.');
        }

        return rtrim($directory, '/\\') . '/workspace_' . $this->workspaceId . '.duckdb';
    }

    private function duckDbSupportDirectory(string $name): string
    {
        $directory = rtrim(dirname($this->duckDbPath()), '/\\') . '/' . $name;

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório de suporte do DuckDB.');
        }

        return $directory;
    }

    private function duckDbTableExists(PDO $pdo, string $tableName): bool
    {
        try {
            $statement = $pdo->prepare('SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_name = ?');
            $statement->execute([$tableName]);

            return (int) $statement->fetchColumn() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @param array<int, int> $documentIds
     */
    private function backfillMissingDatasets(array $documentIds): void
    {
        $documentIds = array_values(array_unique(array_filter($documentIds, static fn (int $id): bool => $id > 0)));

        if ($documentIds === []) {
            return;
        }

        $placeholders = [];
        $params = ['workspace_id' => $this->workspaceId];

        foreach ($documentIds as $index => $documentId) {
            $parameter = ':document_id_' . $index;
            $placeholders[] = $parameter;
            $params[$parameter] = $documentId;
        }

        $statement = $this->pdo->prepare(
            'SELECT rag_documents.id, rag_documents.source_name
             FROM rag_documents
             LEFT JOIN analytics_datasets
                ON analytics_datasets.document_id = rag_documents.id
                AND analytics_datasets.workspace_id = rag_documents.workspace_id
             WHERE rag_documents.workspace_id = :workspace_id
                AND rag_documents.id IN (' . implode(', ', $placeholders) . ')
                AND analytics_datasets.id IS NULL'
        );
        $statement->execute($params);

        foreach ($statement->fetchAll() as $document) {
            $documentId = (int) $document['id'];
            $sourceName = (string) $document['source_name'];

            if (!$this->isStructuredSource($sourceName)) {
                continue;
            }

            $content = $this->reconstructDocumentContent($documentId);
            $dataset = $this->parser->parse($sourceName, $content);

            if ($dataset !== null) {
                $this->registerDataset($documentId, $sourceName, $dataset);
            }
        }
    }

    private function isStructuredSource(string $sourceName): bool
    {
        return in_array(strtolower(pathinfo($sourceName, PATHINFO_EXTENSION)), ['csv', 'json', 'xls', 'xlsx'], true);
    }

    private function reconstructDocumentContent(int $documentId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT document_chunks.content
             FROM document_chunks
             INNER JOIN rag_documents ON rag_documents.id = document_chunks.document_id
             WHERE document_chunks.document_id = :document_id
                AND rag_documents.workspace_id = :workspace_id
             ORDER BY document_chunks.id ASC'
        );
        $statement->execute([
            'document_id' => $documentId,
            'workspace_id' => $this->workspaceId,
        ]);

        $content = '';

        foreach ($statement->fetchAll() as $chunk) {
            $content = $this->appendChunkContent($content, (string) $chunk['content']);
        }

        return $content;
    }

    private function appendChunkContent(string $content, string $chunk): string
    {
        $chunk = trim($chunk);

        if ($chunk === '') {
            return $content;
        }

        if ($content === '') {
            return $chunk;
        }

        $maxOverlap = min(300, strlen($content), strlen($chunk));

        for ($length = $maxOverlap; $length > 0; $length--) {
            if (substr($content, -$length) === substr($chunk, 0, $length)) {
                return $content . substr($chunk, $length);
            }
        }

        return rtrim($content) . "\n\n" . $chunk;
    }

    /**
     * @param array<int, array{id: int, document_id: int, source_name: string, table_name: string, columns: array<int, string>, row_count: int, sample: string, engine: string}> $datasets
     * @return array{document_id: int, sql: string}|null
     */
    private function selectQueryForQuestion(string $question, string $modelName, OllamaClient $ollamaClient, array $datasets): ?array
    {
        $raw = $ollamaClient->generate($modelName, $this->querySelectionPrompt($question, $datasets));
        $payload = json_decode($this->extractJsonObject($raw), true);

        if (!is_array($payload)) {
            return null;
        }

        $documentId = (int) ($payload['document_id'] ?? 0);
        $sql = trim((string) ($payload['sql'] ?? ''));
        $allowedTables = array_map(static fn (array $dataset): string => (string) $dataset['table_name'], $datasets);

        if ($documentId <= 0 || $sql === '' || $this->datasetByDocumentId($datasets, $documentId) === null) {
            return null;
        }

        $sql = $this->normalizeSql($sql);
        preg_match_all('/\bdataset_[a-z0-9_]+\b/i', $sql, $matches);
        $usedTables = array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
        $allowedTables = array_map('strtolower', $allowedTables);

        if ($usedTables === [] || array_diff($usedTables, $allowedTables) !== []) {
            return null;
        }

        return [
            'document_id' => $documentId,
            'sql' => $sql,
        ];
    }

    /**
     * @param array<int, array{id: int, document_id: int, source_name: string, table_name: string, columns: array<int, string>, row_count: int, sample: string, engine: string}> $datasets
     */
    private function querySelectionPrompt(string $question, array $datasets): string
    {
        $blocks = array_map(static function (array $dataset): string {
            return implode("\n", [
                'document_id: ' . $dataset['document_id'],
                'source_name: ' . $dataset['source_name'],
                'table_name: ' . $dataset['table_name'],
                'row_count: ' . $dataset['row_count'],
                'columns: ' . implode(', ', $dataset['columns']),
                $dataset['sample'],
            ]);
        }, $datasets);

        return trim(implode("\n", [
            'Você gera uma consulta SQL DuckDB para responder a pergunta do usuário usando apenas os datasets selecionados.',
            'Responda exclusivamente JSON válido, sem Markdown e sem texto adicional.',
            'Formato exato: {"document_id": 1, "sql": "SELECT ..."}',
            'Regras:',
            '- Use apenas SELECT.',
            '- Use exatamente um dos table_name informados.',
            '- Copie nomes de colunas exatamente como aparecem em columns.',
            '- Para perguntas de contagem, soma, média, ranking ou distribuição, agregue no SQL.',
            '- Retorne no máximo 40 linhas, usando LIMIT quando a consulta puder retornar muitas linhas.',
            '- Se a pergunta não puder ser respondida pelos datasets, use {"document_id": 0, "sql": ""}.',
            '',
            'Pergunta do usuário:',
            $question,
            '',
            'Datasets selecionados:',
            implode("\n\n---\n\n", $blocks),
        ]));
    }

    /**
     * @param array<int, array{id: int, document_id: int, source_name: string, table_name: string, columns: array<int, string>, row_count: int, sample: string, engine: string}> $datasets
     */
    private function datasetCatalogContext(array $datasets): string
    {
        $lines = [
            'Contexto analítico local disponível:',
            'Há documentos estruturados selecionados, mas nenhuma consulta DuckDB segura foi executada antes desta resposta.',
            'Para perguntas sobre quantidade de linhas/registros, responda diretamente usando o campo "linhas" abaixo.',
            'Para perguntas sobre colunas/campos, responda diretamente copiando a lista real do campo "colunas" abaixo. Nunca diga que a lista é hipotética.',
        ];

        foreach ($datasets as $dataset) {
            $lines[] = sprintf(
                '- %s: tabela %s, colunas: %s, linhas: %d, motor: %s',
                (string) $dataset['source_name'],
                (string) $dataset['table_name'],
                implode(', ', $dataset['columns']),
                (int) $dataset['row_count'],
                (string) $dataset['engine']
            );
        }

        return implode("\n", $lines);
    }

    private function isRowCountQuestion(string $question): bool
    {
        $question = $this->normalizeQuestionText($question);

        return preg_match('/\b(quantas?|total|numero|qtd|quantidade)\b.*\b(linhas?|registros?|rows?)\b/', $question) === 1
            || preg_match('/\b(linhas?|registros?|rows?)\b.*\b(quantas?|total|numero|qtd|quantidade)\b/', $question) === 1;
    }

    private function isColumnListQuestion(string $question): bool
    {
        $question = $this->normalizeQuestionText($question);

        return preg_match('/\b(quais?|listar?|liste|mostre|mostrar|nomes?)\b.*\b(colunas?|campos?|fields?|columns?)\b/', $question) === 1
            || preg_match('/\b(colunas?|campos?|fields?|columns?)\b.*\b(quais?|listar?|liste|mostre|mostrar|nomes?)\b/', $question) === 1;
    }

    private function normalizeQuestionText(string $question): string
    {
        $question = strtr(trim($question), [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ç' => 'C', 'ç' => 'c',
        ]);

        return strtolower($question);
    }

    /**
     * @param array{id: int, document_id: int, source_name: string, table_name: string, columns: array<int, string>, row_count: int, sample: string, engine: string} $dataset
     * @param array<int, array<string, mixed>> $rows
     */
    private function queryResultContext(array $dataset, string $sql, array $rows): string
    {
        $rowsJson = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return trim(implode("\n", [
            'Contexto analítico local executado via ' . $dataset['engine'] . ':',
            'Documento: ' . $dataset['source_name'],
            'Tabela: ' . $dataset['table_name'],
            'SQL executado:',
            '```sql',
            $sql,
            '```',
            'Resultado da consulta:',
            '```json',
            $rowsJson ?: '[]',
            '```',
            'Use esse resultado estruturado quando ele for relevante para responder. Cite o nome da fonte/documento quando usar os dados.',
        ]));
    }

    /**
     * @param array<int, array{id: int, document_id: int, source_name: string, table_name: string, columns: array<int, string>, row_count: int, sample: string, engine: string}> $datasets
     * @return array{id: int, document_id: int, source_name: string, table_name: string, columns: array<int, string>, row_count: int, sample: string, engine: string}|null
     */
    private function datasetByDocumentId(array $datasets, int $documentId): ?array
    {
        foreach ($datasets as $dataset) {
            if ((int) $dataset['document_id'] === $documentId) {
                return $dataset;
            }
        }

        return null;
    }

    private function extractJsonObject(string $value): string
    {
        $start = strpos($value, '{');

        if ($start === false) {
            return trim($value);
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($value);

        for ($index = $start; $index < $length; $index++) {
            $char = $value[$index];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = $inString;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($value, $start, $index - $start + 1);
                }
            }
        }

        return trim(substr($value, $start));
    }

    /**
     * @return array<int, string>
     */
    private function decodeColumns(string $json): array
    {
        $columns = json_decode($json, true);

        return is_array($columns) ? array_values(array_map(static fn (mixed $column): string => (string) $column, $columns)) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeRows(string $json): array
    {
        $rows = json_decode($json, true);

        return is_array($rows) ? array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row))) : [];
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function sampleText(array $columns, array $rows): string
    {
        $lines = [
            'Colunas: ' . implode(', ', $columns),
            'Amostras:',
        ];

        foreach (array_slice($rows, 0, 8) as $row) {
            $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return implode("\n", $lines);
    }

    private function humanize(string $value): string
    {
        $value = str_replace('_', ' ', trim($value));

        return $value === '' ? 'Valor' : mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }
}
