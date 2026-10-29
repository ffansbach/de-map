<?php

declare(strict_types=1);

namespace FreifunkKarte\Community;

/**
 * The outcome for one meta-community name. A link exists if $url is set; $source says why.
 */
final readonly class MetaLink
{
    public const OVERRIDE = 'override';
    public const MEMBER = 'member';
    public const SHARED_HOST = 'shared-host';
    /** one member only: the meta-community is the community itself, a second link adds nothing */
    public const SINGLE = 'single-member';
    public const SUPPRESSED = 'suppressed';
    public const NONE = 'none';

    public function __construct(
        public string $name,
        public ?string $url,
        public string $source,
        public int $members,
    ) {
    }
}
