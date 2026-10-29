<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Http;

use FreifunkKarte\Http\FetchQueue;
use PHPUnit\Framework\TestCase;

final class FetchQueueTest extends TestCase
{
    public function testHandsOutUrlsInOrder(): void
    {
        $queue = new FetchQueue(['https://a.test/1', 'https://b.test/1', 'https://c.test/1'], 2);

        $this->assertSame('https://a.test/1', $queue->next());
        $this->assertSame('https://b.test/1', $queue->next());
        $this->assertSame('https://c.test/1', $queue->next());
        $this->assertNull($queue->next());
        $this->assertFalse($queue->hasPending());
    }

    public function testNoHostGetsMoreThanTheLimitInFlight(): void
    {
        $queue = new FetchQueue(['https://a.test/1', 'https://a.test/2', 'https://a.test/3', 'https://b.test/1'], 2);

        $this->assertSame('https://a.test/1', $queue->next());
        $this->assertSame('https://a.test/2', $queue->next());
        // a.test is full: the next request goes to another host although a.test/3 is earlier in line
        $this->assertSame('https://b.test/1', $queue->next());
        $this->assertNull($queue->next());
        $this->assertTrue($queue->hasPending());
    }

    public function testFinishedRequestMakesRoomAgain(): void
    {
        $queue = new FetchQueue(['https://a.test/1', 'https://a.test/2', 'https://a.test/3'], 1);

        $this->assertSame('https://a.test/1', $queue->next());
        $this->assertNull($queue->next());

        $queue->done('https://a.test/1');

        $this->assertSame('https://a.test/2', $queue->next());
        $this->assertNull($queue->next());
    }

    public function testHostsAreComparedCaseInsensitively(): void
    {
        $queue = new FetchQueue(['https://A.test/1', 'https://a.TEST/2'], 1);

        $this->assertSame('https://A.test/1', $queue->next());
        $this->assertNull($queue->next());
    }

    public function testWaitingUrlsAreNeverLost(): void
    {
        $urls = array_map(static fn (int $i): string => 'https://' . ($i % 3 === 0 ? 'busy' : 'host' . $i) . '.test/' . $i, range(1, 30));
        $queue = new FetchQueue($urls, 2);
        $handedOut = [];
        /** @var list<string> $inFlight */
        $inFlight = [];

        do {
            while (($url = $queue->next()) !== null) {
                $inFlight[] = $url;
                $handedOut[] = $url;
            }

            // one request finishes per round
            $finished = array_shift($inFlight);

            if ($finished !== null) {
                $queue->done($finished);
            }
        } while ($finished !== null || $queue->hasPending());

        sort($urls);
        sort($handedOut);
        $this->assertSame($urls, $handedOut);
    }
}
