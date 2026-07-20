<?php

declare(strict_types=1);

namespace App\Services;

final class VectorSimilarityService
{
    /**
     * @param array<int, float|int> $left
     * @param array<int, float|int> $right
     */
    public function cosine(array $left, array $right): float
    {
        $length = min(count($left), count($right));

        if ($length === 0) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $leftMagnitude = 0.0;
        $rightMagnitude = 0.0;

        for ($index = 0; $index < $length; $index++) {
            $leftValue = (float) $left[$index];
            $rightValue = (float) $right[$index];

            $dotProduct += $leftValue * $rightValue;
            $leftMagnitude += $leftValue ** 2;
            $rightMagnitude += $rightValue ** 2;
        }

        if ($leftMagnitude <= 0.0 || $rightMagnitude <= 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($leftMagnitude) * sqrt($rightMagnitude));
    }
}
