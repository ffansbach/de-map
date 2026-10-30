<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline;

use Closure;
use DateTimeImmutable;
use FreifunkKarte\Audit\Format;
use FreifunkKarte\Community\CommunityInfo;
use FreifunkKarte\Community\MetaCommunityResolver;
use FreifunkKarte\Http\FetchResult;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Model\NodeCollection;
use FreifunkKarte\Parser\ParseResult;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Source\ApiDocumentStore;
use FreifunkKarte\Source\Directory;
use FreifunkKarte\Source\Discovery;
use FreifunkKarte\Source\LastGoodStore;
use FreifunkKarte\Source\SourceAssignments;
use FreifunkKarte\Source\SourceCache;
use RuntimeException;

/**
 * One complete run: directory, API files, node sources, parsing, deduplication.
 *
 * Everything that waits for the network is done for all communities at once, in rounds:
 *   1. the directory, 2. all API files, 3. the maps - for each map one stage per round:
 *   cached data URLs, the declared URL, its config.json, the dataPath URLs from that, the
 *   other usual locations. A map stops at the first stage that yields nodes, like the legacy
 *   parser did, but all maps advance together.
 *
 * Priorities (as before): a community with a nodelist uses only that; otherwise the first map
 * that yields nodes; nodelist communities come first, fixed communities last; the first
 * community to deliver a node keeps it.
 */
final class ParseRun
{
    private const MAX_ROUNDS = 8;

    /** @var array<string, FetchResult> */
    private array $fetches = [];

    /** @var array<string, ParseResult> */
    private array $parsed = [];

    /** @var array<string, DateTimeImmutable> data URL => when the nodes shown for it were saved */
    private array $stale = [];

    /** @var array<string, DateTimeImmutable> community key => when the copy of its API file was saved, if that copy is used */
    private array $staleApi = [];

    private int $requests = 0;

    public function __construct(
        private readonly ParallelFetcher $fetcher,
        private readonly SourceParser $parser,
        private readonly SourceCache $cache,
        private readonly MetaCommunityResolver $metaResolver,
        private readonly ?Closure $clock = null,
        private readonly string $directoryUrl = Directory::URL,
        private readonly int $retryDelayMs = 1000,
        private readonly ?LastGoodStore $lastGood = null,
        private readonly SourceAssignments $assignments = new SourceAssignments(),
        private readonly ?ApiDocumentStore $apiDocuments = null,
    ) {
    }

    /**
     * @param array<string, array{url: string, parser: string, name: string, homePage: string}> $fixed
     * @param list<string> $skip directory communities that are not looked at
     * @param callable(string): void|null $log progress messages
     */
    public function run(array $fixed = [], array $skip = [], ?string $only = null, ?callable $log = null): RunResult
    {
        $log ??= static function (string $message): void {
        };
        $this->fetches = [];
        $this->parsed = [];
        $this->stale = [];
        $this->staleApi = [];
        $this->requests = 0;
        $started = microtime(true);
        $now = $this->now();

        $log('fetching community directory');
        $directory = $this->fetchDirectory($only);

        $log(sprintf('fetching %d community API files', count($directory)));
        $builds = $this->buildCommunities($directory, $skip, $now);

        foreach ($fixed as $key => $source) {
            if ($only === null || $only === $key) {
                $builds[] = $this->buildFixed($key, $source);
            }
        }

        $entries = [];

        foreach ($builds as $build) {
            array_push($entries, ...$build->entries);
        }

        $this->resolveSources($entries, $now, $log);

        $reports = $this->assemble($builds, $nodes = new NodeCollection(), $now);
        $this->cache->save($now);
        $this->lastGood?->prune($now);
        $this->apiDocuments?->prune($now);

        $listed = array_values(array_filter($reports, static fn (CommunityReport $r): bool => $r->listed));
        $metaLinks = $this->metaResolver->resolve(array_map(
            static fn (CommunityReport $r): CommunityInfo => new CommunityInfo($r->key, $r->name, $r->url, $r->meta),
            $listed,
        ));

        return new RunResult($nodes, $reports, $metaLinks, $now, microtime(true) - $started, $this->requests, $this->fetchRecords());
    }

