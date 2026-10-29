<?php

declare(strict_types=1);

namespace FreifunkKarte\Run;

/**
 * Something about a run that a person should hear about.
 */
final readonly class DriftEvent
{
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ALERT = 'alert';

    public const NEW_TYPE = 'new-technical-type';
    public const FORMAT_CHANGED = 'format-changed';
    public const NODES_DROPPED = 'nodes-dropped';
    public const COMMUNITY_LOST = 'community-lost';
    public const COMMUNITY_RECOVERED = 'community-recovered';
    public const STALE_DATA = 'stale-data';
    public const NEW_WARNING = 'new-source-warning';
    public const RUN_NODES_DROPPED = 'run-nodes-dropped';
    public const RUN_FAILED = 'run-failed';
    public const RUN_REFUSED = 'run-refused';

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $kind,
        public string $severity,
        public string $subject,
        public string $message,
        public array $details = [],
    ) {
    }
}
