<?php

declare(strict_types=1);

namespace Benchmarks;

/**
 * Makes one row for each variant, test and level from the repetitions of a
 * run, and renders the rows as a text table (B9, D11, D18).
 *
 * @phpstan-import-type Result from WrkParser
 * @phpstan-type Repetition array{
 *     variant: string,
 *     repetition: int,
 *     test: string,
 *     serverAffinityOk: bool,
 *     wrkCpu: int,
 *     parsed: Result
 * }
 * @phpstan-type Row array{
 *     variant: string,
 *     test: string,
 *     connections: int,
 *     valid: int,
 *     total: int,
 *     medianRps: float|null,
 *     relativeIqr: float|null,
 *     status: string,
 *     p50Ms: float|null,
 *     p99Ms: float|null,
 *     serverCpu: float|null,
 *     wrkCpu: float|null,
 *     ratio: float|null,
 *     notes: list<string>
 * }
 * @phpstan-type Sample array{
 *     rejected: bool,
 *     rps: float,
 *     p50: float|null,
 *     p99: float|null,
 *     server: float,
 *     wrk: float
 * }
 */
final class Report
{
    public const CLIENT_BOUND = 0.95;

    private const FORMAT = '%-12s %-9s %6s %7s %12s %7s %-8s %9s %9s %5s %5s %7s  %s';
    private const TESTS = ['json', 'plaintext'];