    /**
     * @return list<FetchRecord>
     */
    private function fetchRecords(): array
    {
        $records = [];

        foreach ($this->fetches as $url => $fetch) {
            $parsed = $this->parsed[$url] ?? null;
            $records[] = new FetchRecord($url, $fetch->status, $fetch->error, $fetch->bytes, $fetch->seconds, $fetch->tlsInvalid, $parsed?->format->value, $parsed !== null ? $parsed->found : 0);
        }

        return $records;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable();
    }

    /**
     * @return array<string, string> community key => API file URL
     */
    private function fetchDirectory(?string $only): array
    {
        $body = null;
        $results = $this->fetcher->fetch([$this->directoryUrl], static function (string $url, string $content) use (&$body): void {
            $body = $content;
        });
        $this->requests += count($results);
        $this->fetches = [...$this->fetches, ...$results];

        $directory = Directory::fromJson((string) $body);

        if ($directory === []) {
            throw new RuntimeException('Community directory not available: ' . $this->directoryUrl);
        }

        return $only === null ? $directory : array_intersect_key($directory, [$only => true]);
    }

    /**
     * @param array<string, string> $directory
     * @param list<string> $skip
     * @return list<CommunityBuild>
     */
    private function buildCommunities(array $directory, array $skip, DateTimeImmutable $now): array
    {
        /** @var array<string, array<array-key, mixed>> $documents */
        $documents = [];
        // communities that are skipped are not looked at, not even their API file
        $wanted = array_values(array_diff_key($directory, array_flip($skip)));
        $apiFetches = $this->fetcher->fetch($wanted, static function (string $url, string $body) use (&$documents): void {
            $document = json_decode($body, true);

            if (is_array($document)) {
                $documents[$url] = $document;
            }
        });
        $this->requests += count($apiFetches);
        $this->fetches = [...$this->fetches, ...$apiFetches];

        $builds = [];

        foreach ($directory as $key => $apiUrl) {
            $document = $documents[$apiUrl] ?? null;
            $placeholder = new CommunityInfo($key, $key, '');

            if (in_array($key, $skip, true)) {
                $builds[] = new CommunityBuild($placeholder, 'directory', $apiUrl, false, CommunityReport::SKIPPED, messages: ['skipped by configuration']);
                continue;
            }

            if ($document === null) {
                $fetch = $apiFetches[$apiUrl] ?? null;
                // a file that is not there (404) is believed; a server that does not answer properly is not
                $stored = $fetch !== null && ($fetch->status === 200 || self::hasTrouble($fetch)) ? $this->apiDocuments?->load($apiUrl, $now) : null;

                if ($stored !== null) {
                    $build = $this->buildFromDocument($key, $apiUrl, $stored['document']);
                    $build->messages[] = sprintf('API file not available (%s); using the copy saved at %s', self::describe($fetch), $stored['savedAt']->format('Y-m-d H:i'));
                    $this->staleApi[$key] = $stored['savedAt'];
                    $builds[] = $build;
                    continue;
                }

                $builds[] = new CommunityBuild($placeholder, 'directory', $apiUrl, false, CommunityReport::API_UNAVAILABLE, messages: [
                    'API file not available: ' . self::describe($fetch),
                ]);
                continue;
            }

            if (is_array($document['nodeMaps'] ?? null)) {
                $this->apiDocuments?->save($apiUrl, $document, $now);
            }

            $builds[] = $this->buildFromDocument($key, $apiUrl, $document);
        }

        return $builds;
    }

