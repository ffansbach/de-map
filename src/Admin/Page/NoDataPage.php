<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin\Page;

use FreifunkKarte\Admin\Html;
use FreifunkKarte\Admin\Page;
use FreifunkKarte\Admin\PageContext;
use FreifunkKarte\Admin\PageResult;
use FreifunkKarte\Admin\Safe;
use FreifunkKarte\Run\NoDataReason;

/**
 * Maps we know about but get no nodes from. The ones with a page behind the map URL come first:
 * somebody can open that page and find where its data really is.
 */
final class NoDataPage implements Page
{
    public function render(PageContext $context): PageResult
    {
        $latest = $context->repository->latestOkRun();

        if ($latest === null) {
            return new PageResult('Maps without data', '/no-data', new Safe('<p>No run has been recorded yet.</p>'));
        }

        $runId = (int) $latest['id'];
        $lastOk = $context->repository->lastOkRuns();
        $okRunIds = $context->repository->okRunIds();
        $groups = [];

        foreach ($context->repository->communityRows($runId, 'no-data') as $row) {
            $detail = $context->repository->communityDetail($runId, (string) $row['key']);
            $attempts = $detail['attempts'] ?? [];
            $reason = NoDataReason::classify($attempts);
            $groups[$reason->value][] = [$reason, $row, $attempts];
        }

        $body = sprintf('<p class="muted">From run %s, %s. Every community here points to at least one map, and no map delivered a node list.</p>', Widgets::runLink($runId)->html, Html::e($context->time($latest['started_at'])));

        foreach (NoDataReason::cases() as $reason) {
            $entries = $groups[$reason->value] ?? [];

            if ($entries === []) {
                continue;
            }

            $rows = [];

            foreach ($entries as [, $row, $attempts]) {
                $rows[] = [
                    Widgets::communityLink((string) $row['key'], (string) $row['name']),
                    (string) $row['origin'],
                    implode(', ', $row['declared_types']),
                    $reason === NoDataReason::PageWithoutData ? $this->pages($attempts) : $this->tried($attempts),
                    Widgets::dry($context, (string) $row['key'], $lastOk, $okRunIds),
                ];
            }

            $lead = $reason === NoDataReason::PageWithoutData ? 'Page found' : 'Last attempts';
            $body .= sprintf('<h2>%s (%d)</h2>', Html::e(ucfirst($reason->label())), count($rows))
                . Html::table(['community', 'origin', 'declared types', $lead, 'without nodes for'], $rows)->html;
        }

        if ($groups === []) {
            $body .= '<p>Every map delivered data in this run.</p>';
        }

        return new PageResult('Maps without data', '/no-data', new Safe($body . $this->withoutMaps($context, $runId)->html));
    }

    /**
     * The pages that answered: where to look for the data.
     *
     * @param list<array<string, mixed>> $attempts
     */
    private function pages(array $attempts): Safe
    {
        $links = [];

        foreach ($attempts as $attempt) {
            if (($attempt['status'] ?? null) === 200 && ($attempt['format'] ?? null) === 'html') {
                $links[] = Html::externalLink((string) $attempt['url'])->html;
            }
        }

        return new Safe(implode('<br>', $links));
    }

    /**
     * @param list<array<string, mixed>> $attempts
     */
    private function tried(array $attempts): Safe
    {
        $answers = [];

        foreach (array_slice($attempts, 0, 3) as $attempt) {
            $answers[] = Html::e((string) ($attempt['status'] ?? $attempt['error'] ?? 'no answer')) . ' ' . Html::externalLink((string) ($attempt['url'] ?? ''))->html;
        }

        $more = count($attempts) > 3 ? sprintf('<br><span class="muted">and %d more, see the community</span>', count($attempts) - 3) : '';

        return new Safe(implode('<br>', $answers) . $more);
    }

    /**
     * Communities without a map to look at: a matter of the directory, not of the parser.
     */
    private function withoutMaps(PageContext $context, int $runId): Safe
    {
        $html = '';

        foreach (['api-unavailable' => 'API file not available', 'no-nodemaps' => 'API file without any map'] as $outcome => $title) {
            $rows = array_map(
                static fn (array $row): array => [Widgets::communityLink((string) $row['key'], (string) $row['name']), $row['api_url'] !== null ? Html::externalLink((string) $row['api_url']) : null],
                $context->repository->communityRows($runId, $outcome),
            );

            $html .= sprintf('<details><summary>%s (%d)</summary>%s</details>', Html::e($title), count($rows), Html::table(['community', 'API file'], $rows)->html);
        }

        return new Safe('<h2>Communities with no map to look at</h2><p class="muted">Their entry in the directory has no usable map. That is for their admins to fix.</p>' . $html);
    }
}
