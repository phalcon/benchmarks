<?php

/**
 * Makes results.json and report.txt for a run folder, and prints the report
 * (B9). The run folder has meta/run.env, meta/order.txt and
 * <variant>/rep-NN/<test>.raw.txt with server-affinity.txt.
 *
 * Usage: php scripts/report.php <run-folder>
 */

declare(strict_types=1);

use Benchmarks\Report;
use Benchmarks\WrkParser;

require __DIR__ . '/../vendor/autoload.php';

if ($argc !== 2) {
    fwrite(STDERR, 'Usage: php scripts/report.php <run-folder>' . PHP_EOL);
    exit(2);
}

$dir = rtrim($argv[1], '/');

$read = static function (string $file): string {
    $content = file_get_contents($file);
    if ($content === false) {
        fwrite(STDERR, 'Cannot read ' . $file . PHP_EOL);
        exit(1);
    }

    return $content;
};

$run = [];
foreach (explode("\n", $read($dir . '/meta/run.env')) as $line) {
    if (preg_match('/^([A-Z0-9_]+)="(.*)"$/', $line, $match) === 1) {
        $run[$match[1]] = $match[2];
    }
}

foreach (['SERVER_CPU', 'WRK_CPU', 'DOM0_CONFIRMED'] as $key) {
    if (!isset($run[$key])) {
        fwrite(STDERR, 'meta/run.env has no ' . $key . PHP_EOL);
        exit(1);
    }
}

$serverCpu = (int) $run['SERVER_CPU'];
$wrkCpu = (int) $run['WRK_CPU'];
$order = array_values(array_filter(explode("\n", $read($dir . '/meta/order.txt'))));

$parser = new WrkParser();
$repetitions = [];
foreach (glob($dir . '/*/rep-*/*.raw.txt') ?: [] as $file) {
    $repetitionDir = dirname($file);
    $affinity = file($repetitionDir . '/server-affinity.txt', FILE_IGNORE_NEW_LINES) ?: [];

    try {
        $parsed = $parser->parse($read($file), $serverCpu, $wrkCpu);
    } catch (UnexpectedValueException $exception) {
        fwrite(STDERR, $file . ': ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }

    $repetitions[] = [
        'variant' => basename(dirname($repetitionDir)),
        'repetition' => (int) substr(basename($repetitionDir), strlen('rep-')),
        'test' => basename($file, '.raw.txt'),
        'serverAffinityOk' => end($affinity) === 'OK',
        'wrkCpu' => $wrkCpu,
        'parsed' => $parsed,
    ];
}

$configuration = [];
foreach (glob($dir . '/meta/*') ?: [] as $file) {
    $configuration[basename($file)] = $read($file);
}

$report = new Report();
$rows = $report->rows($repetitions, $order);
$text = $report->render($rows, $run['DOM0_CONFIRMED'] === 'yes');

$json = json_encode(
    ['configuration' => $configuration, 'repetitions' => $repetitions, 'rows' => $rows],
    JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);

file_put_contents($dir . '/results.json', $json . PHP_EOL);
file_put_contents($dir . '/report.txt', $text);

echo $text;
