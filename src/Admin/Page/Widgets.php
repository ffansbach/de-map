<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin\Page;

use FreifunkKarte\Admin\Html;
use FreifunkKarte\Admin\PageContext;
use FreifunkKarte\Admin\Safe;

/**
 * Pieces several pages show the same way.
 */
final class Widgets
{
    public static function runLink(mixed $id): Safe
    {
        return Html::link('/runs/' . (int) $id, '#' . (int) $id);
    }

    public static function communityLink(string $key, ?string $label = null): Safe
    {
        return Html::link('/communities/' . rawurlencode($key), $label ?? $key);
    }

    /**
     * @param array<string, int> $counts severity => number, as {@see \FreifunkKarte\Run\RunRepository::eventCounts()} gives them
     */
    public static function eventCounts(array $counts): Safe
    {
        if ($counts === []) {
            return new Safe('<span class="muted">-</span>');
        }

        $parts = [];

        foreach (['alert', 'warning', 'info'] as $severity) {
            if (($counts[$severity] ?? 0) > 0) {
                $parts[] = Html::badge($counts[$severity] . ' ' . $severity, $severity)->html;
            }
        }

        return new Safe(implode(' ', $parts));
    }

    /**
     * @param list<array<string, mixed>> $events rows of the events table
     * @param bool $withRun whether to show which run an event belongs to
     */
    public static function eventsTable(PageContext $context, array $events, bool $withRun = false, string $empty = 'No events.'): Safe
    {
        $headers = $withRun ? ['run', 'when', 'severity', 'kind', 'what'] : ['severity', 'kind', 'what'];
        $rows = [];

        foreach ($events as $event) {
            $what = (string) $event['message'];
            $subject = (string) $event['subject'];
            $link = $subject !== '' && $subject !== 'run' && $event['kind'] !== 'new-technical-type'
                ? Html::safe(Html::e($what) . ' ' . self::communityLink($subject, 'open')->html)
                : $what;

            $row = [Html::badge((string) $event['severity']), (string) $event['kind'], $link];
            $rows[] = $withRun
                ? [self::runLink($event['run_id']), $context->time($event['run_started_at'] ?? $event['created_at'] ?? null), ...$row]
                : $row;
        }

        return Html::table($headers, $rows, $empty);
    }

    /**
     * @param array<string, mixed> $run a row of the runs table
     */
    public static function runCards(array $run): Safe
    {
        $cards = [
            ['nodes', Html::number($run['nodes'] ?? null)],
            ['communities listed', Html::number($run['communities'] ?? null)],
            ['with nodes', Html::number($run['with_nodes'] ?? null)],
            ['maps without data', Html::number($run['without_data'] ?? null)],
            ['requests', Html::number($run['requests'] ?? null)],
            ['duration', Html::duration($run['seconds'] ?? null)],
        ];
        $html = '<div class="cards">';

        foreach ($cards as [$label, $value]) {
            $html .= sprintf('<div class="card"><b>%s</b><span>%s</span></div>', Html::e($value === '' ? '-' : $value), Html::e($label));
        }

        return new Safe($html . '</div>');
    }

    /**
     * @param array<string, string|int|null> $query the filters to keep when paging
     */
    public static function pager(string $path, int $page, bool $hasNext, array $query = []): Safe
    {
        $links = [];

        if ($page > 1) {
            $links[] = Html::link($path, '← newer', [...$query, 'page' => $page - 1 === 1 ? null : $page - 1])->html;
        }

        if ($hasNext) {
            $links[] = Html::link($path, 'older →', [...$query, 'page' => $page + 1])->html;
        }

        return new Safe($links === [] ? '' : '<div class="pager">' . implode(' ', $links) . '</div>');
    }
}
