<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Resolve;

use DateTimeImmutable;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Source\LastGoodStore;

/**
 * Fetches a batch of data URLs and parses what comes back: once more for the ones whose server
 * only stumbled, and with the last good nodes of a source standing in if it still has trouble.
 */
final class SourceProbe
{
    public function __construct(
        private readonly ParallelFetcher $fetcher,
        private readonly SourceParser $parser,
        private readonly RunState $state,
        private readonly ?LastGoodStore $lastGood = null,
        private readonly int $retryDelayMs = 1000,
    ) {
    }

    /**
     * @param list<string> $urls
     */
    public function probe(array $urls, DateTimeImmutable $now): void
    {
        $this->fetchInto($urls, $now);
        $this->retryTransient($urls, $now);

        foreach ($urls as $url) {
            $this->settle($url, $now);
        }
    }

    /**
     * Servers under load answer with errors or empty bodies; once more after a short pause is cheap.
     * Timeouts are not retried: a host that timed out usually does it again.
     *
     * @param list<string> $urls
     */
    private function retryTransient(array $urls, DateTimeImmutable $now): void
    {
        $transient = array_values(array_filter($urls, fn (string $url): bool => FetchPolicy::isTransient($this->state->fetch($url), $this->state->parsed($url))));

        if ($transient === []) {
            return;
        }

        if ($this->retryDelayMs > 0) {
            usleep($this->retryDelayMs * 1000);
        }

        foreach ($transient as $url) {
            $this->state->forgetParsed($url);
        }

        $this->fetchInto($transient, $now);
    }

    /**
     * Remembers what a source delivered; if it only has trouble right now, falls back on that.
     */
    private function settle(string $url, DateTimeImmutable $now): void
    {
        if ($this->lastGood === null) {
            return;
        }

        $parsed = $this->state->parsed($url);

        if ($parsed !== null && $parsed->isUsable() && $parsed->nodes !== []) {
            $this->lastGood->save($url, $parsed, $now);

            return;
        }

        $fetch = $this->state->fetch($url);

        if ($fetch === null || !FetchPolicy::mayFallBack($fetch, $parsed)) {
            return;
        }

        $stored = $this->lastGood->load($url, $now);

        if ($stored === null) {
            return;
        }

        $this->state->setParsed($url, $stored['result']->withWarnings([
            sprintf('source answered with %s; showing the nodes saved at %s', FetchPolicy::fallbackReason($fetch), $stored['savedAt']->format('Y-m-d H:i')),
        ]));
        $this->state->markStale($url, $stored['savedAt']);
    }

    /**
     * @param list<string> $urls
     */
    private function fetchInto(array $urls, DateTimeImmutable $now): void
    {
        $this->state->recordFetches($this->fetcher->fetch($urls, function (string $url, string $body) use ($now): void {
            $this->state->setParsed($url, $this->parser->parse($body, $now));
        }));
    }
}
