<?php

declare(strict_types=1);

namespace FreifunkKarte\Run\Listener;

use DateTimeImmutable;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\RunListener;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Run\RunSummary;
use GuzzleHttp\ClientInterface;

/**
 * Pings healthchecks.io: /start when a run begins, then a success ping - or /fail if the run
 * failed, was refused, or has events of at least the severity set in $failOn. The default is
 * "alert": the check only fails when something is wrong widely (a fifth of the nodes gone, many
 * communities lost, no directory) or the result could not be delivered to the web server. Warnings
 * (one community lost, a new technical type, a changed format) do not fail the check; they are in
 * the body of the ping, which healthchecks.io keeps as the log of the check.
 *
 * The body carries the account of the run, so that the reason is in the notification you get.
 */
final class HealthchecksListener implements RunListener
{
    private const MAX_BODY = 10000;

    /**
     * @param string $url the ping URL of the check, e.g. https://hc-ping.com/<uuid>
     * @param string $failOn the lowest severity that makes the ping a failure
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $url,
        private readonly string $failOn = DriftEvent::ALERT,
    ) {
    }

    public function started(int $runId, DateTimeImmutable $at): void
    {
        $this->ping('/start', '');
    }

    public function finished(RunSummary $summary): void
    {
        $failed = $summary->status !== RunRepository::OK || $summary->eventsFrom($this->failOn) !== [];

        $this->ping($failed ? '/fail' : '', $this->body($summary));
    }

    private function ping(string $suffix, string $body): void
    {
        $this->client->request('POST', rtrim($this->url, '/') . $suffix, [
            'body' => $body,
            'timeout' => 10,
            'connect_timeout' => 5,
            'http_errors' => false,
            'headers' => ['User-Agent' => 'freifunk-karte parser'],
        ]);
    }

    private function body(RunSummary $summary): string
    {
        $result = $summary->result;
        $lines = [$result !== null
            ? sprintf('run %d: %s, %d nodes, %d communities, %.1f s, %d requests', $summary->runId, $summary->status, $result->nodes->count(), count($result->listedCommunities()), $result->seconds, $result->requests)
            : sprintf('run %d: %s - %s', $summary->runId, $summary->status, $summary->error ?? ''),
        ];

        foreach ([DriftEvent::ALERT, DriftEvent::WARNING] as $severity) {
            foreach ($summary->events as $event) {
                if ($event->severity === $severity) {
                    $lines[] = sprintf('%s: %s', $severity, $event->message);
                }
            }
        }

        $info = count($summary->events) - count($summary->eventsFrom(DriftEvent::WARNING));

        if ($info > 0) {
            $lines[] = sprintf('(and %d notes of lower importance, see the log)', $info);
        }

        return mb_substr(implode("\n", $lines), 0, self::MAX_BODY);
    }
}
