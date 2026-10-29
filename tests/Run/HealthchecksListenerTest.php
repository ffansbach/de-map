<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Run;

use DateTimeImmutable;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\Listener\HealthchecksListener;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Run\RunSummary;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class HealthchecksListenerTest extends TestCase
{
    private const URL = 'https://hc-ping.com/abc-123';

    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $history;

    private function listener(string $failOn = DriftEvent::ALERT, int $responses = 3, int $status = 200): HealthchecksListener
    {
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler(array_fill(0, $responses, new Response($status))));
        $stack->push(Middleware::history($history));
        $this->assertInstanceOf(\ArrayObject::class, $history);
        $this->history = $history;

        return new HealthchecksListener(new Client(['handler' => $stack]), self::URL, $failOn);
    }

    /**
     * @return array{string, string, string} method, url, body of the n-th request
     */
    private function sent(int $index): array
    {
        $entry = $this->history[$index] ?? null;
        $this->assertIsArray($entry);
        $request = $entry['request'];

        return [$request->getMethod(), (string) $request->getUri(), (string) $request->getBody()];
    }

    /**
     * @param list<DriftEvent> $events
     */
    private function summary(string $status = RunRepository::OK, array $events = [], ?string $error = null, bool $withResult = true): RunSummary
    {
        return new RunSummary(7, $status, $withResult ? RunFixtures::standard() : null, $events, $error, new DateTimeImmutable(), 0);
    }

    public function testStartPing(): void
    {
        $this->listener()->started(7, new DateTimeImmutable());

        $this->assertSame(['POST', self::URL . '/start', ''], $this->sent(0));
    }

    public function testQuietRunIsASuccessPingWithTheAccountOfTheRun(): void
    {
        $this->listener()->finished($this->summary());

        [$method, $url, $body] = $this->sent(0);
        $this->assertSame([self::URL, 'POST'], [$url, $method]);
        $this->assertStringStartsWith('run 7: ok, 40 nodes, 13 communities,', $body);
    }

    public function testWarningsDoNotFailTheCheckByDefaultButAreInTheBody(): void
    {
        $events = [
            new DriftEvent(DriftEvent::NEW_TYPE, DriftEvent::WARNING, 'yanic', 'Technical type "yanic" has not been seen before (used by newcomer)'),
            new DriftEvent(DriftEvent::NEW_WARNING, DriftEvent::INFO, 'a', 'something small'),
        ];

        $this->listener()->finished($this->summary(events: $events));

        [, $url, $body] = $this->sent(0);
        $this->assertSame(self::URL, $url, 'a success ping: this is not a widespread failure');
        $this->assertStringContainsString('warning: Technical type "yanic" has not been seen before', $body);
        $this->assertStringContainsString('(and 1 notes of lower importance, see the log)', $body);
        $this->assertStringNotContainsString('something small', $body);
    }

    public function testWithFailOnWarningAWarningFailsTheCheck(): void
    {
        $this->listener(failOn: DriftEvent::WARNING)->finished($this->summary(events: [new DriftEvent(DriftEvent::NODES_DROPPED, DriftEvent::WARNING, 'a', 'a lost nodes')]));

        [, $url, $body] = $this->sent(0);
        $this->assertSame(self::URL . '/fail', $url);
        $this->assertStringContainsString('warning: a lost nodes', $body);
    }

    public function testWidespreadFailuresFailTheCheck(): void
    {
        foreach ([DriftEvent::RUN_NODES_DROPPED, DriftEvent::MANY_COMMUNITIES_LOST] as $kind) {
            $listener = $this->listener();
            $listener->finished($this->summary(events: [new DriftEvent($kind, DriftEvent::ALERT, 'run', 'a fifth of the map is gone')]));

            $this->assertSame(self::URL . '/fail', $this->sent(0)[1], $kind);
        }
    }

    public function testNotBeingAbleToDeliverTheResultFailsTheCheck(): void
    {
        $this->listener()->finished($this->summary(events: [new DriftEvent(DriftEvent::DELIVERY_FAILED, DriftEvent::ALERT, 'run', 'The result could not be delivered to the web server: FTP login failed')]));

        [, $url, $body] = $this->sent(0);
        $this->assertSame(self::URL . '/fail', $url);
        $this->assertStringContainsString('alert: The result could not be delivered to the web server: FTP login failed', $body);
    }

    public function testAlertsComeFirstInTheBody(): void
    {
        $this->listener()->finished($this->summary(events: [
            new DriftEvent(DriftEvent::NODES_DROPPED, DriftEvent::WARNING, 'a', 'warn text'),
            new DriftEvent(DriftEvent::RUN_NODES_DROPPED, DriftEvent::ALERT, 'run', 'alert text'),
        ]));

        $this->assertLessThan(strpos($this->sent(0)[2], 'warn text'), strpos($this->sent(0)[2], 'alert text'));
    }

    public function testRefusedAndFailedRunsFail(): void
    {
        $listener = $this->listener();
        $listener->finished($this->summary(RunRepository::REFUSED));
        $listener->finished($this->summary(RunRepository::FAILED, [new DriftEvent(DriftEvent::RUN_FAILED, DriftEvent::ALERT, 'run', 'The run failed: boom')], 'boom', false));

        $this->assertSame(self::URL . '/fail', $this->sent(0)[1]);
        $this->assertSame(self::URL . '/fail', $this->sent(1)[1]);
        $this->assertStringContainsString('run 7: failed - boom', $this->sent(1)[2]);
    }

    public function testTheBodyIsKeptShort(): void
    {
        $events = array_map(static fn (int $i): DriftEvent => new DriftEvent(DriftEvent::NODES_DROPPED, DriftEvent::WARNING, 'c' . $i, str_repeat('x', 200)), range(1, 200));

        $this->listener()->finished($this->summary(events: $events));

        $this->assertLessThanOrEqual(10000, mb_strlen($this->sent(0)[2]));
    }

    public function testAnErrorAnswerOfTheServiceIsNoProblem(): void
    {
        $this->listener(status: 500)->finished($this->summary());

        $this->assertCount(1, $this->history);
    }
}
