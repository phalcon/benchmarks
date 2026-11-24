<?php

declare(strict_types=1);

namespace Benchmarks;

use InvalidArgumentException;

/**
 * Median, quartiles and the stability status of a list of values (D11).
 */
final class Statistics
{
    public const LIMIT_STABLE = 0.05;
    public const LIMIT_WARNING = 0.10;

    /**
     * @param list<float> $values
     */
    public static function median(array $values): float
    {
        return self::quartile($values, 0.5);
    }

    /**
     * Linear interpolation between the closest ranks.
     *
     * @param list<float> $values
     */
    public static function quartile(array $values, float $fraction): float
    {
        $sorted = self::sorted($values);
        $position = (count($sorted) - 1) * $fraction;
        $lower = (int) floor($position);

        if ($lower + 1 >= count($sorted)) {
            return $sorted[$lower];
        }

        return $sorted[$lower] + ($position - $lower) * ($sorted[$lower + 1] - $sorted[$lower]);
    }

    /**
     * (Q3 - Q1) / median.
     *
     * @param list<float> $values
     */
    public static function relativeIqr(array $values): float
    {
        return (self::quartile($values, 0.75) - self::quartile($values, 0.25)) / self::median($values);
    }

    /**
     * Returns "stable", "warning" or "rejected".
     */
    public static function status(float $relativeIqr): string
    {
        if ($relativeIqr <= self::LIMIT_STABLE) {
            return 'stable';
        }

        if ($relativeIqr <= self::LIMIT_WARNING) {
            return 'warning';
        }

        return 'rejected';
    }

    /**
     * @param list<float> $values
     *
     * @return list<float>
     */
    private static function sorted(array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('The list of values is empty.');
        }

        sort($values);

        return $values;
    }
}
