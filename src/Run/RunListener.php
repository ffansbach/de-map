<?php

declare(strict_types=1);

namespace FreifunkKarte\Run;

use DateTimeImmutable;

/**
 * Something that wants to know when a run starts and how it ended: healthchecks.io, InfluxDB.
 * A listener that fails never breaks the run (see RunRecorder).
 */
interface RunListener
{
    public function started(int $runId, DateTimeImmutable $at): void;

    public function finished(RunSummary $summary): void;
}
