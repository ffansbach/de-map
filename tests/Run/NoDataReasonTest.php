<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Run;

use FreifunkKarte\Run\NoDataReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoDataReasonTest extends TestCase
{
    /**
     * @return array<string, array{list<array{url: string, status: int|null, error: string|null, format: string|null, nodes: int}>, NoDataReason}>
     */
    public static function attemptsProvider(): array
    {
        $attempt = static fn (?int $status, ?string $format = null): array => ['url' => 'https://x.test/', 'status' => $status, 'error' => null, 'format' => $format, 'nodes' => 0];

        return [
            'nothing tried' => [[], NoDataReason::NothingTried],
            'a page and 404s: the map has a page, the data is somewhere else' => [[$attempt(200, 'html'), $attempt(404), $attempt(404)], NoDataReason::PageWithoutData],
            'the page wins over empty data' => [[$attempt(200, 'empty'), $attempt(200, 'html')], NoDataReason::PageWithoutData],
            'empty body' => [[$attempt(404), $attempt(200, 'empty')], NoDataReason::EmptyData],
            'valid but empty list' => [[$attempt(200, 'nodes_empty')], NoDataReason::EmptyData],
            'only 404 and 401' => [[$attempt(404), $attempt(401)], NoDataReason::NotFound],
            'answers and does not answer' => [[$attempt(null), $attempt(404)], NoDataReason::NotFound],
            'no answer at all' => [[$attempt(null), $attempt(null)], NoDataReason::Unreachable],
            'json that is no node list' => [[$attempt(200, 'json_other')], NoDataReason::NotFound],
        ];
    }

    /**
     * @param list<array{url: string, status: int|null, error: string|null, format: string|null, nodes: int}> $attempts
     */
    #[DataProvider('attemptsProvider')]
    public function testClassify(array $attempts, NoDataReason $expected): void
    {
        $this->assertSame($expected, NoDataReason::classify($attempts));
    }

    public function testEveryReasonHasALabel(): void
    {
        foreach (NoDataReason::cases() as $reason) {
            $this->assertNotSame('', $reason->label());
        }
    }
}
