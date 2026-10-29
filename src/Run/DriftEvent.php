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
    public const COMMUNITY_ADDED = 'community-added';
    public const COMMUNITY_REMOVED = 'community-removed';
    public const MANY_COMMUNITIES_LOST = 'many-communities-lost';
    public const RUN_NODES_DROPPED = 'run-nodes-dropped';
    public const DELIVERY_FAILED = 'delivery-failed';
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
