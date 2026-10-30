<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline;

use DateTimeImmutable;
use FreifunkKarte\Http\FetchResult;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Pipeline\Resolve\FetchPolicy;
use FreifunkKarte\Source\ApiDocumentStore;
use FreifunkKarte\Source\Directory;
use RuntimeException;

/**
 * The first two rounds of a run: the community directory, then the API file of every community in it.
 */
final class CommunityBuilder
{
    public function __construct(
        private readonly ParallelFetcher $fetcher,
        private readonly RunState $state,
        private readonly string $directoryUrl,
        private readonly ?ApiDocumentStore $apiDocuments = null,
    ) {
    }

    /**
     * @return array<string, string> community key => API file URL
     */
    public function directory(?string $only): array
    {
        $body = null;
        $results = $this->fetcher->fetch([$this->directoryUrl], static function (string $url, string $content) use (&$body): void {
            $body = $content;
        });
        $this->state->recordFetches($results);

        $directory = Directory::fromJson((string) $body);

        if ($directory === []) {
            throw new RuntimeException('Community directory not available: ' . $this->directoryUrl);
        }

        return $only === null ? $directory : array_intersect_key($directory, [$only => true]);
    }

    /**
     * @param array<string, string> $directory
     * @param list<string> $skip communities that are not looked at, not even their API file
     * @return list<CommunityBuild>
     */
    public function fromDirectory(array $directory, array $skip, DateTimeImmutable $now): array
    {
        $documents = [];
        $wanted = array_values(array_diff_key($directory, array_flip($skip)));
        $fetches = $this->fetcher->fetch($wanted, static function (string $url, string $body) use (&$documents): void {
            $document = json_decode($body, true);

            if (is_array($document)) {
                $documents[$url] = $document;
            }
        });
        $this->state->recordFetches($fetches);

        $builds = [];

        foreach ($directory as $key => $apiUrl) {
            if (in_array($key, $skip, true)) {
                $builds[] = CommunityBuild::skipped($key, $apiUrl);
                continue;
            }

            $document = $documents[$apiUrl] ?? null;

            if ($document === null) {
                $builds[] = $this->withoutDocument($key, $apiUrl, $fetches[$apiUrl] ?? null, $now);
                continue;
            }

            if (is_array($document['nodeMaps'] ?? null)) {
                $this->apiDocuments?->save($apiUrl, $document, $now);
            }

            $builds[] = CommunityBuild::fromApiDocument($key, $apiUrl, $document);
        }

        return $builds;
    }

    /**
     * The API file did not come: the copy of an earlier run if the server only has trouble,
     * otherwise a community without data. A file that is not there (404) is believed.
     */
    private function withoutDocument(string $key, string $apiUrl, ?FetchResult $fetch, DateTimeImmutable $now): CommunityBuild
    {
        $mayUseCopy = $fetch !== null && ($fetch->status === 200 || FetchPolicy::hasTrouble($fetch));
        $stored = $mayUseCopy ? $this->apiDocuments?->load($apiUrl, $now) : null;

        if ($stored === null) {
            return CommunityBuild::apiUnavailable($key, $apiUrl, FetchPolicy::describe($fetch));
        }

        $this->state->markApiStale($key, $stored['savedAt']);

        return CommunityBuild::fromApiDocument($key, $apiUrl, $stored['document'])
            ->withMessage(sprintf('API file not available (%s); using the copy saved at %s', FetchPolicy::describe($fetch), $stored['savedAt']->format('Y-m-d H:i')));
    }
}
