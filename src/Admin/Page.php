<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

interface Page
{
    public function render(PageContext $context): PageResult;
}
