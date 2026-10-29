<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

final readonly class PageResult
{
    /**
     * @param string $nav which entry of the menu is current (its path, e.g. "/runs")
     */
    public function __construct(
        public string $title,
        public string $nav,
        public Safe $body,
        public int $status = 200,
    ) {
    }

    public static function notFound(string $what): self
    {
        return new self('Not found', '', new Safe('<p>' . Html::e($what) . '</p>'), 404);
    }
}
