<?php

/**
 * Prints the parse result of a raw wrk output file as JSON.
 *
 * Usage: php scripts/parse.php <raw-file> <server-cpu> <wrk-cpu>
 */

declare(strict_types=1);

use Benchmarks\WrkParser;

require __DIR__ . '/../vendor/autoload.php';

if ($argc !== 4) {
    fwrite(STDERR, 'Usage: php scripts/parse.php <raw-file> <server-cpu> <wrk-cpu>' . PHP_EOL);
    exit(2);
}

$raw = file_get_contents($argv[1]);
if ($raw === false) {
    fwrite(STDERR, 'Cannot read ' . $argv[1] . PHP_EOL);
    exit(1);
}

$result = (new WrkParser())->parse($raw, (int) $argv[2], (int) $argv[3]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), PHP_EOL;
