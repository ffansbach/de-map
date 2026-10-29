<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin\Page;

use FreifunkKarte\Admin\Html;
use FreifunkKarte\Admin\Page;
use FreifunkKarte\Admin\PageContext;
use FreifunkKarte\Admin\PageResult;
use FreifunkKarte\Admin\Safe;
use FreifunkKarte\Admin\Svg;
use FreifunkKarte\Run\RunRepository;

final class DashboardPage implements Page
{
    private const CHART_RUNS = 60;
    private const RECENT_RUNS = 8;

    public function render(PageContext $context): PageResult
    {
        $repository = $context->repository;
        $latest = $repository->latestRun();

        if ($latest === null) {
            return new PageResult('Overview', '/', new Safe('<p>No run has been recorded yet. The history is created by <code>bin/parser run</code>.</p>'));
        }

        $body = '';

        if ($latest['status'] !== RunRepository::OK) {
            $body .= $this->problem($context, $latest);
        }

        $ok = $repository->latestOkRun();

        if ($ok !== null) {
            $body .= sprintf('<h2>Latest successful run %s from %s</h2>', Widgets::runLink($ok['id'])->html, Html::e($context->time($ok['started_at'])));
            $body .= Widgets::runCards($ok)->html;
            $body .= $this->charts($context)->html;
            $body .= $this->events($context, (int) $ok['id'])->html;
            $body .= $this->attention($context, (int) $ok['id'])->html;
        }

        $body .= '<h2>Recent runs</h2>' . $this->recent($context)->html;

        return new PageResult('Overview', '/', new Safe($body));
    }

    /**
     * @param array<string, mixed> $run
     */
    private function problem(PageContext $context, array $run): string
    {
        $text = match ($run['status']) {
            RunRepository::FAILED => 'failed: ' . (string) ($run['error'] ?? 'no reason recorded'),
            RunRepository::REFUSED => 'was refused: the result looked implausibly small, so no result files were written',
            default => 'is still running, or was interrupted',
        };

        return sprintf(
            '<p class="card"><strong>The latest run %s (%s) %s</strong></p>',
            Widgets::runLink($run['id'])->html,
            Html::e($context->time($run['started_at'])),
            Html::e($text),
        );
    }

    private function charts(PageContext $context): Safe
    {
        $runs = array_reverse(array_values(array_filter(
            $context->repository->runsPage(self::CHART_RUNS, 0),
            static fn (array $run): bool => $run['status'] === RunRepository::OK,
        )));

        return new Safe(
            Svg::chart(array_map(static fn (array $r): int => (int) $r['nodes'], $runs), 'Nodes on the map')->html
            . Svg::chart(array_map(static fn (array $r): int => (int) $r['with_nodes'], $runs), 'Communities with nodes')->html
            . Svg::chart(array_map(static fn (array $r): float => (float) $r['seconds'], $runs), 'Duration of a run (seconds)')->html,
        );
    }

    private function events(PageContext $context, int $runId): Safe
    {
        $events = array_values(array_filter(
            $context->repository->events($runId),
            static fn (array $event): bool => $event['severity'] !== 'info',
        ));

        return new Safe('<h2>To look at in this run</h2>' . Widgets::eventsTable($context, $events, empty: 'Nothing odd compared with the run before.')->html);
    }

    private function attention(PageContext $context, int $runId): Safe
    {
        $repository = $context->repository;
        $noData = count($repository->communityRows($runId, 'no-data'));
        $stale = count(array_filter($repository->communityRows($runId), static fn (array $row): bool => $row['stale_since'] !== null));
        $dryFixed = count($repository->communityRows($runId, 'no-data', 'fixed'));

        return new Safe(
            '<h2>Keep an eye on</h2><ul>'
            . sprintf('<li>%s: %d</li>', Html::link('/no-data', 'maps without data')->html, $noData)
            . sprintf('<li>%s without data: %d</li>', Html::link('/fixed', 'fixed communities')->html, $dryFixed)
            . sprintf('<li>communities showing the last good data because the source has trouble: %d</li>', $stale)
            . '</ul>',
        );
    }

    private function recent(PageContext $context): Safe
    {
        $runs = $context->repository->runsPage(self::RECENT_RUNS, 0);
        $counts = $context->repository->eventCounts(array_map(static fn (array $r): int => (int) $r['id'], $runs));
        $rows = [];

        foreach ($runs as $run) {
            $rows[] = [
                Widgets::runLink($run['id']),
                $context->time($run['started_at']),
                Html::badge((string) $run['status'], 'run-' . $run['status']),
                Html::number($run['nodes'] ?? null),
                Html::number($run['without_data'] ?? null),
                Html::duration($run['seconds'] ?? null),
                Widgets::eventCounts($counts[(int) $run['id']] ?? []),
            ];
        }

        return new Safe(Html::table(['run', 'started', 'status', 'nodes', 'no data', 'duration', 'events'], $rows)->html . '<p>' . Html::link('/runs', 'all runs')->html . '</p>');
    }
}
