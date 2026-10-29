<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Publish;

use FreifunkKarte\Publish\Delivery;
use FreifunkKarte\Publish\Publisher;
use FreifunkKarte\Publish\PublishResult;
use FreifunkKarte\Publish\ResultFiles;
use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase
{
    private static function publisher(string $name, bool $ok, bool $throws = false): Publisher
    {
        return new class ($name, $ok, $throws) implements Publisher {
            public function __construct(private readonly string $name, private readonly bool $ok, private readonly bool $throws)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function publish(ResultFiles $files): PublishResult
            {
                if ($this->throws) {
                    throw new \RuntimeException('exploded');
                }

                return new PublishResult($this->name, $this->ok, $this->ok ? 'fine' : 'refused', 0.1);
            }
        };
    }

    private static function files(): ResultFiles
    {
        return new ResultFiles(['result_routers.json' => '[]', 'result_communities.json' => '{}']);
    }

    public function testEverythingWorks(): void
    {
        $pings = new \ArrayObject();
        $delivery = new Delivery([[self::publisher('a', true), true], [self::publisher('b', true), false]], static function () use ($pings): void {
            $pings->append(1);
        });

        $report = $delivery->deliver(self::files());

        $this->assertTrue($report->delivered());
        $this->assertSame([], $report->warnings);
        $this->assertCount(2, $report->results);
        $this->assertCount(1, $pings, 'statistics are pinged once');
    }

    public function testARequiredPublisherThatFailsFailsTheDeliveryEvenIfAnotherWorked(): void
    {
        $pings = new \ArrayObject();
        $delivery = new Delivery([[self::publisher('https', false), true], [self::publisher('ftp', true), false]], static function () use ($pings): void {
            $pings->append(1);
        });

        $report = $delivery->deliver(self::files());

        $this->assertFalse($report->delivered());
        $this->assertSame('https: refused', $report->error);
        $this->assertCount(0, $pings, 'no ping when the delivery failed');
    }

    public function testAnOptionalPublisherThatFailsIsOnlyAWarning(): void
    {
        $report = (new Delivery([[self::publisher('https', true), true], [self::publisher('ftp', false), false]]))->deliver(self::files());

        $this->assertTrue($report->delivered());
        $this->assertSame(['ftp: refused'], $report->warnings);
    }

    public function testIfNothingWorkedItFailsEvenWhenNoneIsRequired(): void
    {
        $report = (new Delivery([[self::publisher('a', false), false], [self::publisher('b', false), false]]))->deliver(self::files());

        $this->assertFalse($report->delivered());
        $this->assertSame('a: refused; b: refused', $report->error);
        $this->assertSame([], $report->warnings);
    }

    public function testAPublisherThatThrowsCountsAsFailed(): void
    {
        $report = (new Delivery([[self::publisher('a', true, throws: true), true]]))->deliver(self::files());

        $this->assertFalse($report->delivered());
        $this->assertSame('a: exploded', $report->error);
    }

    public function testAFailingStatisticsPingIsOnlyAWarning(): void
    {
        $report = (new Delivery([[self::publisher('a', true), true]], static function (): void {
            throw new \RuntimeException('HTTP 500');
        }))->deliver(self::files());

        $this->assertTrue($report->delivered());
        $this->assertSame(['statistics ping failed: HTTP 500'], $report->warnings);
    }

    public function testNoPublishersIsKnown(): void
    {
        $delivery = new Delivery([]);

        $this->assertFalse($delivery->hasPublishers());
        $this->assertTrue($delivery->deliver(self::files())->delivered());
    }
}
