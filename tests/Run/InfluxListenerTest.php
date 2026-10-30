<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Run;

use DateTimeImmutable;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\Listener\InfluxListener;
use FreifunkKarte\Run\Listener\InfluxPointWriter;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Run\RunSummary;
use PHPUnit\Framework\TestCase;

final class InfluxListenerTest extends TestCase
{
    /**
     * @return array{InfluxListener, object{points: list<array{string, array<string, string>, array<string, int|float|string|bool>}>}}
     */
    private function listener(): array
    {
        $writer = new class () implements InfluxPointWriter {
            /** @var list<array{string, array<string, string>, array<string, int|float|string|bool>}> */
            public array $points = [];

            public function write(string $measurement, array $tags, array $fields): void
            {
                $this->points[] = [$measurement, $tags, $fields];
            }
        };

        return [new InfluxListener($writer, 'live'), $writer];
    }

    public function testFinishedRunWritesTheMeasurementAndFieldsOfTheDashboards(): void
    {
        [$listener, $writer] = $this->listener();
        $events = [new DriftEvent('a', DriftEvent::WARNING, 's', 'm'), new DriftEvent('b', DriftEvent::ALERT, 's', 'm'), new DriftEvent('c', DriftEvent::INFO, 's', 'm')];

        $listener->finished(new RunSummary(1, RunRepository::OK, RunFixtures::standard(), $events, null, new DateTimeImmutable(), 52428800));

        $this->assertCount(1, $writer->points);
        [$measurement, $tags, $fields] = $writer->points[0];
        $this->assertSame('parser_result_count', $measurement);
        $this->assertSame([], $tags);
        // the field names the dashboards read
        $this->assertSame(40, $fields['routers']);
        $this->assertSame(13, $fields['communities']);
        $this->assertSame(52428800, $fields['mem_usage']);
        $this->assertSame('live', $fields['env']);
        $this->assertArrayHasKey('parse_time', $fields);
        $this->assertArrayHasKey('curl_calls', $fields);
        // and new ones
        $this->assertSame(10, $fields['communities_with_nodes']);
        $this->assertSame(1, $fields['events_warning']);
        $this->assertSame(1, $fields['events_alert']);
    }

    public function testRefusedAndFailedRunsWriteNothing(): void
    {
        [$listener, $writer] = $this->listener();

        $listener->finished(new RunSummary(1, RunRepository::REFUSED, RunFixtures::standard(), [], null, new DateTimeImmutable(), 0));
        $listener->finished(new RunSummary(2, RunRepository::FAILED, null, [], 'boom', new DateTimeImmutable(), 0));
        $listener->started(3, new DateTimeImmutable());

        $this->assertSame([], $writer->points);
    }
}
