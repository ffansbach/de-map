<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline;

use Closure;
use DateTimeImmutable;
use FreifunkKarte\Community\CommunityInfo;
use FreifunkKarte\Community\MetaCommunityResolver;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Model\NodeCollection;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Pipeline\Assemble\AssignmentPlanner;
use FreifunkKarte\Pipeline\Assemble\CommunityAssembler;
use FreifunkKarte\Pipeline\Assemble\MapChooser;
use FreifunkKarte\Pipeline\Assemble\ReportBuilder;
use FreifunkKarte\Pipeline\Resolve\MapWalker;
use FreifunkKarte\Pipeline\Resolve\SourceProbe;
use FreifunkKarte\Pipeline\Resolve\SourceResolver;
use FreifunkKarte\Source\ApiDocumentStore;
use FreifunkKarte\Source\Directory;
use FreifunkKarte\Source\LastGoodStore;
use FreifunkKarte\Source\SourceAssignments;
use FreifunkKarte\Source\SourceCache;

/**
 * One complete run: directory, API files, node sources, parsing, deduplication.
 *
 * Everything that waits for the network is done for all communities at once, in rounds:
 *   1. the directory, 2. all API files ({@see CommunityBuilder}), 3. the maps - for each map one
 *   stage per round: cached data URLs, the declared URL, its config.json, the dataPath URLs from
 *   that, the other usual locations ({@see SourceResolver}). A map stops at the first stage that
 *   yields nodes, but all maps advance together. Then 4. the nodes are added to the collection
 *   and every community is reported on ({@see CommunityAssembler}).
 *
 * Priorities: a community with a nodelist uses only that; otherwise the first map that yields
 * nodes; nodelist communities come first, fixed communities last; the first community to deliver
 * a node keeps it.
 */
final class ParseRun
{
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
        $started = microtime(true);
        $now = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable();
        $state = new RunState();
        $walker = new MapWalker($state, $this->cache);
        $communities = new CommunityBuilder($this->fetcher, $state, $this->directoryUrl, $this->apiDocuments);

        $log('fetching community directory');
        $directory = $communities->directory($only);

        $log(sprintf('fetching %d community API files', count($directory)));
        $builds = $communities->fromDirectory($directory, $skip, $now);

        foreach ($fixed as $key => $source) {
            if ($only === null || $only === $key) {
                $builds[] = CommunityBuild::fixed($key, $source);
            }
        }

        $entries = [];

        foreach ($builds as $build) {
            array_push($entries, ...$build->entries);
        }

        $probe = new SourceProbe($this->fetcher, $this->parser, $state, $this->lastGood, $this->retryDelayMs);
        (new SourceResolver($walker, $probe, $state))->resolve($entries, $now, $log);

        $assembler = new CommunityAssembler(
            $state,
            new MapChooser($walker, $state, $this->cache),
            new AssignmentPlanner($state, $this->assignments),
            new ReportBuilder($state, $walker),
        );
        $reports = $assembler->assemble($builds, $nodes = new NodeCollection(), $now);

        $this->cache->save($now);
        $this->lastGood?->prune($now);
        $this->apiDocuments?->prune($now);

        $metaLinks = $this->metaResolver->resolve(array_map(
            static fn (CommunityReport $r): CommunityInfo => new CommunityInfo($r->key, $r->name, $r->url, $r->meta),
            array_values(array_filter($reports, static fn (CommunityReport $r): bool => $r->listed)),
        ));

        return new RunResult($nodes, $reports, $metaLinks, $now, microtime(true) - $started, $state->requests(), $state->fetchRecords());
    }
}
