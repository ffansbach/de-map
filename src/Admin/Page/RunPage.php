<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin\Page;

use FreifunkKarte\Admin\Html;
use FreifunkKarte\Admin\Page;
use FreifunkKarte\Admin\PageContext;
use FreifunkKarte\Admin\PageResult;
use FreifunkKarte\Admin\Safe;
use FreifunkKarte\Run\CommunityChange;
use FreifunkKarte\Run\NoDataReason;
use FreifunkKarte\Run\RunDiff;
use FreifunkKarte\Run\RunRepository;

final class RunPage implements Page
{
    private const SLOWEST = 15;
    private const PROBLEMS = 50;

    public function render(PageContext $context): PageResult
    {
        $repository = $context->repository;
        $runId = (int) ($context->params['id'] ?? 0);
        $run = $repository->run($runId);

        if ($run === null) {
            return PageResult::notFound('There is no run #' . $runId);
        }

        $body = $this->summary($context, $run)->html;

        if ($run['status'] === RunRepository::FAILED || $run['status'] === RunRepository::RUNNING) {
            $body .= '<h2>Events</h2>' . Widgets::eventsTable($context, $repository->events($runId))->html;

            return new PageResult('Run #' . $runId, '/runs', new Safe($body));
        }

        $body .= '<h2>Events</h2>' . Widgets::eventsTable($context, $repository->events($runId), empty: 'No events: nothing odd compared with the run before.')->html;
        $body .= $this->changes($context, $runId)->html;
        $body .= $this->noData($context, $runId)->html;
        $body .= $this->stale($context, $runId)->html;
        $body .= $this->fetches($context, $runId)->html;

        return new PageResult('Run #' . $runId, '/runs', new Safe($body));
    }

    /**
     * @param array<string, mixed> $run
     */
    private function summary(PageContext $context, array $run): Safe
    {
        $id = (int) $run['id'];
        $neighbours = [];

        if ($id > 1 && $context->repository->run($id - 1) !== null) {
            $neighbours[] = Html::link('/runs/' . ($id - 1), '← run #' . ($id - 1))->html;
        }

        if ($context->repository->run($id + 1) !== null) {
            $neighbours[] = Html::link('/runs/' . ($id + 1), 'run #' . ($id + 1) . ' →')->html;
        }

        return new Safe(
            ($neighbours !== [] ? '<div class="pager">' . implode(' ', $neighbours) . '</div>' : '')
            . Widgets::runCards($run)->html
            . Html::definitions([
                'status' => Html::badge((string) $run['status'], 'run-' . $run['status']),
                'started' => $context->time($run['started_at']),
                'finished' => $context->time($run['finished_at'] ?? null),
                'result files in' => (string) ($run['out_dir'] ?? ''),
                'error' => $run['error'] ?? null,
            ])->html,
        );
    }

    private function changes(PageContext $context, int $runId): Safe
    {
        $previous = $context->repository->previousRun($runId);

        if ($previous === null) {
            return new Safe('');
        }

        $changes = RunDiff::between($context->repository->communities($previous), $context->repository->communities($runId));
        $rows = array_map(static fn (CommunityChange $c): array => [
            Widgets::communityLink($c->key, $c->name),
            $c->kind,
            $c->before,
            $c->after,
        ], $changes);

        return new Safe(sprintf('<h2>Changes since run %s</h2>', Widgets::runLink($previous)->html) . Html::table(['community', 'what', 'before', 'after'], $rows, 'Nothing changed in a way that matters.')->html);
    }

    private function noData(PageContext $context, int $runId): Safe
    {
        $rows = [];

        foreach ($context->repository->communityRows($runId, 'no-data') as $row) {
            $detail = $context->repository->communityDetail($runId, (string) $row['key']);
            $reason = NoDataReason::classify($detail['attempts'] ?? []);
            $rows[] = [Widgets::communityLink((string) $row['key'], (string) $row['name']), implode(', ', $row['declared_types']), $reason->label()];
        }

        return new Safe(sprintf('<h2>Maps without data (%d)</h2>', count($rows)) . Html::table(['community', 'declared types', 'why'], $rows, 'Every map delivered data.')->html . '<p>' . Html::link('/no-data', 'more about them')->html . '</p>');
    }

    private function stale(PageContext $context, int $runId): Safe
    {
        $rows = [];

        foreach ($context->repository->communityRows($runId) as $row) {
            if ($row['stale_since'] !== null) {
                $rows[] = [Widgets::communityLink((string) $row['key'], (string) $row['name']), $context->time($row['stale_since'])];
            }
        }

        return $rows === [] ? new Safe('') : new Safe('<h2>Showing the last good data</h2><p class="muted">The source answered with an error or nothing, the nodes are from an earlier run.</p>' . Html::table(['community', 'nodes saved at'], $rows)->html);
    }

    private function fetches(PageContext $context, int $runId): Safe
    {
        $row = static fn (array $f): array => [
            (string) $f['url'],
            $f['status'] ?? (string) ($f['error'] ?? 'no answer'),
            sprintf('%.2f s', (float) $f['seconds']),
            (string) ($f['format'] ?? ''),
            (int) $f['tls_invalid'] === 1 ? Html::badge('certificate', 'warning') : null,
        ];
        $headers = ['url', 'status', 'time', 'format', ''];
        $problems = $context->repository->fetchesFor($runId, true, self::PROBLEMS);

        return new Safe(
            sprintf('<h2>Requests without a good answer (%d shown, 404s are normal while probing)</h2>', count($problems))
            . Html::table($headers, array_map($row, $problems), 'Every request was answered.')->html
            . sprintf('<h2>Slowest requests</h2>')
            . Html::table($headers, array_map($row, $context->repository->fetchesFor($runId, false, self::SLOWEST)))->html,
        );
    }
}
