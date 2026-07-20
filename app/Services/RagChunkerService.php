<?php

declare(strict_types=1);

namespace App\Services;

final class RagChunkerService
{
    public function __construct(
        private int $minimumCharacters = 500,
        private int $maximumCharacters = 1500,
        private int $overlapCharacters = 50,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function chunk(string $text): array
    {
        $normalizedText = trim(preg_replace("/\r\n?/", "\n", $text) ?? $text);

        if ($normalizedText === '') {
            return [];
        }

        $paragraphs = preg_split("/\n{2,}/", $normalizedText) ?: [];
        $chunks = [];
        $currentChunk = '';

        foreach ($paragraphs as $paragraphText) {
            $paragraph = trim((string) $paragraphText);

            if ($paragraph === '') {
                continue;
            }

            foreach ($this->splitLongText($paragraph) as $paragraphPart) {
                $currentChunk = $this->appendChunkPart($chunks, $currentChunk, $paragraphPart);
            }
        }

        if (trim($currentChunk) !== '' && !in_array(trim($currentChunk), $chunks, true)) {
            $chunks[] = trim($currentChunk);
        }

        return array_values(array_filter($chunks));
    }

    /**
     * @param array<int, string> $chunks
     */
    private function appendChunkPart(array &$chunks, string $currentChunk, string $paragraph): string
    {
        if ($this->length($paragraph) > $this->maximumCharacters) {
            foreach ($this->splitLongText($paragraph) as $paragraphPart) {
                $currentChunk = $this->appendChunkPart($chunks, $currentChunk, $paragraphPart);
            }

            return $currentChunk;
        }

        if ($currentChunk === '') {
            return $paragraph;
        }

        $candidate = trim($currentChunk . "\n\n" . $paragraph);

        if ($this->length($candidate) <= $this->maximumCharacters) {
            return $candidate;
        }

        if (trim($currentChunk) !== '') {
            $chunks[] = trim($currentChunk);
        }

        $candidateWithOverlap = trim($this->tail($currentChunk) . "\n\n" . $paragraph);

        if ($this->length($candidateWithOverlap) <= $this->maximumCharacters) {
            return $candidateWithOverlap;
        }

        return $paragraph;
    }

    /**
     * @return array<int, string>
     */
    private function splitLongText(string $text): array
    {
        if ($this->length($text) <= $this->maximumCharacters) {
            return [$text];
        }

        $parts = [];
        $step = max(1, $this->maximumCharacters - $this->overlapCharacters);
        $offset = 0;
        $textLength = $this->length($text);

        while ($offset < $textLength) {
            $parts[] = trim($this->slice($text, $offset, $this->maximumCharacters));
            $offset += $step;
        }

        return array_values(array_filter($parts));
    }

    private function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function tail(string $text): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, max(0, mb_strlen($text, 'UTF-8') - $this->overlapCharacters), null, 'UTF-8');
        }

        return substr($text, max(0, strlen($text) - $this->overlapCharacters));
    }

    private function slice(string $text, int $offset, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, $offset, $length, 'UTF-8');
        }

        return substr($text, $offset, $length);
    }
}
