<?php

declare(strict_types=1);

namespace FreifunkKarte\Run;

/**
 * A run that has been started in the history and still has to be completed or failed.
 */
final readonly class RecordedRun
{
    public function __construct(
        public RunRecorder $recorder,
        public RunRepository $repository,
        public int $id,
    ) {
    }
}
