<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline;

use FreifunkKarte\Community\CommunityInfo;

/**
 * A community on its way through a run: what we know before any map is fetched.
 *
 * @internal
 */
final class CommunityBuild
{
    /**
     * @param list<SourceEntry> $entries maps to look at, in the order the community declared them
     * @param list<string> $messages
     * @param list<string> $declaredTypes
     */
    public function __construct(
        public readonly CommunityInfo $info,
        public readonly string $origin,
        public readonly ?string $apiUrl,
        public readonly bool $listed,
        public readonly string $outcome,
        public readonly array $entries = [],
        public array $messages = [],
        public readonly array $declaredTypes = [],
        public readonly ?int $claimedNodes = null,
    ) {
    }
}
