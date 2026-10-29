<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin\Page;

use FreifunkKarte\Admin\Html;
use FreifunkKarte\Admin\Page;
use FreifunkKarte\Admin\PageContext;
use FreifunkKarte\Admin\PageResult;
use FreifunkKarte\Admin\Safe;
use FreifunkKarte\Admin\Svg;

/**
 * The communities of fixedCommunities.php: which ones deliver nodes of their own, which ones are
 * covered by the directory by now, and which ones have run dry.
 */
final class FixedPage implements Page
{
    private const TREND_RUNS = 20;

    public function render(PageContext $context): PageResult
    {
        $latest = $context->repository->latestOkRun();

        if ($latest === null) {
            return new PageResult('Fixed communities', '/fixed', new Safe('<p>No run has been recorded yet.</p>'));
        }

        $rows = $context->repository->communityRows((int) $latest['id'], origin: 'fixed');

        if ($rows === []) {
            return new PageResult('Fixed communities', '/fixed', new Safe('<p>The latest run had no fixed communities.</p>'));
        }

        $lastOk = $context->repository->lastOkRuns();
        $okRunIds = $context->repository->okRunIds();
        $series = $context->repository->nodeSeries(self::TREND_RUNS);
        $groups = ['dry' => [], 'redundant' => [], 'own' => []];

        foreach ($rows as $row) {
            $role = $row['outcome'] !== 'ok' ? 'dry' : ((int) $row['added'] === 0 ? 'redundant' : 'own');
            $groups[$role][] = [
                Widgets::communityLink((string) $row['key'], (string) $row['name']),
                Html::badge((string) $row['outcome']),
                (int) $row['added'],
                (int) $row['duplicates'],
                Widgets::dry($context, (string) $row['key'], $lastOk, $okRunIds),
                Svg::sparkline($series[(string) $row['key']] ?? []),
            ];
        }

        $headers = ['community', 'outcome', 'kept', 'also from the directory', 'without nodes for', sprintf('last %d runs', self::TREND_RUNS)];
        $body = sprintf(
            '<p class="muted">From run %s, %s: %d fixed communities - %d deliver nodes of their own, %d only repeat what the directory delivers, %d deliver nothing.</p>',
            Widgets::runLink($latest['id'])->html,
            Html::e($context->time($latest['started_at'])),
            count($rows),
            count($groups['own']),
            count($groups['redundant']),
            count($groups['dry']),
        );

        $sections = [
            'dry' => ['Deliver nothing', 'Candidates for removal if they stay dry. "Never" means no run in the recorded history ever got nodes from them.'],
            'redundant' => ['Covered by the directory today', 'All their nodes are also delivered by a community from the directory. Safe to drop while that stays true; they matter again if the directory entry disappears.'],
            'own' => ['Deliver nodes of their own', 'Dropping these removes nodes from the map.'],
        ];

        foreach ($sections as $role => [$title, $explanation]) {
            $body .= sprintf('<h2>%s (%d)</h2><p class="muted">%s</p>', Html::e($title), count($groups[$role]), Html::e($explanation))
                . Html::table($headers, $groups[$role], 'None.')->html;
        }

        return new PageResult('Fixed communities', '/fixed', new Safe($body));
    }
}
