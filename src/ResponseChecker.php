<?php

declare(strict_types=1);

namespace Benchmarks;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The TechEmpower response checks for the plaintext and JSON tests (I2).
 *
 * A response is array{status: int, headers: array<string, string>, body: string}.
 * The header names are in lower case.
 */
final class ResponseChecker
{
    private const CONTENT_TYPES = [
        'json' => '/^application\/json(; ?charset=(UTF|utf)-8)?$/',
        'plaintext' => '/^text\/plain(; ?charset=(UTF|utf)-8)?$/',
    ];
    private const DATE_FORMAT = 'D, d M Y H:i:s T';
    private const HELLO = 'hello, world!';
    private const REQUIRED_HEADERS = ['server', 'date', 'content-type'];

    /**
     * Checks a response. The second response is the same request 3 seconds
     * later. Only its Date header is used.
     *
     * @param array{status: int, headers: array<string, string>, body: string} $first
     * @param array{status: int, headers: array<string, string>, body: string} $second
     *
     * @return list<array{level: string, code: string}>
     */
    public function check(string $test, array $first, array $second): array
    {
        if (!isset(self::CONTENT_TYPES[$test])) {
            throw new InvalidArgumentException('Unknown test: ' . $test);
        }

        if ($first['body'] === '') {
            return [$this->problem('fail', 'empty-body')];
        }

        if ($test === 'json') {
            $decoded = json_decode($first['body'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [$this->problem('fail', 'invalid-json')];
            }

            $problems = $this->checkJson($decoded);
        } else {
            $problems = $this->checkPlaintext($first['body']);
        }

        if ($first['status'] !== 200) {
            $problems[] = $this->problem('fail', 'status');
        }

        return array_merge($problems, $this->checkHeaders($test, $first['headers'], $second['headers']));
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $later
     *
     * @return list<array{level: string, code: string}>
     */
    private function checkHeaders(string $test, array $headers, array $later): array
    {
        $problems = [];
        foreach (self::REQUIRED_HEADERS as $name) {
            if (!isset($headers[$name])) {
                $problems[] = $this->problem('fail', 'missing-header:' . $name);
            }
        }

        if (!isset($headers['content-length']) && !isset($headers['transfer-encoding'])) {
            $problems[] = $this->problem('fail', 'missing-length');
        }

        if (isset($headers['date'])) {
            $date = DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $headers['date']);
            if ($date === false || $date->format(self::DATE_FORMAT) !== $headers['date']) {
                $problems[] = $this->problem('warn', 'date-format');
            }

            if ($headers['date'] === ($later['date'] ?? null)) {
                $problems[] = $this->problem('fail', 'cached-date');
            }
        }

        if (isset($headers['content-type']) && preg_match(self::CONTENT_TYPES[$test], $headers['content-type']) !== 1) {
            $problems[] = $this->problem('fail', 'content-type');
        }

        return $problems;
    }

    /**
     * @return list<array{level: string, code: string}>
     */
    private function checkJson(mixed $decoded): array
    {
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return [$this->problem('fail', 'json-message')];
        }

        $object = array_change_key_case($decoded);
        $message = $object['message'] ?? null;
        if (!is_string($message) || strtolower($message) !== self::HELLO) {
            return [$this->problem('fail', 'json-message')];
        }

        if (count($object) > 1) {
            return [$this->problem('warn', 'json-keys')];
        }

        return [];
    }

    /**
     * @return list<array{level: string, code: string}>
     */
    private function checkPlaintext(string $body): array
    {
        if (!str_contains(strtolower($body), self::HELLO)) {
            return [$this->problem('fail', 'plaintext-body')];
        }

        if (strlen($body) > strlen(self::HELLO)) {
            return [$this->problem('warn', 'plaintext-extra-bytes')];
        }

        return [];
    }

    /**
     * @return array{level: string, code: string}
     */
    private function problem(string $level, string $code): array
    {
        return ['level' => $level, 'code' => $code];
    }
}
