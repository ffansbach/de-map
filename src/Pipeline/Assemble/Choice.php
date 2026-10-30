<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Assemble;

/**
 * Which of a community's maps are used, and which were found but left alone.
 */
final readonly class Choice
{
    /**
     * @param bool $nodelist the community has a nodelist that delivers, which takes priority
     * @param list<Resolution> $resolutions the maps whose data is used
     * @param list<Resolution> $unused maps that led to data but are not used
     */
    public function __construct(
        public bool $nodelist,
        public array $resolutions,
        public array $unused,
    ) {
    }
}
