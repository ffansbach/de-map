<?php

declare(strict_types=1);

namespace FreifunkKarte\Run\Listener;

use DateTimeImmutable;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\RunListener;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Run\RunSummary;

/**
 * Writes the numbers of a finished run to InfluxDB. The measurement and the field names are the
 * ones the dashboards have always read (parser_result_count: routers, communities, parse_time, mem_usage,
 * curl_calls, env), so existing dashboards keep working; a few fields are new.
 */
final class InfluxListener implements RunListener
{
    public const MEASUREMENT = 'parser_result_count';

    public function __construct(private readonly InfluxPointWriter $writer, private readonly string $environment = 'undefined')
    {
    }

    public function started(int $runId, DateTimeImmutable $at): void
    {
    }

    public function finished(RunSummary $summary): void
    {
        $result = $summary->result;

        if ($result === null || $summary->status !== RunRepository::OK) {
            return;
        }

        $outcomes = array_count_values(array_map(static fn (CommunityReport $c): string => $c->outcome, $result->communities));

        $this->writer->write(self::MEASUREMENT, [], [
            'routers' => $result->nodes->count(),
            'communities' => count($result->listedCommunities()),
            'parse_time' => (int) $result->seconds,
            'parse_time_float' => $result->seconds,
            'mem_usage' => $summary->peakMemoryBytes,
            'curl_calls' => $result->requests,
            'env' => $this->environment,
            'communities_with_nodes' => $outcomes[CommunityReport::OK] ?? 0,
            'communities_no_data' => $outcomes[CommunityReport::NO_DATA] ?? 0,
            'events_warning' => count(array_filter($summary->events, static fn (DriftEvent $e): bool => $e->severity === DriftEvent::WARNING)),
            'events_alert' => count(array_filter($summary->events, static fn (DriftEvent $e): bool => $e->severity === DriftEvent::ALERT)),
        ]);
    }
}
