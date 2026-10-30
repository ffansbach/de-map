<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Resolve;

enum WalkStatus
{
    /** there are URLs that have not been requested yet */
    case Fetch;
    /** the map led to data */
    case Resolved;
    /** nothing left to try, no data found */
    case Exhausted;
}
