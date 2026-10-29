<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

/**
 * HTML that is already safe: built from escaped parts. Everything else that reaches a page is
 * escaped (see Html::cell), so that a community called "<script>" is only ever text.
 */
final readonly class Safe implements \Stringable
{
    public function __construct(public string $html)
    {
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
