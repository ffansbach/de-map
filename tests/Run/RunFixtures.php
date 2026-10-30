<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Run;

use FreifunkKarte\Community\MetaCommunityResolver;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Pipeline\ParseRun;
use FreifunkKarte\Pipeline\RunResult;
use FreifunkKarte\Source\SourceCache;
use FreifunkKarte\Tests\Support\StandardWorld;
use FreifunkKarte\Tests\Support\World;
use GuzzleHttp\Client;

/**
 * Real run results for tests of everything that works on them.
 */
final class RunFixtures
{
    /**
     * A run over the reference world (16 communities, 40 nodes).
     */
    public static function standard(?World $world = null): RunResult
    {
        $world ??= StandardWorld::build();

        return (new ParseRun(
            new ParallelFetcher(new Client(['handler' => $world->httpHandler()]), 8, retryWithoutVerification: false),
            new SourceParser(),
            new SourceCache(),
            new MetaCommunityResolver(),
            null,
            World::DIRECTORY_URL,
            retryDelayMs: 0,
        ))->run($world->fixedCommunities(), StandardWorld::SKIP);
    }

    /**
     * The reference world after a bad day: hop-town's data is gone and a community uses a new type.
     */
    public static function worse(): RunResult
    {
        return self::standard(StandardWorld::build()
            ->route('https://hop.test/a/nodes.json', 'not json')
            ->route('https://hop.test/b/nodes.json', 'not json')
            ->community('newcomer', ['nodeMaps' => [['technicalType' => 'yanic', 'url' => 'https://newcomer.test/map/']]]));
    }
}
