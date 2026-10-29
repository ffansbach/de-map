<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

/**
 * Tiny charts as inline SVG: no JavaScript, no assets, they follow the colours of the page.
 */
final class Svg
{
    /**
     * @param list<int|float> $values oldest first
     */
    public static function sparkline(array $values, int $width = 120, int $height = 28): Safe
    {
        if (count($values) < 2) {
            return new Safe('');
        }

        return new Safe(sprintf(
            '<svg class="spark" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" role="img" aria-label="trend"><polyline fill="none" stroke="currentColor" stroke-width="1.5" points="%3$s"/></svg>',
            $width,
            $height,
            self::points($values, $width, $height, 2),
        ));
    }

    /**
     * A line chart with its lowest and highest value written next to it.
     *
     * @param list<int|float> $values oldest first
     */
    public static function chart(array $values, string $label, int $width = 640, int $height = 110): Safe
    {
        if (count($values) < 2) {
            return new Safe('<p class="muted">' . Html::e($label) . ': not enough runs for a chart yet.</p>');
        }

        return new Safe(sprintf(
            '<figure class="chart"><figcaption>%1$s <span class="muted">(%2$s … %3$s over the last %4$d runs)</span></figcaption><svg viewBox="0 0 %5$d %6$d" width="100%%" height="%6$d" preserveAspectRatio="none" role="img" aria-label="%1$s"><polyline fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" points="%7$s"/></svg></figure>',
            Html::e($label),
            Html::e(Html::number(min($values))),
            Html::e(Html::number(max($values))),
            count($values),
            $width,
            $height,
            self::points($values, $width, $height, 4),
        ));
    }

    /**
     * @param list<int|float> $values
     */
    private static function points(array $values, int $width, int $height, int $padding): string
    {
        if ($values === []) {
            return '';
        }

        $min = min($values);
        $max = max($values);
        $span = $max - $min;
        $step = ($width - 2 * $padding) / (count($values) - 1);
        $points = [];

        foreach ($values as $index => $value) {
            $x = $padding + $index * $step;
            // a flat line is drawn in the middle
            $y = $span > 0 ? $padding + ($height - 2 * $padding) * (1 - ($value - $min) / $span) : $height / 2;
            $points[] = sprintf('%.1f,%.1f', $x, $y);
        }

        return implode(' ', $points);
    }
}
