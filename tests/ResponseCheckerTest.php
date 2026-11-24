<?php

declare(strict_types=1);

namespace Benchmarks\Tests;

use Benchmarks\ResponseChecker;
use PHPUnit\Framework\TestCase;

final class ResponseCheckerTest extends TestCase
{
    private const DATE = 'Sat, 21 Nov 2026 10:00:00 GMT';
    private const DATE_LATER = 'Sat, 21 Nov 2026 10:00:03 GMT';

    public function testCachedDateFails(): void
    {
        $this->assertContains('fail cached-date', $this->problems('json', secondDate: self::DATE));
    }

    public function testCharsetUtf8IsAccepted(): void
    {
        $this->assertSame([], $this->problems('json', ['content-type' => 'application/json; charset=UTF-8']));
    }

    public function testInvalidJsonFails(): void
    {
        $this->assertSame(['fail invalid-json'], $this->problems('json', body: '{"message":'));
    }

    public function testJsonExtraKeyWarns(): void
    {
        $this->assertSame(
            ['warn json-keys'],
            $this->problems('json', body: '{"message":"Hello, World!","x":"y"}')
        );
    }

    public function testJsonMessageIgnoresCase(): void
    {
        $this->assertSame([], $this->problems('json', body: '{"message":"hello, world!"}'));
    }

    public function testJsonWrongMessageFails(): void
    {
        $this->assertContains('fail json-message', $this->problems('json', body: '{"message":"Hello"}'));
    }

    public function testMissingLengthFails(): void
    {
        $this->assertContains('fail missing-length', $this->problems('json', ['content-length' => null]));
        $this->assertSame(
            [],
            $this->problems('json', ['content-length' => null, 'transfer-encoding' => 'chunked'])
        );
    }

    public function testMissingServerFails(): void
    {
        $this->assertContains('fail missing-header:server', $this->problems('json', ['server' => null]));
    }

    public function testPlaintextExtraBytesWarns(): void
    {
        $this->assertSame(
            ['warn plaintext-extra-bytes'],
            $this->problems('plaintext', body: "Hello, World!\n")
        );
    }

    public function testPlaintextWrongBodyFails(): void
    {
        $this->assertContains('fail plaintext-body', $this->problems('plaintext', body: 'Goodbye'));
    }

    public function testStatusFails(): void
    {
        $this->assertContains('fail status', $this->problems('json', status: 500));
    }

    public function testValidJsonPasses(): void
    {
        $this->assertSame([], $this->problems('json'));
    }

    public function testValidPlaintextPasses(): void
    {
        $this->assertSame([], $this->problems('plaintext'));
    }

    public function testWrongCharsetFails(): void
    {
        $this->assertContains(
            'fail content-type',
            $this->problems('json', ['content-type' => 'application/json; charset=latin1'])
        );
    }

    public function testWrongDateFormatWarns(): void
    {
        $this->assertContains('warn date-format', $this->problems('json', ['date' => 'Saturday 21 Nov']));
    }

    /**
     * Checks a valid response of the test, with the given changes. A null
     * header value removes the header.
     *
     * @param array<string, string|null> $changes
     *
     * @return list<string>
     */
    private function problems(
        string $test,
        array $changes = [],
        ?string $body = null,
        int $status = 200,
        string $secondDate = self::DATE_LATER,
    ): array {
        $headers = $test === 'json'
            ? ['content-type' => 'application/json', 'content-length' => '27']
            : ['content-type' => 'text/plain; charset=UTF-8', 'content-length' => '13'];
        $headers += ['server' => 'nginx', 'date' => self::DATE];
        $headers = array_filter(array_merge($headers, $changes), static fn (?string $value): bool => $value !== null);

        $first = [
            'status' => $status,
            'headers' => $headers,
            'body' => $body ?? ($test === 'json' ? '{"message":"Hello, World!"}' : 'Hello, World!'),
        ];
        $second = ['status' => 200, 'headers' => ['date' => $secondDate], 'body' => ''];

        return array_map(
            static fn (array $problem): string => $problem['level'] . ' ' . $problem['code'],
            (new ResponseChecker())->check($test, $first, $second)
        );
    }
}
