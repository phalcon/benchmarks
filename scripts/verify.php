<?php

/**
 * Sends the request of a test two times, 3 seconds apart, and runs the
 * TechEmpower response checks (I2).
 *
 * Usage: php scripts/verify.php <json|plaintext> <url> <accept>
 * Exit code 1 when a check fails.
 */

declare(strict_types=1);

use Benchmarks\ResponseChecker;

require __DIR__ . '/../vendor/autoload.php';

if ($argc !== 4) {
    fwrite(STDERR, 'Usage: php scripts/verify.php <json|plaintext> <url> <accept>' . PHP_EOL);
    exit(2);
}

[, $test, $url, $accept] = $argv;

/**
 * @return array{status: int, headers: array<string, string>, body: string}
 */
$fetch = static function (string $url, string $accept): array {
    $headers = [];
    $handle = curl_init($url);
    if ($handle === false) {
        fwrite(STDERR, 'FAIL request: cannot create a curl handle' . PHP_EOL);
        exit(1);
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);

    $body = curl_exec($handle);
    if (!is_string($body)) {
        echo 'FAIL request: ', curl_error($handle), PHP_EOL, 'FAILED', PHP_EOL;
        exit(1);
    }

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => $headers,
        'body' => $body,
    ];
};

$first = $fetch($url, $accept);
sleep(3);
$second = $fetch($url, $accept);

$failed = false;
foreach ((new ResponseChecker())->check($test, $first, $second) as $problem) {
    echo strtoupper($problem['level']), ' ', $problem['code'], PHP_EOL;
    $failed = $failed || $problem['level'] === 'fail';
}

echo $failed ? 'FAILED' : 'PASS', PHP_EOL;
exit($failed ? 1 : 0);
