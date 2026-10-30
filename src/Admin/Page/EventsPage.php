<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin\Page;

use FreifunkKarte\Admin\Html;
use FreifunkKarte\Admin\Page;
use FreifunkKarte\Admin\PageContext;
use FreifunkKarte\Admin\PageResult;
use FreifunkKarte\Admin\Safe;

final class EventsPage implements Page
{
    private const PER_PAGE = 100;
    private const SEVERITIES = ['info', 'warning', 'alert'];

    public function render(PageContext $context): PageResult
    {
        $page = $context->int('page', 1);
        $severity = $context->text('severity');
        $severity = $severity !== null && in_array($severity, self::SEVERITIES, true) ? $severity : 'warning';
        $kinds = $context->repository->eventKinds();
        $kind = $context->text('kind');
        $kind = $kind !== null && in_array($kind, $kinds, true) ? $kind : null;

        $events = $context->repository->eventsPage(self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE, $severity, $kind);
        $hasNext = count($events) > self::PER_PAGE;
        $events = array_slice($events, 0, self::PER_PAGE);

        $body = $this->filter($severity, $kind, $kinds)
            . Widgets::eventsTable($context, $events, true, 'No events match.')->html
            . Widgets::pager('/events', $page, $hasNext, ['severity' => $severity, 'kind' => $kind])->html;

        return new PageResult('Events', '/events', new Safe($body));
    }

    /**
     * @param list<string> $kinds
     */
    private function filter(string $severity, ?string $kind, array $kinds): string
    {
        $options = static function (array $values, ?string $current, bool $withAll): string {
            $html = $withAll ? '<option value="">all</option>' : '';

            foreach ($values as $value) {
                $html .= sprintf('<option value="%1$s"%2$s>%1$s</option>', Html::e($value), $value === $current ? ' selected' : '');
            }

            return $html;
        };

        return '<form class="filter" method="get" action="' . Html::e(Html::url('/events')) . '">'
            . '<label>from severity<select name="severity">' . $options(self::SEVERITIES, $severity, false) . '</select></label>'
            . '<label>kind<select name="kind">' . $options($kinds, $kind, true) . '</select></label>'
            . '<button type="submit">Filter</button></form>';
    }
}
