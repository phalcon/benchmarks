<?php

declare(strict_types=1);

namespace Benchmarks\Tests;

use Benchmarks\Report;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Repetition from Report
 */
final class ReportTest extends TestCase
{
    public function testAllRejectedGivesNoNumbers(): void
    {
        $errors = ['connect' => 0, 'read' => 3, 'write' => 0, 'timeout' => 0];
        $repetitions = [];
        for ($i = 0; $i < 3; $i++) {
            $repetitions[] = $this->makeRepetition(1000.0, errors: $errors);
        }

        $row = (new Report())->rows($repetitions, ['cphalcon'])[0];

        $this->assertSame(0, $row['valid']);
        $this->assertSame(3, $row['total']);
        $this->assertNull($row['medianRps']);
        $this->assertNull($row['relativeIqr']);
        $this->assertSame('rejected', $row['status']);
        $this->assertContains('rejected 3', $row['notes']);
    }

    public function testClientBoundRaisesStatus(): void
    {
        $repetitions = [];
        for ($i = 0; $i < 9; $i++) {
            $repetitions[] = $this->makeRepetition(1000.0);
        }
        $repetitions[] = $this->makeRepetition(1000.0, wrkCpu: 0.97);

        $row = (new Report())->rows($repetitions, ['cphalcon'])[0];

        $this->assertSame('warning', $row['status']);
        $this->assertSame(0.97, $row['wrkCpu']);
        $this->assertContains('client-bound', $row['notes']);
    }

    public function testPlaintextLatencyNotAvailable(): void
    {
        $zero = ['p50' => 0.0, 'p75' => 0.0, 'p90' => 0.0, 'p99' => 0.0];
        $repetitions = [];
        for ($i = 0; $i < 3; $i++) {
            $repetitions[] = $this->makeRepetition(1000.0, test: 'plaintext', latency: $zero);
        }

        $report = new Report();
        $rows = $report->rows($repetitions, ['cphalcon']);

        $this->assertNull($rows[0]['p50Ms']);
        $this->assertNull($rows[0]['p99Ms']);
        $this->assertSame('stable', $rows[0]['status']);
        $this->assertStringContainsString(' - ', $report->render($rows, true));
    }

    public function testRatioAgainstBaseline(): void
    {
        $repetitions = [];
        for ($i = 0; $i < 10; $i++) {
            $repetitions[] = $this->makeRepetition(1000.0, variant: 'a');
            $repetitions[] = $this->makeRepetition(1500.0, variant: 'b');
        }

        $rows = (new Report())->rows($repetitions, ['a', 'b']);

        $this->assertSame('a', $rows[0]['variant']);
        $this->assertSame(1.0, $rows[0]['ratio']);
        $this->assertSame('b', $rows[1]['variant']);
        $this->assertSame(1.5, $rows[1]['ratio']);
    }

    public function testRejectedRepetitionIsNotUsed(): void
    {
        $repetitions = [];
        for ($i = 0; $i < 9; $i++) {
            $repetitions[] = $this->makeRepetition(1000.0 + $i);
        }
        $repetitions[] = $this->makeRepetition(1009.0, non2xx3xx: 5);

        $row = (new Report())->rows($repetitions, ['cphalcon'])[0];

        $this->assertSame(9, $row['valid']);
        $this->assertSame(10, $row['total']);
        $this->assertSame(1004.0, $row['medianRps']);
        $this->assertSame('warning', $row['status']);
        $this->assertSame(['rejected 1'], $row['notes']);
    }

    public function testRenderShowsRows(): void
    {
        $report = new Report();
        $output = $report->render($report->rows($this->tenRepetitions(), ['cphalcon']), false);

        $this->assertStringContainsString('dom0 state not confirmed', $output);
        $this->assertStringContainsString('json', $output);
        $this->assertStringContainsString('10/10', $output);
        $this->assertStringContainsString('1004.5', $output);
        $this->assertStringContainsString('stable', $output);
    }

    public function testStableLevel(): void
    {
        $row = (new Report())->rows($this->tenRepetitions(), ['cphalcon'])[0];

        $this->assertSame(10, $row['valid']);
        $this->assertSame(1004.5, $row['medianRps']);
        $this->assertEqualsWithDelta((1006.75 - 1002.25) / 1004.5, $row['relativeIqr'], 1e-9);
        $this->assertSame('stable', $row['status']);
        $this->assertNull($row['ratio']);
        $this->assertSame([], $row['notes']);
    }

    public function testWrongAffinityRejects(): void
    {
        $repetitions = $this->tenRepetitions();
        $repetitions[0] = $this->makeRepetition(1000.0, affinity: '0-5');

        $row = (new Report())->rows($repetitions, ['cphalcon'])[0];

        $this->assertSame(9, $row['valid']);
    }

    public function testZeroRequestsIsRejected(): void
    {
        $repetitions = $this->tenRepetitions();
        $repetitions[0] = $this->makeRepetition(0.0, requests: 0);

        $row = (new Report())->rows($repetitions, ['cphalcon'])[0];

        $this->assertSame(9, $row['valid']);
        $this->assertContains('rejected 1', $row['notes']);
    }

    /**
     * One repetition with one JSON level at 16 connections.
     *
     * @param array{connect: int, read: int, write: int, timeout: int} $errors
     * @param array{p50: float, p75: float, p90: float, p99: float} $latency
     *
     * @return Repetition
     */
    private function makeRepetition(
        float $rps,
        string $variant = 'cphalcon',
        string $test = 'json',
        array $errors = ['connect' => 0, 'read' => 0, 'write' => 0, 'timeout' => 0],
        int $non2xx3xx = 0,
        float $wrkCpu = 0.5,
        string $affinity = '5',
        array $latency = ['p50' => 0.5, 'p75' => 0.6, 'p90' => 0.7, 'p99' => 1.0],
        int $requests = 1000,
    ): array {
        return [
            'variant' => $variant,
            'repetition' => 1,
            'test' => $test,
            'serverAffinityOk' => true,
            'wrkCpu' => 5,
            'parsed' => [
                'affinity' => $affinity,
                'phases' => [
                    [
                        'name' => 'level',
                        'connections' => 16,
                        'threads' => 1,
                        'requests' => $requests,
                        'requestsPerSecond' => $rps,
                        'latencyMs' => $latency,
                        'errors' => $errors,
                        'non2xx3xx' => $non2xx3xx,
                        'cpuBusy' => ['server' => 0.9, 'wrk' => $wrkCpu],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<Repetition>
     */
    private function tenRepetitions(): array
    {
        $repetitions = [];
        for ($i = 0; $i < 10; $i++) {
            $repetitions[] = $this->makeRepetition(1000.0 + $i);
        }

        return $repetitions;
    }
}
