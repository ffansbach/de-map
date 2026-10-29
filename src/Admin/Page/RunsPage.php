<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin\Page;

use FreifunkKarte\Admin\Html;
use FreifunkKarte\Admin\Page;
use FreifunkKarte\Admin\PageContext;
use FreifunkKarte\Admin\PageResult;
use FreifunkKarte\Admin\Safe;

final class RunsPage implements Page
{
    private const PER_PAGE = 50;

    public function render(PageContext $context): PageResult
    {
        $page = $context->int('page', 1);
        $repository = $context->repository;
        $runs = $repository->runsPage(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $counts = $repository->eventCounts(array_map(static fn (array $r): int => (int) $r['id'], $runs));
        $rows = [];

        foreach ($runs as $run) {
            $rows[] = [
                Widgets::runLink($run['id']),
                $context->time($run['started_at']),
                Html::badge((string) $run['status'], 'run-' . $run['status']),
                Html::number($run['nodes'] ?? null),
                Html::number($run['communities'] ?? null),
                Html::number($run['with_nodes'] ?? null),
                Html::number($run['without_data'] ?? null),
                Html::number($run['requests'] ?? null),
                Html::duration($run['seconds'] ?? null),
                Widgets::eventCounts($counts[(int) $run['id']] ?? []),
            ];
        }

        $body = Html::table(['run', 'started', 'status', 'nodes', 'communities', 'with nodes', 'no data', 'requests', 'duration', 'events'], $rows, 'No run has been recorded yet.')->html
            . Widgets::pager('/runs', $page, $repository->runCount() > $page * self::PER_PAGE)->html;

        return new PageResult('Runs', '/runs', new Safe($body));
    }
}