    /**
     * @param array<array-key, mixed> $document
     */
    private function buildFromDocument(string $key, string $apiUrl, array $document): CommunityBuild
    {
        $name = is_string($document['name'] ?? null) && $document['name'] !== '' ? $document['name'] : $key;
        $homePage = $document['homePage'] ?? null;
        $url = is_string($homePage) && $homePage !== '' ? $homePage : (is_string($document['url'] ?? null) ? $document['url'] : '');
        $meta = is_string($document['metacommunity'] ?? null) && $document['metacommunity'] !== '' ? $document['metacommunity'] : null;
        $info = new CommunityInfo($key, $name, $url, $meta);
        $state = is_array($document['state'] ?? null) ? $document['state'] : [];
        $claimed = !empty($state['nodes']) && is_numeric($state['nodes']) ? (int) $state['nodes'] : null;

        $maps = $document['nodeMaps'] ?? null;

        if (!is_array($maps)) {
            return new CommunityBuild($info, 'directory', $apiUrl, false, CommunityReport::NO_NODEMAPS, messages: ['API file has no nodeMaps'], claimedNodes: $claimed);
        }

        if (json_encode(['name' => $name, 'url' => $url]) === false) {
            return new CommunityBuild($info, 'directory', $apiUrl, false, CommunityReport::NO_DATA, messages: ['name or url are corrupt (not valid UTF-8) - ignored'], claimedNodes: $claimed);
        }

        $entries = [];
        $types = [];
        $messages = $meta !== null ? ['meta-community: ' . $meta] : [];

        foreach ($maps as $map) {
            if (!is_array($map) || !is_string($map['url'] ?? null) || trim($map['url']) === '') {
                $messages[] = 'ignored a map without url';
                continue;
            }

            $type = is_string($map['technicalType'] ?? null) ? strtolower($map['technicalType']) : '';
            $types[] = $type !== '' ? $type : '(none)';

            if ($type === '') {
                $messages[] = 'ignored map without technicalType: ' . $map['url'];
                continue;
            }

            $entries[] = new SourceEntry($key, $type, Discovery::withScheme($map['url']));
        }

        return new CommunityBuild($info, 'directory', $apiUrl, true, CommunityReport::NO_DATA, $entries, $messages, $types, $claimed);
    }

    /**
     * @param array{url: string, parser: string, name: string, homePage: string} $source
     */
    private function buildFixed(string $key, array $source): CommunityBuild
    {
        $info = new CommunityInfo($key, $source['name'], $source['homePage']);
        $type = strtolower($source['parser']);

        return new CommunityBuild($info, 'fixed', null, true, CommunityReport::NO_DATA, [new SourceEntry($key, $type, Discovery::withScheme($source['url']))], ['fixed community'], [$type]);
    }

