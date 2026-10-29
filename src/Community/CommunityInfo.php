<?php

declare(strict_types=1);

namespace FreifunkKarte\Community;

/**
 * What a community says about itself in its API file.
 */
final readonly class CommunityInfo
{
    public function __construct(
        public string $key,
        public string $name,
        public string $url,
        public ?string $meta = null,
    ) {
    }
}
