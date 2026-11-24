<?php

declare(strict_types=1);

namespace Benchmarks;

use UnexpectedValueException;

/**
 * Parses the output of common/wrk/run.sh: an AFFINITY line, then for each
 * phase a PHASE line, the /proc/stat lines of the two CPUs, the wrk output,
 * the /proc/stat lines again, and END.
 *
 * @phpstan-type Phase array{
 *     name: string,
 *     connections: int,
 *     threads: int,
 *     requests: int,
 *     requestsPerSecond: float,
 *     latencyMs: array{p50: float, p75: float, p90: float, p99: float},
 *     errors: array{connect: int, read: int, write: int, timeout: int},
 *     non2xx3xx: int,
 *     cpuBusy: array{server: float, wrk: float}
 * }
 * @phpstan-type Result array{affinity: string, phases: list<Phase>}
 */
final class WrkParser
{
    private const SOCKET_ERRORS = '/^\s*Socket errors: connect (\d+), read (\d+), write (\d+), timeout (\d+)/';
    private const UNITS = ['us' => 0.001, 'ms' => 1.0, 's' => 1000.0, 'm' => 60000.0];

    /**
     * @return Result
     */
    public function parse(string $raw, int $serverCpu, int $wrkCpu): array
    {
        $affinity = null;
        $current = null;
        $lines = [];
        $phases = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^AFFINITY (\S+)$/', $line, $match) === 1) {
                $affinity = $match[1];
            } elseif (preg_match('/^PHASE (\w+) (\d+) (\d+)$/', $line, $match) === 1) {
                $current = ['name' => $match[1], 'connections' => (int) $match[2], 'threads' => (int) $match[3]];
                $lines = [];
            } elseif ($current !== null && $line === 'END') {
                $phases[] = $this->phase($current, $lines, $serverCpu, $wrkCpu);
                $current = null;
            } elseif ($current !== null) {
                $lines[] = $line;
            }
        }

        if ($affinity === null) {
            throw new UnexpectedValueException('The output has no AFFINITY line.');
        }

        if ($current !== null) {
            throw new UnexpectedValueException('The phase ' . $current['name'] . ' has no END line.');
        }

        return ['affinity' => $affinity, 'phases' => $phases];
    }

    /**
     * Busy part of a CPU between its two /proc/stat lines. The fields are
     * user, nice, system, idle, iowait, irq, softirq, steal.
     *
     * @param array<int, list<list<int>>> $cpu
     */
    private function busy(array $cpu, int $id, string $phase): float
    {
        if (count($cpu[$id] ?? []) !== 2) {
            throw new UnexpectedValueException('The phase ' . $phase . ' needs 2 lines for cpu' . $id . '.');
        }

        [$before, $after] = $cpu[$id];
        $delta = static fn (int $field): int => ($after[$field] ?? 0) - ($before[$field] ?? 0);
        $busy = $delta(0) + $delta(1) + $delta(2) + $delta(5) + $delta(6);
        $total = $busy + $delta(3) + $delta(4) + $delta(7);

        return $total > 0 ? $busy / $total : 0.0;
    }

    private function milliseconds(float $value, string $unit): float
    {
        if (!isset(self::UNITS[$unit])) {
            throw new UnexpectedValueException('Unknown latency unit: ' . $unit);
        }

        return $value * self::UNITS[$unit];
    }

    /**
     * @param array{name: string, connections: int, threads: int} $header
     * @param list<string> $lines
     *
     * @return Phase
     */
    private function phase(array $header, array $lines, int $serverCpu, int $wrkCpu): array
    {
        $cpu = [];
        $errors = ['connect' => 0, 'read' => 0, 'write' => 0, 'timeout' => 0];
        $latency = [];
        $non2xx3xx = 0;
        $requests = null;
        $requestsPerSecond = null;

        foreach ($lines as $line) {
            if (preg_match('/^cpu(\d+) (.+)$/', $line, $match) === 1) {
                $cpu[(int) $match[1]][] = array_map('intval', preg_split('/\s+/', trim($match[2])) ?: []);
            } elseif (preg_match('/^\s+(50|75|90|99)%\s+([\d.]+)([a-z]+)\s*$/', $line, $match) === 1) {
                $latency['p' . $match[1]] = $this->milliseconds((float) $match[2], $match[3]);
            } elseif (preg_match('/^\s*(\d+) requests in /', $line, $match) === 1) {
                $requests = (int) $match[1];
            } elseif (preg_match(self::SOCKET_ERRORS, $line, $match) === 1) {
                $errors = [
                    'connect' => (int) $match[1],
                    'read' => (int) $match[2],
                    'write' => (int) $match[3],
                    'timeout' => (int) $match[4],
                ];
            } elseif (preg_match('/^\s*Non-2xx or 3xx responses: (\d+)/', $line, $match) === 1) {
                $non2xx3xx = (int) $match[1];
            } elseif (preg_match('/^Requests\/sec:\s+([\d.]+)/', $line, $match) === 1) {
                $requestsPerSecond = (float) $match[1];
            }
        }

        $name = $header['name'];
        if ($requests === null || $requestsPerSecond === null) {
            throw new UnexpectedValueException('The phase ' . $name . ' has no request count or no Requests/sec line.');
        }

        if (!isset($latency['p50'], $latency['p75'], $latency['p90'], $latency['p99'])) {
            throw new UnexpectedValueException('The phase ' . $name . ' has no full latency distribution.');
        }

        return [
            'name' => $name,
            'connections' => $header['connections'],
            'threads' => $header['threads'],
            'requests' => $requests,
            'requestsPerSecond' => $requestsPerSecond,
            'latencyMs' => [
                'p50' => $latency['p50'],
                'p75' => $latency['p75'],
                'p90' => $latency['p90'],
                'p99' => $latency['p99'],
            ],
            'errors' => $errors,
            'non2xx3xx' => $non2xx3xx,
            'cpuBusy' => [
                'server' => $this->busy($cpu, $serverCpu, $name),
                'wrk' => $this->busy($cpu, $wrkCpu, $name),
            ],
        ];
    }
}
