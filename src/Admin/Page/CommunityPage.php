<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin\Page;

use FreifunkKarte\Admin\Html;
use FreifunkKarte\Admin\Page;
use FreifunkKarte\Admin\PageContext;
use FreifunkKarte\Admin\PageResult;
use FreifunkKarte\Admin\Safe;
use FreifunkKarte\Admin\Svg;
use FreifunkKarte\Run\NoDataReason;

final class CommunityPage implements Page
{
    private const HISTORY = 60;

    public function render(PageContext $context): PageResult
    {
        $repository = $context->repository;
        $key = $context->params['key'] ?? '';
        $history = $repository->communityHistory($key, self::HISTORY);

        if ($history === []) {
            return PageResult::notFound('There is no community "' . $key . '" in the history.');
        }

        $detail = $repository->communityDetail((int) $history[0]['run_id'], $key);

        if ($detail === null) {
            return PageResult::notFound('There is no community "' . $key . '" in the history.');
        }

        $body = $this->summary($context, $detail, (int) $history[0]['run_id'])->html
            . $this->dryness($context, $key, (string) $detail['outcome'])->html
            . $this->sources($detail)->html
            . $this->attempts($detail)->html
            . $this->messages($detail)->html
            . $this->history($context, $history)->html;

        return new PageResult((string) $detail['name'], '/communities', new Safe($body));
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function summary(PageContext $context, array $detail, int $runId): Safe
    {
        return new Safe(
            '<p class="muted">As of run ' . Widgets::runLink($runId)->html . '</p>'
            . Html::definitions([
                'key' => (string) $detail['key'],
                'origin' => (string) $detail['origin'],
                'outcome' => Html::badge((string) $detail['outcome']),
                'format delivered' => $detail['format'] ?? null,
                'declared map types' => implode(', ', $detail['declared_types']),
                'meta-community' => $detail['meta'] ?? null,
                'API file' => $detail['api_url'] !== null ? Html::externalLink((string) $detail['api_url']) : null,
                'listed on the map' => (int) $detail['listed'] === 1 ? 'yes' : 'no',
                'nodes found / kept / also elsewhere' => sprintf('%d / %d / %d', $detail['found'], $detail['added'], $detail['duplicates']),
                'without location / dropped / invalid' => sprintf('%d / %d / %d', $detail['without_location'], $detail['dropped'], $detail['invalid']),
                'showing last good data since' => $detail['stale_since'] !== null ? $context->time($detail['stale_since']) : null,
            ])->html,
        );
    }

    private function dryness(PageContext $context, string $key, string $outcome): Safe
    {
        if ($outcome === 'ok') {
            return new Safe('');
        }

        $last = $context->repository->lastOkRuns()[$key] ?? null;

        if ($last === null) {
            return new Safe('<p><strong>This community has never delivered nodes in the recorded history.</strong></p>');
        }

        $since = count(array_filter($context->repository->okRunIds(), static fn (int $id): bool => $id > $last['run_id']));

        return new Safe(sprintf(
            '<p><strong>Dry for %d %s.</strong> It last delivered nodes in run %s (%s).</p>',
            $since,
            $since === 1 ? 'run' : 'runs',
            Widgets::runLink($last['run_id'])->html,
            Html::e($context->time($last['started_at'])),
        ));
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function sources(array $detail): Safe
    {
        $rows = array_values(array_map(static fn (string $url): array => [Html::externalLink($url)], $detail['source_urls']));

        return new Safe('<h2>Where the nodes come from</h2>' . Html::table(['data URL'], $rows, 'No data source in this run.')->html);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function attempts(array $detail): Safe
    {
        if ($detail['attempts'] === []) {
            return new Safe('');
        }

        $reason = $detail['outcome'] === 'no-data' ? '<p><strong>' . Html::e(ucfirst(NoDataReason::classify($detail['attempts'])->label())) . '.</strong></p>' : '';
        $rows = array_values(array_map(static fn (array $a): array => [
            Html::externalLink((string) ($a['url'] ?? '')),
            $a['status'] ?? (string) ($a['error'] ?? 'no answer'),
            (string) ($a['format'] ?? ''),
            (int) ($a['nodes'] ?? 0),
        ], $detail['attempts']));

        return new Safe('<h2>What was tried</h2>' . $reason . Html::table(['url', 'answer', 'looked like', 'entries'], $rows)->html);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function messages(array $detail): Safe
    {
        $warnings = $detail['warnings'] === [] ? '' : '<h2>Warnings about the source</h2><ul>' . implode('', array_map(static fn (string $w): string => '<li>' . Html::e($w) . '</li>', $detail['warnings'])) . '</ul>';
        $log = $detail['messages'] === [] ? '' : '<details><summary>Account of the run for this community</summary><pre><code>' . Html::e(implode("\n", $detail['messages'])) . '</code></pre></details>';

        return new Safe($warnings . $log);
    }

    /**
     * @param list<array<string, mixed>> $history newest first
     */
    private function history(PageContext $context, array $history): Safe
    {
        $oldestFirst = array_reverse($history);
        $rows = array_map(fn (array $h): array => [
            Widgets::runLink($h['run_id']),
            $context->time($h['started_at']),
            Html::badge((string) $h['outcome']),
            (string) ($h['format'] ?? ''),
            (int) $h['found'],
            (int) $h['added'],
            (int) $h['duplicates'],
            $h['stale_since'] !== null ? Html::badge('last good data', 'stale') : null,
        ], $history);

        return new Safe(
            sprintf('<h2>History (last %d runs)</h2>', count($history))
            . Svg::chart(array_map(static fn (array $h): int => (int) $h['added'] + (int) $h['duplicates'], $oldestFirst), 'Nodes delivered')->html
            . Html::table(['run', 'started', 'outcome', 'format', 'found', 'kept', 'also elsewhere', ''], $rows)->html,
        );
    }
}
