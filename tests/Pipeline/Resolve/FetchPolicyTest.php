<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline\Resolve;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Http\FetchResult;
use FreifunkKarte\Parser\ParseResult;
use FreifunkKarte\Pipeline\Resolve\FetchPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FetchPolicyTest extends TestCase
{
    /**
     * @return array<string, array{?int, bool}>
     */
    public static function troubleByStatus(): array
    {
        return [
            'no answer' => [null, true],
            'request timeout' => [408, true],
            'too many requests' => [429, true],
            'server error' => [500, true],
            'bad gateway' => [502, true],
            'ok' => [200, false],
            'not found' => [404, false],
            'forbidden' => [403, false],
        ];
    }

    #[DataProvider('troubleByStatus')]
    public function testHasTrouble(?int $status, bool $expected): void
    {
        $this->assertSame($expected, FetchPolicy::hasTrouble(new FetchResult('u', $status)));
    }

    /**
     * @return array<string, array{?int, ?Format, bool}>
     */
    public static function transientCases(): array
    {
        return [
            'not fetched' => [null, null, false],
            'timeout is not asked again' => [null, Format::Empty, false],
            'server error' => [503, null, true],
            'too many requests' => [429, null, true],
            'request timeout is not transient' => [408, null, false],
            'empty body' => [200, Format::Empty, true],
            'valid data' => [200, Format::NodeInfo, false],
            'empty node list is believed' => [200, Format::NodesEmpty, false],
            'not found' => [404, null, false],
        ];
    }

    #[DataProvider('transientCases')]
    public function testIsTransient(?int $status, ?Format $format, bool $expected): void
    {
        $fetch = $status === null && $format === null ? null : new FetchResult('u', $status);
        $parsed = $format !== null ? new ParseResult($format) : null;

        $this->assertSame($expected, FetchPolicy::isTransient($fetch, $parsed));
    }

    /**
     * @return array<string, array{?int, ?Format, bool}>
     */
    public static function fallBackCases(): array
    {
        return [
            'no answer' => [null, null, true],
            'request timeout' => [408, null, true],
            'server error' => [500, null, true],
            'empty body' => [200, Format::Empty, true],
            'not found is believed' => [404, null, false],
            'valid data' => [200, Format::NodeInfo, false],
            'valid but empty node list is believed' => [200, Format::NodesEmpty, false],
        ];
    }

    #[DataProvider('fallBackCases')]
    public function testMayFallBack(?int $status, ?Format $format, bool $expected): void
    {
        $parsed = $format !== null ? new ParseResult($format) : null;

        $this->assertSame($expected, FetchPolicy::mayFallBack(new FetchResult('u', $status), $parsed));
    }

    public function testNothingFetchedNothingToFallBackOn(): void
    {
        $this->assertFalse(FetchPolicy::mayFallBack(null, null));
    }

    public function testFallbackReason(): void
    {
        $this->assertSame('no answer (timeout)', FetchPolicy::fallbackReason(new FetchResult('u', null, 'timeout')));
        $this->assertSame('no answer (unreachable)', FetchPolicy::fallbackReason(new FetchResult('u', null)));
        $this->assertSame('an empty body', FetchPolicy::fallbackReason(new FetchResult('u', 200)));
        $this->assertSame('HTTP 503', FetchPolicy::fallbackReason(new FetchResult('u', 503)));
    }

    public function testDescribe(): void
    {
        $this->assertSame('not fetched', FetchPolicy::describe(null));
        $this->assertSame('Could not resolve host', FetchPolicy::describe(new FetchResult('u', null, 'Could not resolve host')));
        $this->assertSame('unreachable', FetchPolicy::describe(new FetchResult('u', null)));
        $this->assertSame('HTTP 404', FetchPolicy::describe(new FetchResult('u', 404)));
        $this->assertSame('not a JSON object', FetchPolicy::describe(new FetchResult('u', 200)));
    }
}
