<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Http\FetchResult;
use FreifunkKarte\Parser\ParseResult;
use FreifunkKarte\Pipeline\RunState;
use PHPUnit\Framework\TestCase;

final class RunStateTest extends TestCase
{
    use PipelineFixtures;

    public function testCountsRequestsAndKeepsTheLatestAnswerPerUrl(): void
    {
        $state = new RunState();
        $state->recordFetches(['a' => self::fetched('a', 500), 'b' => self::fetched('b')]);
        $state->recordFetches(['a' => self::fetched('a')]);

        $this->assertSame(3, $state->requests());
        $this->assertSame(200, $state->fetch('a')?->status);
        $this->assertTrue($state->isFetched('b'));
        $this->assertFalse($state->isFetched('c'));
        $this->assertNull($state->fetch('c'));
    }

    public function testHasNodesNeedsAUsableSourceWithEntries(): void
    {
        $state = new RunState();
        $state->setParsed('good', self::nodes(['1']));
        $state->setParsed('empty-list', new ParseResult(Format::NodesEmpty, parsed: true));
        $state->setParsed('page', ParseResult::unusable(Format::Html, 'a page'));

        $this->assertTrue($state->hasNodes('good'));
        $this->assertFalse($state->hasNodes('empty-list'));
        $this->assertFalse($state->hasNodes('page'));
        $this->assertFalse($state->hasNodes('unknown'));
    }

    public function testAnyNodelist(): void
    {
        $state = new RunState();
        $state->setParsed('list', self::nodes(['1'], Format::Nodelist));
        $state->setParsed('flat', self::nodes(['2'], Format::MeshviewerFlat));

        $this->assertTrue($state->anyNodelist(['flat', 'list']));
        $this->assertFalse($state->anyNodelist(['flat', 'unknown']));
        $this->assertFalse($state->anyNodelist([]));
    }

    public function testForgetParsed(): void
    {
        $state = new RunState();
        $state->setParsed('a', self::nodes(['1']));
        $state->forgetParsed('a');

        $this->assertNull($state->parsed('a'));
    }

    public function testStaleMarks(): void
    {
        $state = new RunState();
        $state->markStale('url', self::now());
        $state->markApiStale('town', self::now()->modify('-1 hour'));

        $this->assertEquals(self::now(), $state->staleSince('url'));
        $this->assertNull($state->staleSince('other'));
        $this->assertEquals(self::now()->modify('-1 hour'), $state->apiStaleSince('town'));
        $this->assertNull($state->apiStaleSince('other'));
    }

    public function testFetchRecordsJoinFetchAndParse(): void
    {
        $state = new RunState();
        $state->recordFetches([
            'https://a/' => new FetchResult('https://a/', 200, null, 1234, 0.5, true),
            'https://b/' => new FetchResult('https://b/', null, 'timeout'),
        ]);
        $state->setParsed('https://a/', self::nodes(['1', '2']));

        [$a, $b] = $state->fetchRecords();

        $this->assertSame(['https://a/', 200, 1234, true, 'nodeinfo', 2], [$a->url, $a->status, $a->bytes, $a->tlsInvalid, $a->format, $a->nodes]);
        $this->assertSame(['https://b/', null, 'timeout', null, 0], [$b->url, $b->status, $b->error, $b->format, $b->nodes]);
    }
}
