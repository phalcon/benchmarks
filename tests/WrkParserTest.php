<?php

declare(strict_types=1);

namespace Benchmarks\Tests;

use Benchmarks\WrkParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class WrkParserTest extends TestCase
{
    public function testParsesErrorsAndSeconds(): void
    {
        $phase = (new WrkParser())->parse($this->fixture('wrk-errors.txt'), 4, 5)['phases'][0];

        $this->assertSame(['connect' => 0, 'read' => 12, 'write' => 0, 'timeout' => 3], $phase['errors']);
        $this->assertSame(7, $phase['non2xx3xx']);
        $this->assertEqualsWithDelta(100.0, $phase['latencyMs']['p50'], 1e-9);
        $this->assertEqualsWithDelta(2500.0, $phase['latencyMs']['p99'], 1e-9);
    }

    public function testParsesNormalOutput(): void
    {
        $result = (new WrkParser())->parse($this->fixture('wrk-normal.txt'), 4, 5);

        $this->assertSame('5', $result['affinity']);
        $this->assertCount(2, $result['phases']);

        [$primer, $level] = $result['phases'];
        $this->assertSame('primer', $primer['name']);
        $this->assertSame(8, $primer['connections']);
        $this->assertSame(8, $primer['threads']);

        $this->assertSame('level', $level['name']);
        $this->assertSame(16, $level['connections']);
        $this->assertSame(1, $level['threads']);
        $this->assertSame(480000, $level['requests']);
        $this->assertSame(32000.5, $level['requestsPerSecond']);
        $this->assertEqualsWithDelta(0.48, $level['latencyMs']['p50'], 1e-9);
        $this->assertEqualsWithDelta(0.55, $level['latencyMs']['p75'], 1e-9);
        $this->assertEqualsWithDelta(0.65, $level['latencyMs']['p90'], 1e-9);
        $this->assertEqualsWithDelta(1.2, $level['latencyMs']['p99'], 1e-9);
        $this->assertSame(['connect' => 0, 'read' => 0, 'write' => 0, 'timeout' => 0], $level['errors']);
        $this->assertSame(0, $level['non2xx3xx']);
        $this->assertSame(['server' => 0.75, 'wrk' => 0.5], $level['cpuBusy']);
    }

    public function testThrowsForUnknownUnit(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new WrkParser())->parse(str_replace('1.20ms', '1.20xs', $this->fixture('wrk-normal.txt')), 4, 5);
    }

    public function testThrowsWithoutRequestsPerSecond(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new WrkParser())->parse(str_replace("Requests/sec:  32000.50\n", '', $this->fixture('wrk-normal.txt')), 4, 5);
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__ . '/fixtures/' . $name);
        if ($content === false) {
            throw new RuntimeException('Cannot read the fixture ' . $name);
        }

        return $content;
    }
}
