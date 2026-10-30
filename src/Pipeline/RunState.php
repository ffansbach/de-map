<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline;

use DateTimeImmutable;
use FreifunkKarte\Audit\Format;
use FreifunkKarte\Http\FetchResult;
use FreifunkKarte\Parser\ParseResult;

/**
 * What one run has learned so far: every response, what was parsed from it, which data is a
 * copy from an earlier run. The stages of the run write to it and read from it; a new run
 * starts with a new instance.
 */
final class RunState
{
    /** @var array<string, FetchResult> */
    private array $fetches = [];

    /** @var array<string, ParseResult> */
    private array $parsed = [];

    /** @var array<string, DateTimeImmutable> data URL => when the nodes shown for it were saved */
    private array $stale = [];

    /** @var array<string, DateTimeImmutable> community key => when the copy of its API file was saved, if that copy is used */
    private array $staleApi = [];

    private int $requests = 0;

    /**
     * @param array<string, FetchResult> $results
     */
    public function recordFetches(array $results): void
    {
        $this->requests += count($results);

        foreach ($results as $url => $result) {
            $this->fetches[$url] = $result;
        }
    }

    public function requests(): int
    {
        return $this->requests;
    }

    public function fetch(string $url): ?FetchResult
    {
        return $this->fetches[$url] ?? null;
    }

    public function isFetched(string $url): bool
    {
        return isset($this->fetches[$url]);
    }

    public function parsed(string $url): ?ParseResult
    {
        return $this->parsed[$url] ?? null;
    }

    public function setParsed(string $url, ParseResult $result): void
    {
        $this->parsed[$url] = $result;
    }

    public function forgetParsed(string $url): void
    {
        unset($this->parsed[$url]);
    }

    /**
     * Whether the URL answered with something that is a node source and has at least one entry.
     */
    public function hasNodes(string $url): bool
    {
        $result = $this->parsed[$url] ?? null;

        return $result !== null && $result->isUsable() && $result->found > 0;
    }

    /**
     * @param list<string> $urls
     */
    public function anyNodelist(array $urls): bool
    {
        foreach ($urls as $url) {
            if (($this->parsed[$url] ?? null)?->format === Format::Nodelist) {
                return true;
            }
        }

        return false;
    }

    public function markStale(string $url, DateTimeImmutable $savedAt): void
    {
        $this->stale[$url] = $savedAt;
    }

    public function staleSince(string $url): ?DateTimeImmutable
    {
        return $this->stale[$url] ?? null;
    }

    public function markApiStale(string $community, DateTimeImmutable $savedAt): void
    {
        $this->staleApi[$community] = $savedAt;
    }

    public function apiStaleSince(string $community): ?DateTimeImmutable
    {
        return $this->staleApi[$community] ?? null;
    }

    /**
     * @return list<FetchRecord> every URL requested, with what was found behind it
     */
    public function fetchRecords(): array
    {
        $records = [];

        foreach ($this->fetches as $url => $fetch) {
            $parsed = $this->parsed[$url] ?? null;
            $records[] = new FetchRecord($url, $fetch->status, $fetch->error, $fetch->bytes, $fetch->seconds, $fetch->tlsInvalid, $parsed?->format->value, $parsed !== null ? $parsed->found : 0);
        }

        return $records;
    }
}
