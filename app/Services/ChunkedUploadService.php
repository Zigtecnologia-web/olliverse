<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final readonly class ChunkedUploadService
{
    private const MAX_CHUNK_BYTES = 3 * 1024 * 1024;
    private const MAX_TOTAL_CHUNKS = 1000;

    public function __construct(private string $basePath)
    {
    }

    /**
     * @return array{complete: bool, path: string|null, name: string, received: int, total: int}
     */
    public function receive(array $files, array $post): array
    {
        $uploadId = $this->uploadId((string) ($post['upload_id'] ?? ''));
        $fileName = basename((string) ($post['file_name'] ?? 'dados.csv'));
        $chunkIndex = (int) ($post['chunk_index'] ?? -1);
        $totalChunks = (int) ($post['total_chunks'] ?? 0);
        $chunk = $files['chunk'] ?? null;

        if ($fileName === '' || !str_ends_with(strtolower($fileName), '.csv')) {
            throw new InvalidArgumentException('Uploads grandes em lotes aceitam CSV.');
        }

        if ($chunkIndex < 0 || $totalChunks <= 0 || $totalChunks > self::MAX_TOTAL_CHUNKS || $chunkIndex >= $totalChunks) {
            throw new InvalidArgumentException('Lote de upload inválido.');
        }

        if (!is_array($chunk) || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Chunk não recebido pelo servidor.');
        }

        $size = (int) ($chunk['size'] ?? 0);

        if ($size <= 0 || $size > self::MAX_CHUNK_BYTES) {
            throw new InvalidArgumentException('Chunk fora do tamanho permitido.');
        }

        $tmpName = (string) ($chunk['tmp_name'] ?? '');

        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('Não foi possível validar o chunk enviado.');
        }

        $uploadPath = $this->uploadPath($uploadId);
        $this->ensureDirectory($uploadPath);
        $this->writeMetadata($uploadPath, $fileName, $totalChunks);

        $partPath = $this->partPath($uploadPath, $chunkIndex);

        if (!move_uploaded_file($tmpName, $partPath)) {
            throw new RuntimeException('Não foi possível salvar o chunk recebido.');
        }

        $received = $this->receivedChunks($uploadPath, $totalChunks);

        if ($received < $totalChunks) {
            return [
                'complete' => false,
                'path' => null,
                'name' => $fileName,
                'received' => $received,
                'total' => $totalChunks,
            ];
        }

        $finalPath = $this->assemble($uploadPath, $fileName, $totalChunks);

        return [
            'complete' => true,
            'path' => $finalPath,
            'name' => $fileName,
            'received' => $received,
            'total' => $totalChunks,
        ];
    }

    public function cleanup(string $path): void
    {
        $directory = dirname($path);

        if (!str_starts_with($directory, rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    private function uploadId(string $uploadId): string
    {
        $uploadId = trim($uploadId);

        if ($uploadId === '' || preg_match('/^[a-zA-Z0-9_-]{12,80}$/', $uploadId) !== 1) {
            throw new InvalidArgumentException('Identificador de upload inválido.');
        }

        return $uploadId;
    }

    private function uploadPath(string $uploadId): string
    {
        return rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $uploadId;
    }

    private function partPath(string $uploadPath, int $chunkIndex): string
    {
        return $uploadPath . DIRECTORY_SEPARATOR . sprintf('part_%06d.chunk', $chunkIndex);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($this->basePath) && !mkdir($this->basePath, 0775, true) && !is_dir($this->basePath)) {
            throw new RuntimeException('Não foi possível preparar a pasta de uploads temporários.');
        }

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Não foi possível preparar o upload temporário.');
        }
    }

    private function writeMetadata(string $uploadPath, string $fileName, int $totalChunks): void
    {
        $metadataPath = $uploadPath . DIRECTORY_SEPARATOR . 'metadata.json';
        $metadata = [
            'file_name' => $fileName,
            'total_chunks' => $totalChunks,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if (is_file($metadataPath)) {
            $current = json_decode((string) file_get_contents($metadataPath), true);

            if (is_array($current)
                && (($current['file_name'] ?? '') !== $fileName || (int) ($current['total_chunks'] ?? 0) !== $totalChunks)) {
                throw new InvalidArgumentException('Metadados do upload não conferem.');
            }

            return;
        }

        file_put_contents($metadataPath, json_encode($metadata, JSON_UNESCAPED_UNICODE));
    }

    private function receivedChunks(string $uploadPath, int $totalChunks): int
    {
        $received = 0;

        for ($index = 0; $index < $totalChunks; $index++) {
            if (is_file($this->partPath($uploadPath, $index))) {
                $received++;
            }
        }

        return $received;
    }

    private function assemble(string $uploadPath, string $fileName, int $totalChunks): string
    {
        $finalPath = $uploadPath . DIRECTORY_SEPARATOR . 'complete_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $fileName);
        $target = fopen($finalPath, 'wb');

        if ($target === false) {
            throw new RuntimeException('Não foi possível criar o arquivo final.');
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $partPath = $this->partPath($uploadPath, $index);
                $source = fopen($partPath, 'rb');

                if ($source === false) {
                    throw new RuntimeException('Um chunk esperado não foi encontrado.');
                }

                stream_copy_to_stream($source, $target);
                fclose($source);
            }
        } finally {
            fclose($target);
        }

        return $finalPath;
    }
}
