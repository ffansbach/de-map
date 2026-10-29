<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline;

/**
 * What happened to one community in a run.
 */
final readonly class CommunityReport
{
    /** at least one map delivered a node list */
    public const OK = 'ok';
    /** maps exist, but none delivered a node list - see the attempts */
    public const NO_DATA = 'no-data';
    public const SKIPPED = 'skipped';
    public const API_UNAVAILABLE = 'api-unavailable';
    public const NO_NODEMAPS = 'no-nodemaps';

    /**
     * @param string $origin 'directory' or 'fixed'
     * @param bool $listed whether the community is part of result_communities.json
     * @param list<string> $declaredTypes technicalTypes of its maps, as declared (drift detection)
     * @param list<string> $sourceUrls the data URLs the nodes came from
     * @param list<SourceAttempt> $attempts every URL tried for its maps
     * @param list<string> $warnings oddities of the sources used
     * @param list<string> $messages human readable account of the run for this community
     * @param string|null $staleSince set if the nodes are from an earlier run because the source is not answering properly
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $url,
        public ?string $meta,
        public string $origin,
        public ?string $apiUrl,
        public bool $listed,
        public string $outcome,
        public array $declaredTypes = [],
        public array $sourceUrls = [],
        public ?string $format = null,
        public bool $viaCache = false,
        public int $found = 0,
        public int $added = 0,
        public int $duplicates = 0,
        public int $withoutLocation = 0,
        public int $dropped = 0,
        public int $invalid = 0,
        public array $attempts = [],
        public array $warnings = [],
        public array $messages = [],
        public ?int $claimedNodes = null,
        public ?string $staleSince = null,
    ) {
    }
}
