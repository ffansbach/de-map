<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline\Resolve;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Pipeline\Resolve\SourceProbe;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Source\LastGoodStore;
use FreifunkKarte\Tests\Pipeline\PipelineFixtures;
use PHPUnit\Framework\TestCase;

final class SourceProbeTest extends TestCase
{
    use PipelineFixtures;

    private const URL = 'https://map.example/nodes.json';

    private RunState $state;

    protected function setUp(): void
    {
        $this->state = new RunState();
    }

    /**
     * @param array<string, string|int|null> $answers
     */
    private function probe(array $answers, ?LastGoodStore $lastGood = null): SourceProbe
    {
        return new SourceProbe($this->fetcher($answers), new SourceParser(), $this->state, $lastGood, retryDelayMs: 0);
    }

    public function testFetchesAndParses(): void
    {
        $this->probe([self::URL => self::nodeInfoBody()])->probe([self::URL], self::now());

        $this->assertSame(Format::NodeInfo, $this->state->parsed(self::URL)?->format);
        $this->assertTrue($this->state->hasNodes(self::URL));
        $this->assertSame(1, $this->state->requests());
    }

    public function testAServerErrorIsAskedOnceMore(): void
    {
        $this->probe([self::URL => 503])->probe([self::URL], self::now());

        $this->assertSame([self::URL, self::URL], $this->requested);
        $this->assertSame(2, $this->state->requests());
    }

    public function testAnEmptyBodyIsAskedOnceMore(): void
    {
        $this->probe([self::URL => ''])->probe([self::URL], self::now());

        $this->assertSame([self::URL, self::URL], $this->requested);
    }

    public function testNoAnswerAndNotFoundAreNotAskedAgain(): void
    {
        $this->probe([self::URL => null])->probe([self::URL], self::now());
        $this->probe([])->probe(['https://map.example/other.json'], self::now());

        $this->assertSame([self::URL, 'https://map.example/other.json'], $this->requested);
    }

    public function testGoodDataIsRememberedAndStandsInForAServerWithTrouble(): void
    {
        $lastGood = new LastGoodStore();
        $this->probe([self::URL => self::nodeInfoBody()], $lastGood)->probe([self::URL], self::now());

        $later = self::now()->modify('+1 hour');
        $this->state = new RunState();
        $this->probe([self::URL => 503], $lastGood)->probe([self::URL], $later);

        $this->assertTrue($this->state->hasNodes(self::URL));
        $this->assertEquals(self::now(), $this->state->staleSince(self::URL));
        $warnings = ($this->state->parsed(self::URL) ?? self::nodes([]))->warnings;
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('source answered with HTTP 503; showing the nodes saved at 2026-10-30 12:00', $warnings[0]);
    }

    public function testNoAnswerAndEmptyBodyFallBackToo(): void
    {
        $lastGood = new LastGoodStore();
        $lastGood->save(self::URL, self::nodes(['1']), self::now());

        foreach ([null, ''] as $answer) {
            $this->state = new RunState();
            $this->probe([self::URL => $answer], $lastGood)->probe([self::URL], self::now());

            $this->assertTrue($this->state->hasNodes(self::URL), 'answer: ' . var_export($answer, true));
        }
    }

    public function testNotFoundIsBelievedEvenIfThereIsAnOldCopy(): void
    {
        $lastGood = new LastGoodStore();
        $lastGood->save(self::URL, self::nodes(['1']), self::now());

        $this->probe([], $lastGood)->probe([self::URL], self::now());

        $this->assertFalse($this->state->hasNodes(self::URL));
        $this->assertNull($this->state->staleSince(self::URL));
    }

    public function testACopyThatIsTooOldIsNotShown(): void
    {
        $lastGood = new LastGoodStore(ttl: 3600);
        $lastGood->save(self::URL, self::nodes(['1']), self::now());

        $this->probe([self::URL => 503], $lastGood)->probe([self::URL], self::now()->modify('+2 hours'));

        $this->assertFalse($this->state->hasNodes(self::URL));
    }

    public function testWithoutAStoreTroubleStaysTrouble(): void
    {
        $this->probe([self::URL => 503])->probe([self::URL], self::now());

        $this->assertFalse($this->state->hasNodes(self::URL));
    }
}