    /**
     * @param list<Row> $rows
     */
    public function render(array $rows, bool $dom0Confirmed): string
    {
        $lines = [];
        if (!$dom0Confirmed) {
            $lines[] = 'dom0 state not confirmed';
        }

        $lines[] = sprintf(
            self::FORMAT,
            'variant',
            'test',
            'conn',
            'valid',
            'rps',
            'iqr%',
            'status',
            'p50 ms',
            'p99 ms',
            'srv%',
            'wrk%',
            'ratio',
            'notes'
        );

        foreach ($rows as $row) {
            $lines[] = sprintf(
                self::FORMAT,
                $row['variant'],
                $row['test'],
                $row['connections'],
                $row['valid'] . '/' . $row['total'],
                $this->number($row['medianRps'], 1),
                $this->number($row['relativeIqr'] === null ? null : $row['relativeIqr'] * 100, 1),
                $row['status'],
                $this->number($row['p50Ms'], 3),
                $this->number($row['p99Ms'], 3),
                $this->number($row['serverCpu'] === null ? null : $row['serverCpu'] * 100, 0),
                $this->number($row['wrkCpu'] === null ? null : $row['wrkCpu'] * 100, 0),
                $this->number($row['ratio'], 3),
                implode(', ', $row['notes'])
            );
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param list<Repetition> $repetitions
     * @param list<string>     $order       The run order of the variants. The first is the baseline.
     *
     * @return list<Row>
     */
    public function rows(array $repetitions, array $order): array
    {
        $samples = [];
        foreach ($repetitions as $repetition) {
            $wrkCpu = (string) $repetition['wrkCpu'];
            $affinityOk = $repetition['serverAffinityOk'] && $repetition['parsed']['affinity'] === $wrkCpu;

            foreach ($repetition['parsed']['phases'] as $phase) {
                if ($phase['name'] !== 'level') {
                    continue;
                }

                $key = $repetition['variant'] . '|' . $repetition['test'] . '|' . $phase['connections'];
                $samples[$key][] = $this->sample($phase, $affinityOk);
            }
        }

        $rows = [];
        foreach ($samples as $key => $list) {
            [$variant, $test, $connections] = explode('|', (string) $key);
            $rows[] = $this->row($variant, $test, (int) $connections, $list);
        }

        usort($rows, fn (array $a, array $b): int => $this->sortKey($a, $order) <=> $this->sortKey($b, $order));

        return $this->addRatios($rows, $order);
    }

    /**
     * @param list<Row>    $rows
     * @param list<string> $order
     *
     * @return list<Row>
     */
    private function addRatios(array $rows, array $order): array
    {
        if (count($order) < 2) {
            return $rows;
        }

        $baseline = [];
        foreach ($rows as $row) {
            if ($row['variant'] === $order[0]) {
                $baseline[$row['test'] . '|' . $row['connections']] = $row['medianRps'];
            }
        }

        foreach ($rows as $index => $row) {
            $base = $baseline[$row['test'] . '|' . $row['connections']] ?? null;
            if ($row['medianRps'] !== null && $base !== null && $base > 0.0) {
                $rows[$index]['ratio'] = $row['medianRps'] / $base;
            }
        }

        return $rows;
    }

    /**
     * Median of the values, or null for an empty list.
     *
     * @param list<float> $values
     */
    private function median(array $values): ?float
    {
        return $values === [] ? null : Statistics::median($values);
    }

    private function number(?float $value, int $decimals): string
    {
        return $value === null ? '-' : number_format($value, $decimals, '.', '');
    }

    /**
     * @param list<Sample> $samples
     *
     * @return Row
     */
    private function row(string $variant, string $test, int $connections, array $samples): array
    {
        $valid = array_values(array_filter($samples, static fn (array $sample): bool => !$sample['rejected']));
        $rejected = count($samples) - count($valid);
        $rps = array_column($valid, 'rps');
        $wrkCpu = $valid === [] ? null : max(array_column($valid, 'wrk'));

        $notes = [];
        if ($rejected > 0) {
            $notes[] = 'rejected ' . $rejected;
        }

        $relativeIqr = null;
        $status = 'rejected';
        if ($rps !== []) {
            $relativeIqr = Statistics::relativeIqr($rps);
            $status = Statistics::status($relativeIqr);

            if ($wrkCpu !== null && $wrkCpu >= self::CLIENT_BOUND) {
                $notes[] = 'client-bound';
            }

            if ($status === 'stable' && ($rejected > 0 || in_array('client-bound', $notes, true))) {
                $status = 'warning';
            }
        }

        return [
            'variant' => $variant,
            'test' => $test,
            'connections' => $connections,
            'valid' => count($valid),
            'total' => count($samples),
            'medianRps' => $this->median($rps),
            'relativeIqr' => $relativeIqr,
            'status' => $status,
            'p50Ms' => $this->median(array_values(array_filter(array_column($valid, 'p50'), 'is_float'))),
            'p99Ms' => $this->median(array_values(array_filter(array_column($valid, 'p99'), 'is_float'))),
            'serverCpu' => $this->median(array_column($valid, 'server')),
            'wrkCpu' => $wrkCpu,
            'ratio' => null,
            'notes' => $notes,
        ];
    }

    /**
     * A repetition is rejected at a level when it has socket errors, non-2xx
     * or 3xx responses, no requests, or a wrong CPU affinity (D18, I7).
     * wrk writes a zero latency distribution for pipelined requests. Then the
     * latency is not available (null).
     *
     * @param array{
     *     requests: int,
     *     requestsPerSecond: float,
     *     latencyMs: array{p50: float, p75: float, p90: float, p99: float},
     *     errors: array{connect: int, read: int, write: int, timeout: int},
     *     non2xx3xx: int,
     *     cpuBusy: array{server: float, wrk: float}
     * } $phase
     *
     * @return Sample
     */
    private function sample(array $phase, bool $affinityOk): array
    {
        $latencyAvailable = array_sum($phase['latencyMs']) > 0.0;

        return [
            'rejected' => !$affinityOk
                || array_sum($phase['errors']) > 0
                || $phase['non2xx3xx'] > 0
                || $phase['requests'] === 0,
            'rps' => $phase['requestsPerSecond'],
            'p50' => $latencyAvailable ? $phase['latencyMs']['p50'] : null,
            'p99' => $latencyAvailable ? $phase['latencyMs']['p99'] : null,
            'server' => $phase['cpuBusy']['server'],
            'wrk' => $phase['cpuBusy']['wrk'],
        ];
    }

    /**
     * @param Row          $row
     * @param list<string> $order
     *
     * @return array{int, int, int}
     */
    private function sortKey(array $row, array $order): array
    {
        $variant = array_search($row['variant'], $order, true);
        $test = array_search($row['test'], self::TESTS, true);

        return [
            $variant === false ? count($order) : $variant,
            $test === false ? count(self::TESTS) : $test,
            $row['connections'],
        ];
    }
}