    /**
     * @param list<SourceEntry> $entries
     * @param callable(string): void $log
     */
    private function resolveSources(array $entries, DateTimeImmutable $now, callable $log): void
    {
        $byCommunity = [];

        foreach ($entries as $entry) {
            $byCommunity[$entry->community][] = $entry;
        }

        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $urls = [];

            foreach ($entries as $entry) {
                if ($this->waitsForNodelist($entry, $byCommunity[$entry->community], $now)) {
                    continue;
                }

                $step = $this->walk($entry, $now);

                if ($step['status'] === 'fetch') {
                    array_push($urls, ...$step['urls']);
                }
            }

            $urls = array_values(array_unique($urls));

            if ($urls === []) {
                return;
            }

            $log(sprintf('round %d: %d URLs', $round, count($urls)));
            $this->probe($urls, $now);
        }
    }

    /**
     * A community that declares a nodelist uses nothing else if the nodelist delivers. So its other
     * maps are not touched while the nodelist is still being looked at, and never if it delivers.
     *
     * @param list<SourceEntry> $siblings all maps of the entry's community
     */
    private function waitsForNodelist(SourceEntry $entry, array $siblings, DateTimeImmutable $now): bool
    {
        if ($entry->declaredType === 'nodelist') {
            return false;
        }

        foreach ($siblings as $sibling) {
            if ($sibling->declaredType !== 'nodelist') {
                continue;
            }

            $step = $this->walk($sibling, $now);

            if ($step['status'] === 'fetch' || ($step['status'] === 'resolved' && $this->isNodelist($step['urls']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $urls
     */
    private function probe(array $urls, DateTimeImmutable $now): void
    {
        $this->fetchInto($urls, $now);

        // Servers under load answer with errors or empty bodies; once more after a short pause
        // is cheap. Timeouts are not retried: a host that timed out usually does it again.
        $transient = array_values(array_filter($urls, $this->isTransient(...)));

        if ($transient !== []) {
            if ($this->retryDelayMs > 0) {
                usleep($this->retryDelayMs * 1000);
            }

            foreach ($transient as $url) {
                unset($this->parsed[$url]);
            }

            $this->fetchInto($transient, $now);
        }

        foreach ($urls as $url) {
            $this->settle($url, $now);
        }
    }

    /**
     * Remembers what a source delivered; if it only has trouble right now, falls back on that.
     */
    private function settle(string $url, DateTimeImmutable $now): void
    {
        if ($this->lastGood === null) {
            return;
        }

        $parsed = $this->parsed[$url] ?? null;

        if ($parsed !== null && $parsed->isUsable() && $parsed->nodes !== []) {
            $this->lastGood->save($url, $parsed, $now);

            return;
        }

        $stored = $this->mayFallBack($url) ? $this->lastGood->load($url, $now) : null;

        if ($stored === null) {
            return;
        }

        $fetch = $this->fetches[$url] ?? null;
        $reason = match (true) {
            $fetch?->status === null => 'no answer (' . ($fetch->error ?? 'unreachable') . ')',
            $fetch->status === 200 => 'an empty body',
            default => 'HTTP ' . $fetch->status,
        };
        $this->parsed[$url] = $stored['result']->withWarnings([
            sprintf('source answered with %s; showing the nodes saved at %s', $reason, $stored['savedAt']->format('Y-m-d H:i')),
        ]);
        $this->stale[$url] = $stored['savedAt'];
    }

    /**
     * @param list<string> $urls
     */
    private function fetchInto(array $urls, DateTimeImmutable $now): void
    {
        $results = $this->fetcher->fetch($urls, function (string $url, string $body) use ($now): void {
            $this->parsed[$url] = $this->parser->parse($body, $now);
        });

        $this->requests += count($results);
        $this->fetches = [...$this->fetches, ...$results];
    }

    /**
     * Whether the last good nodes of a source may be shown instead of what it delivered now: the
     * server does not answer or answers with an error, or its body is empty. Wider than
     * {@see isTransient()}, which decides what is worth asking again at once (a host that timed out
     * usually does it again).
     */
    private function mayFallBack(string $url): bool
    {
        $fetch = $this->fetches[$url] ?? null;

        if ($fetch === null) {
            return false;
        }

        return self::hasTrouble($fetch) || ($fetch->status === 200 && ($this->parsed[$url] ?? null)?->format === Format::Empty);
    }

    /**
     * No answer at all (connection, DNS, TLS, timeout), request timeout, too many requests or a server error.
     */
    private static function hasTrouble(FetchResult $fetch): bool
    {
        return $fetch->status === null || $fetch->status === 408 || $fetch->status === 429 || $fetch->status >= 500;
    }

    private function isTransient(string $url): bool
    {
        $fetch = $this->fetches[$url] ?? null;

        if ($fetch === null || $fetch->status === null) {
            return false;
        }

        if ($fetch->status === 429 || $fetch->status >= 500) {
            return true;
        }

        return $fetch->status === 200 && $this->parsed[$url]->format === Format::Empty;
    }

    /**
     * Where a map stands: still has URLs to fetch, resolved to data URLs, or out of options.
     *
     * @return array{status: 'fetch'|'resolved'|'exhausted', urls: list<string>, via: string}
     */
    private function walk(SourceEntry $entry, DateTimeImmutable $now): array
    {
        foreach ($this->stages($entry, $now) as $stage) {
            $unfetched = array_values(array_filter($stage['urls'], fn (string $url): bool => !isset($this->fetches[$url])));

            if ($unfetched !== []) {
                return ['status' => 'fetch', 'urls' => $unfetched, 'via' => $stage['kind']];
            }

            $withNodes = array_values(array_filter($stage['urls'], $this->hasNodes(...)));
            $hits = match ($stage['kind']) {
                'candidates' => array_slice($withNodes, 0, 1),
                // meshviewer.json and nodes.json of one directory are the same data: the first is enough
                'datapath' => $this->firstPerDirectory($withNodes),
                default => $withNodes,
            };

            if ($hits !== []) {
                return ['status' => 'resolved', 'urls' => $hits, 'via' => $stage['kind']];
            }

            if ($stage['kind'] === 'cache') {
                // what we remembered does not deliver any more: look again from the start
                $this->cache->forget($entry->mapUrl);
            }

            if ($stage['kind'] === 'declared' && ($this->fetches[$entry->mapUrl] ?? null)?->status === null) {
                // the host does not answer at all: its other paths will not either
                break;
            }
        }

        return ['status' => 'exhausted', 'urls' => [], 'via' => ''];
    }

    /**
     * The URLs to look at for a map, stage by stage, in order of preference.
     *
     * @return list<array{kind: string, urls: list<string>}>
     */
    private function stages(SourceEntry $entry, DateTimeImmutable $now): array
    {
        $stages = [];
        $cached = $this->cache->get($entry->mapUrl, $now);

        if ($cached !== null) {
            $stages[] = ['kind' => 'cache', 'urls' => $cached];
        }

        $stages[] = ['kind' => 'declared', 'urls' => [$entry->mapUrl]];
        $candidates = Discovery::candidates($entry->mapUrl, $entry->declaredType);

        if ($candidates !== []) {
            $config = Discovery::baseUrl($entry->mapUrl) . 'config.json';
            $stages[] = ['kind' => 'config', 'urls' => [$config]];
            // the data paths come from config.json or, for pages that carry their configuration inline, from the page
            $paths = [];

            foreach ([$config, $entry->mapUrl] as $source) {
                if (isset($this->parsed[$source])) {
                    array_push($paths, ...$this->parsed[$source]->dataPaths);
                }
            }

            $stages[] = ['kind' => 'datapath', 'urls' => Discovery::dataPathUrls($entry->mapUrl, array_values(array_unique($paths)))];
            $stages[] = ['kind' => 'candidates', 'urls' => array_values(array_diff($candidates, [$config]))];
        }

        return $stages;
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function firstPerDirectory(array $urls): array
    {
        $first = [];

        foreach ($urls as $url) {
            $first[dirname($url)] ??= $url;
        }

        return array_values($first);
    }

    private function hasNodes(string $url): bool
    {
        $result = $this->parsed[$url] ?? null;

        return $result !== null && $result->isUsable() && $result->found > 0;
    }

    /**
     * @param list<CommunityBuild> $builds
     * @return list<CommunityReport>
     */
    private function assemble(array $builds, NodeCollection $nodes, DateTimeImmutable $now): array
    {
        $chosen = [];

        foreach ($builds as $index => $build) {
            $chosen[$index] = $this->choose($build, $now);
        }

        $order = [];

        foreach (['nodelist', 'others', 'fixed'] as $group) {
            foreach ($builds as $index => $build) {
                $isFixed = $build->origin === 'fixed';
                $isNodelist = $chosen[$index]['nodelist'];

                if (($group === 'fixed' && $isFixed) || ($group === 'nodelist' && !$isFixed && $isNodelist) || ($group === 'others' && !$isFixed && !$isNodelist)) {
                    $order[] = $index;
                }
            }
        }

        $plan = $this->plan($builds, $chosen, $order);
        $executed = $this->execute($plan['steps'], $nodes);
        $reports = [];

        foreach ($order as $index) {
            $key = $builds[$index]->info->key;
            $reports[] = $this->report($builds[$index], $chosen[$index], $executed[$key] ?? [], $plan['notes'][$key] ?? [], isset($plan['led'][$key]), $now);
        }

        return $reports;
    }

    /**
     * Decides which community every data URL belongs to, and in which order the sources are added.
     * By default a URL belongs to the community whose map led to it; assignment rules can say
     * otherwise, which is what a map server carrying the data of many communities needs.
     *
     * @param list<CommunityBuild> $builds
     * @param array<int, array{nodelist: bool, resolutions: list<array{entry: SourceEntry, urls: list<string>, via: string}>, unused: list<array{entry: SourceEntry, urls: list<string>, via: string}>}> $chosen
     * @param list<int> $order
     * @return array{steps: list<array{owner: string, url: string, entry: SourceEntry, via: string, backfill: bool, repeatOf: string|null}>, notes: array<string, list<string>>, led: array<string, true>}
     */
    private function plan(array $builds, array $chosen, array $order): array
    {
        $listed = [];

        foreach ($builds as $build) {
            if ($build->listed) {
                $listed[$build->info->key] = true;
            }
        }

        $steps = [];
        $notes = [];
        $led = [];
        /** @var array<string, array<string, int>> $away community that led to data => owner => number of URLs */
        $away = [];
        /** @var array<string, true> $assigned */
        $assigned = [];
        /** @var array<string, string> $firstUser */
        $firstUser = [];

        foreach ($order as $index) {
            $key = $builds[$index]->info->key;

            foreach ($chosen[$index]['resolutions'] as $resolution) {
                foreach ($resolution['urls'] as $url) {
                    if (!isset($this->parsed[$url])) {
                        continue;
                    }

                    $led[$key] = true;
                    $rule = $this->assignments->forUrl($url);

                    if ($rule !== null && !isset($listed[$rule->community])) {
                        $notes[$key][] = sprintf('%s is assigned to "%s", which is not a listed community: kept here', $url, $rule->community);
                        $rule = null;
                    }

                    if ($rule !== null) {
                        if ($rule->community !== $key) {
                            $away[$key][$rule->community] = ($away[$key][$rule->community] ?? 0) + 1;
                        }

                        $identity = SourceAssignments::identity($url);

                        // http and https of the same address, or the same map named by several communities: added once
                        if (!isset($assigned[$identity])) {
                            $assigned[$identity] = true;
                            $steps[] = ['owner' => $rule->community, 'url' => $url, 'entry' => $resolution['entry'], 'via' => $resolution['via'], 'backfill' => $rule->backfill, 'repeatOf' => null];
                        }

                        continue;
                    }

                    $steps[] = ['owner' => $key, 'url' => $url, 'entry' => $resolution['entry'], 'via' => $resolution['via'], 'backfill' => false, 'repeatOf' => $firstUser[$url] ?? null];
                    $firstUser[$url] ??= $key;
                }
            }
        }

        foreach ($away as $key => $owners) {
            foreach ($owners as $owner => $count) {
                $notes[$key][] = sprintf('%d data %s of this map %s assigned to community "%s"', $count, $count === 1 ? 'file' : 'files', $count === 1 ? 'is' : 'are', $owner);
            }
        }

        return ['steps' => $steps, 'notes' => $notes, 'led' => $led];
    }

    /**
     * Adds the sources to the collection: the specific ones in the order of the plan, then the
     * backfill sources, which only fill what is still missing.
     *
     * @param list<array{owner: string, url: string, entry: SourceEntry, via: string, backfill: bool, repeatOf: string|null}> $steps
     * @return array<string, list<array{owner: string, url: string, entry: SourceEntry, via: string, backfill: bool, repeatOf: string|null, result: ParseResult, added: int, duplicates: int}>>
     */
    private function execute(array $steps, NodeCollection $nodes): array
    {
        $ordered = [...array_filter($steps, static fn (array $s): bool => !$s['backfill']), ...array_filter($steps, static fn (array $s): bool => $s['backfill'])];
        $done = [];

        foreach ($ordered as $step) {
            $result = $this->parsed[$step['url']];
            $added = $nodes->addResult($step['owner'], $result);
            $done[$step['owner']][] = $step + ['result' => $result, 'added' => $added['added'], 'duplicates' => $added['duplicates']];
        }

        return $done;
    }

    /**
     * Which of a community's maps are used.
     *
     * @return array{nodelist: bool, resolutions: list<array{entry: SourceEntry, urls: list<string>, via: string}>, unused: list<array{entry: SourceEntry, urls: list<string>, via: string}>}
     */
    private function choose(CommunityBuild $build, DateTimeImmutable $now): array
    {
        $resolutions = [];

        foreach ($build->entries as $entry) {
            $step = $this->walk($entry, $now);

            if ($step['status'] === 'resolved') {
                $resolutions[] = ['entry' => $entry, 'urls' => $step['urls'], 'via' => $step['via']];

                if (in_array($step['via'], ['datapath', 'candidates'], true)) {
                    $this->cache->remember($entry->mapUrl, $step['urls'], $now);
                }
            }
        }

        $nodelists = array_values(array_filter($resolutions, fn (array $r): bool => $this->isNodelist($r['urls'])));

        if ($nodelists !== []) {
            return ['nodelist' => true, 'resolutions' => $nodelists, 'unused' => array_values(array_udiff($resolutions, $nodelists, static fn (array $a, array $b): int => $a['entry']->mapUrl <=> $b['entry']->mapUrl))];
        }

        return ['nodelist' => false, 'resolutions' => array_slice($resolutions, 0, 1), 'unused' => array_slice($resolutions, 1)];
    }

    /**
     * @param list<string> $urls
     */
    private function isNodelist(array $urls): bool
    {
        foreach ($urls as $url) {
            if (($this->parsed[$url] ?? null)?->format === Format::Nodelist) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{nodelist: bool, resolutions: list<array{entry: SourceEntry, urls: list<string>, via: string}>, unused: list<array{entry: SourceEntry, urls: list<string>, via: string}>} $chosen
     * @param list<array{owner: string, url: string, entry: SourceEntry, via: string, backfill: bool, repeatOf: string|null, result: ParseResult, added: int, duplicates: int}> $owned the sources whose nodes belong to this community
     * @param list<string> $notes what to tell about sources that were assigned elsewhere
     * @param bool $led whether a map of this community led to data at all (which may belong to others)
     */
    private function report(CommunityBuild $build, array $chosen, array $owned, array $notes, bool $led, DateTimeImmutable $now): CommunityReport
    {
        $messages = [...$build->messages, ...$notes];
        $key = $build->info->key;
        $sourceUrls = [];
        $warnings = [];
        $counts = ['found' => 0, 'added' => 0, 'duplicates' => 0, 'withoutLocation' => 0, 'dropped' => 0, 'invalid' => 0];
        $format = null;
        $viaCache = false;
        $staleSince = null;

        foreach ($owned as $step) {
            $result = $step['result'];
            $url = $step['url'];
            $sourceUrls[] = $url;
            $format ??= $result->format->value;
            $viaCache = $viaCache || $step['via'] === 'cache';
            $counts['found'] += $result->found;
            $counts['added'] += $step['added'];
            $counts['duplicates'] += $step['duplicates'];
            $counts['withoutLocation'] += $result->withoutLocation;
            $counts['dropped'] += $result->dropped;
            $counts['invalid'] += $result->invalid;

            if (isset($this->stale[$url])) {
                $staleSince = $this->stale[$url]->format('c');
            }

            $messages[] = sprintf('%s (%s%s%s): %s', $url, $step['entry']->declaredType, $step['via'] === 'cache' ? ', cached' : '', $step['backfill'] ? ', backfill' : '', $result->summary());

            if ($step['repeatOf'] !== null) {
                // the oddities of a shared source are reported where it was used first
                $messages[] = sprintf('%s was already used by "%s", its nodes belong there', $url, $step['repeatOf']);
            } else {
                array_push($warnings, ...$result->warnings);
            }
        }

        foreach ($chosen['unused'] as $unused) {
            $messages[] = sprintf('not used: %s (%s) - %s', implode(', ', $unused['urls']), $unused['entry']->declaredType, $chosen['nodelist'] ? 'the community has a nodelist, which takes priority' : 'another map of this community was used');
        }

        $apiStale = $this->staleApi[$key] ?? null;

        if ($apiStale !== null && ($staleSince === null || $apiStale < new DateTimeImmutable($staleSince))) {
            // the older of the two: that is how long this community has been shown from copies
            $staleSince = $apiStale->format('c');
        }

        $attempts = $this->attempts($build, $now);
        $delivered = $sourceUrls !== [] || $led;
        $outcome = $delivered ? CommunityReport::OK : $build->outcome;

        if (!$delivered && $build->entries !== []) {
            foreach ($build->entries as $entry) {
                $messages[] = sprintf('no data found for %s (%s)', $entry->mapUrl, $entry->declaredType);
            }
        }

        foreach ($warnings as $warning) {
            $messages[] = 'warning: ' . $warning;
        }

        return new CommunityReport(
            $key,
            $build->info->name,
            $build->info->url,
            $build->info->meta,
            $build->origin,
            $build->apiUrl,
            $build->listed,
            $outcome,
            $build->declaredTypes,
            $sourceUrls,
            $format,
            $viaCache,
            $counts['found'],
            $counts['added'],
            $counts['duplicates'],
            $counts['withoutLocation'],
            $counts['dropped'],
            $counts['invalid'],
            $attempts,
            $warnings,
            $messages,
            $build->claimedNodes,
            $staleSince,
        );
    }

    /**
     * @return list<SourceAttempt>
     */
    private function attempts(CommunityBuild $build, DateTimeImmutable $now): array
    {
        $attempts = [];

        foreach ($build->entries as $entry) {
            foreach ($this->stages($entry, $now) as $stage) {
                foreach ($stage['urls'] as $url) {
                    $fetch = $this->fetches[$url] ?? null;

                    if ($fetch === null || isset($attempts[$url])) {
                        continue;
                    }

                    $parsed = $this->parsed[$url] ?? null;
                    $attempts[$url] = new SourceAttempt($url, $fetch->status, $fetch->error, $parsed?->format->value, $parsed !== null ? $parsed->found : 0, $fetch->tlsInvalid);
                }
            }
        }

        return array_values($attempts);
    }

    private static function describe(?FetchResult $fetch): string
    {
        return match (true) {
            $fetch === null => 'not fetched',
            $fetch->status === null => $fetch->error ?? 'unreachable',
            $fetch->status !== 200 => 'HTTP ' . $fetch->status,
            default => 'not a JSON object',
        };
    }
}
