<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin\Page;

use FreifunkKarte\Admin\Html;
use FreifunkKarte\Admin\Page;
use FreifunkKarte\Admin\PageContext;
use FreifunkKarte\Admin\PageResult;
use FreifunkKarte\Admin\Safe;
use FreifunkKarte\Admin\Svg;

final class CommunitiesPage implements Page
{
    private const OUTCOMES = ['ok', 'no-data', 'api-unavailable', 'no-nodemaps', 'skipped'];
    private const ORIGINS = ['directory', 'fixed'];
    private const TREND_RUNS = 20;

    public function render(PageContext $context): PageResult
    {
        $latest = $context->repository->latestOkRun();

        if ($latest === null) {
            return new PageResult('Communities', '/communities', new Safe('<p>No run has been recorded yet.</p>'));
        }

        $outcome = $this->choice($context->text('outcome'), self::OUTCOMES);
        $origin = $this->choice($context->text('origin'), self::ORIGINS);
        $search = mb_strtolower($context->text('q') ?? '');
        $byNodes = $context->text('sort') === 'nodes';

        $rows = array_values(array_filter(
            $context->repository->communityRows((int) $latest['id'], $outcome, $origin),
            static fn (array $row): bool => $search === '' || str_contains(mb_strtolower($row['key'] . ' ' . $row['name']), $search),
        ));

        if ($byNodes) {
            usort($rows, static fn (array $a, array $b): int => ($b['added'] + $b['duplicates']) <=> ($a['added'] + $a['duplicates']));
        }

        $series = $context->repository->nodeSeries(self::TREND_RUNS);
        $table = [];

        foreach ($rows as $row) {
            $table[] = [
                Widgets::communityLink((string) $row['key'], (string) $row['name']),
                (string) $row['origin'],
                Html::badge((string) $row['outcome']),
                (string) ($row['format'] ?? ''),
                implode(', ', $row['declared_types']),
                (int) $row['added'],
                (int) $row['duplicates'],
                Svg::sparkline($series[(string) $row['key']] ?? []),
                $row['stale_since'] !== null ? Html::badge('last good data', 'stale') : null,
            ];
        }

        $body = $this->filter($context, $outcome, $origin, $search, $byNodes)
            . sprintf('<p class="muted">%d communities in run %s from %s</p>', count($rows), Widgets::runLink($latest['id'])->html, Html::e($context->time($latest['started_at'])))
            . Html::table(['community', 'origin', 'outcome', 'format', 'declared types', 'kept', 'also elsewhere', sprintf('last %d runs', self::TREND_RUNS), ''], $table, 'No community matches.')->html;

        return new PageResult('Communities', '/communities', new Safe($body));
    }

    /**
     * @param list<string> $allowed
     */
    private function choice(?string $value, array $allowed): ?string
    {
        return $value !== null && in_array($value, $allowed, true) ? $value : null;
    }

    private function filter(PageContext $context, ?string $outcome, ?string $origin, string $search, bool $byNodes): string
    {
        $select = static function (string $name, string $label, array $options, ?string $current): string {
            $html = sprintf('<label>%s<select name="%s"><option value="">all</option>', Html::e($label), Html::e($name));

            foreach ($options as $option) {
                $html .= sprintf('<option value="%1$s"%2$s>%1$s</option>', Html::e($option), $option === $current ? ' selected' : '');
            }

            return $html . '</select></label>';
        };

        return '<form class="filter" method="get" action="/communities">'
            . $select('outcome', 'outcome', self::OUTCOMES, $outcome)
            . $select('origin', 'origin', self::ORIGINS, $origin)
            . $select('sort', 'sort by', ['nodes'], $byNodes ? 'nodes' : null)
            . sprintf('<label>search<input type="search" name="q" value="%s"></label>', Html::e($search))
            . '<button type="submit">Filter</button></form>';
    }
}
