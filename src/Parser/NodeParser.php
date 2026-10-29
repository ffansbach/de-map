<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

use DateTimeImmutable;
use FreifunkKarte\Audit\Format;

interface NodeParser
{
    public function format(): Format;

    /**
     * @param array<array-key, mixed> $data the decoded source (with a "nodes" entry)
     * @param DateTimeImmutable $now reference time for the offline rule
     */
    public function parse(array $data, DateTimeImmutable $now): ParseResult;
}
