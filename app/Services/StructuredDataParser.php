<?php

declare(strict_types=1);

namespace App\Services;

final readonly class StructuredDataParser
{
    private const MAX_ROWS = 5000;

    /**
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, sample: string, row_count?: int}|null
     */
    public function parse(string $sourceName, string $content): ?array
    {
        $extension = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => $this->parseCsv($content),
            'json' => $this->parseJson($content),
            'xls', 'xlsx' => $this->parseSpreadsheetText($content),
            default => null,
        };
    }

    /**
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, sample: string, row_count?: int}|null
     */
    public function parseFile(string $sourceName, string $filePath): ?array
    {
        $extension = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            $content = file_get_contents($filePath);

            return is_string($content) ? $this->parse($sourceName, $content) : null;
        }

        return $this->parseCsvFile($filePath);
    }

    /**
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, sample: string, row_count?: int}|null
     */
    private function parseCsv(string $content): ?array
    {
        $content = trim($content);

        if ($content === '') {
            return null;
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return null;
        }

        fwrite($handle, $content);
        rewind($handle);

        $delimiter = $this->detectDelimiter($content);
        $header = fgetcsv($handle, 0, $delimiter, '"', '');

        if (!is_array($header) || count($header) < 2) {
            fclose($handle);
            return null;
        }

        $headerCount = count($header);
        $columns = $this->normalizeColumns(array_map(static fn (mixed $column): string => (string) $column, $header));
        $rows = [];

        $rowCount = 0;

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if (!is_array($row) || $this->isEmptyRow($row)) {
                continue;
            }

            if (count($row) !== $headerCount) {
                continue;
            }

            $rowCount++;

            if (count($rows) < self::MAX_ROWS) {
                $rows[] = $this->combineRow($columns, $row);
            }
        }

        fclose($handle);

        if ($rows === []) {
            return null;
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'sample' => $this->sampleText($columns, $rows),
            'row_count' => $rowCount,
        ];
    }

    /**
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, sample: string, row_count?: int}|null
     */
    private function parseCsvFile(string $filePath): ?array
    {
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            return null;
        }

        $firstLine = fgets($handle);

        if (!is_string($firstLine) || trim($firstLine) === '') {
            fclose($handle);
            return null;
        }

        rewind($handle);
        $delimiter = $this->detectDelimiter($firstLine);
        $header = fgetcsv($handle, 0, $delimiter, '"', '');

        if (!is_array($header) || count($header) < 2) {
            fclose($handle);
            return null;
        }

        $headerCount = count($header);
        $columns = $this->normalizeColumns(array_map(static fn (mixed $column): string => (string) $column, $header));
        $rows = [];

        $rowCount = 0;

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if (!is_array($row) || $this->isEmptyRow($row) || count($row) !== $headerCount) {
                continue;
            }

            $rowCount++;

            if (count($rows) < self::MAX_ROWS) {
                $rows[] = $this->combineRow($columns, $row);
            }
        }

        fclose($handle);

        if ($rows === []) {
            return null;
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'sample' => $this->sampleText($columns, $rows),
            'row_count' => $rowCount,
        ];
    }

    /**
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, sample: string, row_count?: int}|null
     */
    private function parseJson(string $content): ?array
    {
        $payload = json_decode(trim($content), true);

        if (!is_array($payload)) {
            return null;
        }

        $rows = $this->jsonRows($payload);

        if ($rows === []) {
            return null;
        }

        $columns = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        $columns = $this->normalizeColumns($columns);
        $normalizedRows = array_slice(array_map(
            fn (array $row): array => $this->combineRow($columns, $row),
            $rows
        ), 0, self::MAX_ROWS);

        return [
            'columns' => $columns,
            'rows' => $normalizedRows,
            'sample' => $this->sampleText($columns, $normalizedRows),
            'row_count' => count($rows),
        ];
    }

    /**
     * Parses the text export generated in the browser for XLS/XLSX uploads.
     *
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, sample: string, row_count?: int}|null
     */
    private function parseSpreadsheetText(string $content): ?array
    {
        $blocks = preg_split('/\R{2,}/', trim($content)) ?: [];
        $columns = [];
        $rows = [];
        $rowCount = 0;

        foreach ($blocks as $block) {
            $csvLines = array_values(array_filter(
                preg_split('/\R/', trim($block)) ?: [],
                static fn (string $line): bool => !str_starts_with($line, 'Arquivo: ')
                    && !str_starts_with($line, 'Aba: ')
                    && trim($line) !== ''
            ));

            if (count($csvLines) < 2) {
                continue;
            }

            $dataset = $this->parseCsv(implode("\n", $csvLines));

            if ($dataset === null) {
                continue;
            }

            if ($columns === []) {
                $columns = $dataset['columns'];
            }

            if ($dataset['columns'] !== $columns) {
                continue;
            }

            $rowCount += (int) ($dataset['row_count'] ?? count($dataset['rows']));

            if (count($rows) < self::MAX_ROWS) {
                $rows = array_slice(array_merge($rows, $dataset['rows']), 0, self::MAX_ROWS);
            }
        }

        if ($columns === [] || $rows === []) {
            return null;
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'sample' => $this->sampleText($columns, $rows),
            'row_count' => $rowCount,
        ];
    }

    private function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\n") ?: $content;
        $candidates = [',', ';', "\t", '|'];
        $bestDelimiter = ',';
        $bestCount = 0;

        foreach ($candidates as $delimiter) {
            $count = substr_count($firstLine, $delimiter);

            if ($count > $bestCount) {
                $bestDelimiter = $delimiter;
                $bestCount = $count;
            }
        }

        return $bestDelimiter;
    }

    /**
     * @param array<int, mixed> $row
     */
    private function isEmptyRow(array $row): bool
    {
        return trim(implode('', array_map(static fn (mixed $value): string => (string) $value, $row))) === '';
    }

    /**
     * @param array<int, string> $columns
     * @param array<int|string, mixed> $row
     * @return array<string, mixed>
     */
    private function combineRow(array $columns, array $row): array
    {
        $combined = [];

        foreach ($columns as $index => $column) {
            $value = is_int($index) ? ($row[$index] ?? null) : null;

            if ($value === null && array_key_exists($column, $row)) {
                $value = $row[$column];
            }

            $combined[$column] = $this->normalizeValue($value);
        }

        return $combined;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        $number = str_replace(' ', '', $text);

        if (str_contains($number, ',') && str_contains($number, '.')) {
            $number = strrpos($number, ',') > strrpos($number, '.')
                ? str_replace('.', '', str_replace(',', '.', $number))
                : str_replace(',', '', $number);
        } elseif (str_contains($number, ',')) {
            $number = str_replace(',', '.', $number);
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $number) === 1) {
            return str_contains($number, '.') ? (float) $number : (int) $number;
        }

        return $text;
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function normalizeColumns(array $columns): array
    {
        $used = [];

        return array_map(function (string $column, int $index) use (&$used): string {
            $name = trim($column) !== '' ? trim($column) : 'coluna_' . ($index + 1);
            $transliterated = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : false;
            $name = $transliterated === false ? $name : $transliterated;
            $name = preg_replace('/[^\p{L}\p{N}_]+/u', '_', $name) ?? $name;
            $name = trim($name, '_') ?: 'coluna_' . ($index + 1);
            $base = $name;
            $suffix = 2;

            while (in_array(mb_strtolower($name), $used, true)) {
                $name = $base . '_' . $suffix;
                $suffix++;
            }

            $used[] = mb_strtolower($name);

            return $name;
        }, $columns, array_keys($columns));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function jsonRows(array $payload): array
    {
        if (array_is_list($payload)) {
            return array_values(array_filter($payload, static fn (mixed $row): bool => is_array($row) && !array_is_list($row)));
        }

        foreach ($payload as $value) {
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, static fn (mixed $row): bool => is_array($row) && !array_is_list($row)));
            }
        }

        return [$payload];
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function sampleText(array $columns, array $rows): string
    {
        $sampleRows = array_slice($rows, 0, 12);
        $lines = [
            'Colunas: ' . implode(', ', $columns),
            'Amostras:',
        ];

        foreach ($sampleRows as $row) {
            $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return implode("\n", $lines);
    }
}
