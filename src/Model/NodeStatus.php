<?php

declare(strict_types=1);

namespace FreifunkKarte\Model;

enum NodeStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    /** the source does not say whether the node is online (nodelist without status.online) */
    case Unknown = 'unknown';
}
