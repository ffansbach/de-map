<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Resolve;

/**
 * The steps by which a map is followed to its data, in the order they are tried.
 */
enum StageKind: string
{
    /** data URLs found by an earlier run */
    case Cache = 'cache';
    /** the URL the community declared: it may be the data itself */
    case Declared = 'declared';
    /** the map's config.json, which tells where the data is */
    case Config = 'config';
    /** the data paths that config.json (or the page itself) names */
    case DataPath = 'datapath';
    /** the usual places for data files */
    case Candidates = 'candidates';
}
