<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Run;

use FreifunkKarte\Community\MetaCommunityResolver;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Pipeline\ParseRun;
use FreifunkKarte\Pipeline\RunResult;
use FreifunkKarte\Source\SourceCache;
use FreifunkKarte\Tests\Legacy\LegacyRunner;
use FreifunkKarte\Tests\Legacy\StandardWorld;
use FreifunkKarte\Tests\Legacy\World;
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
            LegacyRunner::DIRECTORY_URL,
            retryDelayMs: 0,
        ))->run($world->fixedCommunities(), StandardWorld::SKIP);
    }
}
