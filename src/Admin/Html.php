<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The small vocabulary the pages are written in. Text is escaped; markup is {@see Safe}.
 */
final class Html
{
    public static function e(string|int|float|null $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Plain values are escaped, Safe is passed through.
     */
    public static function cell(string|int|float|Safe|null $value, string $empty = '-'): string
    {
        if ($value instanceof Safe) {
            return $value->html;
        }

        return $value === null || $value === '' ? '<span class="muted">' . self::e($empty) . '</span>' : self::e($value);
    }

    public static function safe(string $html): Safe
    {
        return new Safe($html);
    }

    /**
     * @param array<string, string|int|null> $query
     */
    public static function link(string $path, string|Safe $label, array $query = []): Safe
    {
        $query = array_filter($query, static fn (string|int|null $v): bool => $v !== null && $v !== '');

        return new Safe(sprintf('<a href="%s">%s</a>', self::e($path . ($query !== [] ? '?' . http_build_query($query) : '')), $label instanceof Safe ? $label->html : self::e($label)));
    }

    /**
     * A URL from the outside world: a link only if it is http or https (never javascript: and the like),
     * otherwise just text.
     */
    public static function externalLink(string $url, ?string $label = null): Safe
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return new Safe(self::e($label ?? $url));
        }

        return new Safe(sprintf('<a href="%s" rel="noopener noreferrer nofollow" target="_blank">%s</a>', self::e($url), self::e($label ?? $url)));
    }

    public static function badge(string $text, string $kind = ''): Safe
    {
        return new Safe(sprintf('<span class="badge %s">%s</span>', self::e($kind !== '' ? $kind : $text), self::e($text)));
    }

    /**
     * @param list<string|Safe> $headers
     * @param list<list<string|int|float|Safe|null>> $rows
     */
    public static function table(array $headers, array $rows, string $empty = 'Nothing to show.'): Safe
    {
        if ($rows === []) {
            return new Safe('<p class="muted">' . self::e($empty) . '</p>');
        }

        $html = '<div class="scroll"><table><thead><tr>';

        foreach ($headers as $header) {
            $html .= '<th>' . self::cell($header) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';

            foreach ($row as $value) {
                $html .= '<td>' . self::cell($value) . '</td>';
            }

            $html .= '</tr>';
        }

        return new Safe($html . '</tbody></table></div>');
    }

    /**
     * @param array<string, string|int|float|Safe|null> $pairs
     */
    public static function definitions(array $pairs): Safe
    {
        $html = '<dl>';

        foreach ($pairs as $term => $value) {
            $html .= '<dt>' . self::e($term) . '</dt><dd>' . self::cell($value) . '</dd>';
        }

        return new Safe($html . '</dl>');
    }

    public static function time(mixed $iso, string $timezone = 'Europe/Berlin'): string
    {
        if (!is_string($iso) || $iso === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i');
        } catch (Throwable) {
            return $iso;
        }
    }

    public static function duration(mixed $seconds): string
    {
        if (!is_numeric($seconds)) {
            return '';
        }

        $seconds = (float) $seconds;

        return $seconds >= 60 ? sprintf('%d min %02d s', intdiv((int) $seconds, 60), (int) $seconds % 60) : sprintf('%.1f s', $seconds);
    }

    public static function number(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 0, '.', ' ') : '';
    }
}
