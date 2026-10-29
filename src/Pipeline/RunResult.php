<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline;

use DateTimeImmutable;
use FreifunkKarte\Community\MetaLink;
use FreifunkKarte\Model\NodeCollection;

/**
 * Everything a run produced; the writers turn it into files.
 */
final readonly class RunResult
{
    /**
     * @param list<CommunityReport> $communities all communities seen, in output order
     * @param array<string, MetaLink> $metaLinks by meta-community name
     * @param list<FetchRecord> $fetches every URL requested
     */
    public function __construct(
        public NodeCollection $nodes,
        public array $communities,
        public array $metaLinks,
        public DateTimeImmutable $startedAt,
        public float $seconds,
        public int $requests,
        public array $fetches = [],
    ) {
    }

    /**
     * @return list<CommunityReport> the ones that go to result_communities.json
     */
    public function listedCommunities(): array
    {
        return array_values(array_filter($this->communities, static fn (CommunityReport $c): bool => $c->listed));
    }
}
