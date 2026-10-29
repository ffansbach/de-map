<?php

declare(strict_types=1);

namespace FreifunkKarte\Audit;

final readonly class DetectionResult
{
    /**
     * @param int $nodes number of entries in "nodes"
     * @param int $located number of nodes with usable coordinates
     * @param list<string> $dataPaths only set for {@see Format::FfmapConfig}
     */
    public function __construct(
        public Format $format,
        public int $nodes = 0,
        public int $located = 0,
        public array $dataPaths = [],
    ) {
    }

    public function hasNodes(): bool
    {
        return $this->format->isNodeSource() && $this->nodes > 0;
    }
}
