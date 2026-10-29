<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

enum LocationProblem
{
    /** no coordinates, or 0 (which is what unconfigured nodes report) */
    case Missing;
    case OutOfRange;
}
