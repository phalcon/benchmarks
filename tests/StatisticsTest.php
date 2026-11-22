<?php

declare(strict_types=1);

namespace Benchmarks\Tests;

use Benchmarks\Statistics;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StatisticsTest extends TestCase
{
    public function testEmptyListThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Statistics::median([]);
    }

    public function testMedianSortsTheValues(): void
    {
        $this->assertSame(2.0, Statistics::median([3.0, 1.0, 2.0]));
    }

    public function testQuartileOfOneValue(): void
    {
        $this->assertSame(7.0, Statistics::quartile([7.0], 0.25));
    }

    public function testQuartilesOfOneToTen(): void
    {
        $values = array_map('floatval', range(1, 10));

        $this->assertEqualsWithDelta(3.25, Statistics::quartile($values, 0.25), 1e-9);
        $this->assertEqualsWithDelta(5.5, Statistics::median($values), 1e-9);
        $this->assertEqualsWithDelta(7.75, Statistics::quartile($values, 0.75), 1e-9);
        $this->assertEqualsWithDelta(4.5 / 5.5, Statistics::relativeIqr($values), 1e-9);
    }

    public function testStatusLimits(): void
    {
        $this->assertSame('stable', Statistics::status(0.05));
        $this->assertSame('warning', Statistics::status(0.0501));
        $this->assertSame('warning', Statistics::status(0.10));
        $this->assertSame('rejected', Statistics::status(0.1001));
    }
}
