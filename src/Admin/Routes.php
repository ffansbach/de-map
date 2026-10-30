<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

use FreifunkKarte\Run\RunRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Routes
{
    /**
     * The admin UI with all its pages.
     */
    public static function app(RunRepository $repository, AdminConfig $config, LoggerInterface $logger = new NullLogger(), string $basePath = ''): AdminApp
    {
        return new AdminApp($repository, $config, self::all(), $logger, $basePath);
    }

    /**
     * @return list<array{string, Page}>
     */
    public static function all(): array
    {
        return [
            ['#^/$#', new Page\DashboardPage()],
            ['#^/runs$#', new Page\RunsPage()],
            ['#^/runs/(?<id>\d+)$#', new Page\RunPage()],
            ['#^/communities$#', new Page\CommunitiesPage()],
            ['#^/communities/(?<key>[A-Za-z0-9_.\-]+)$#', new Page\CommunityPage()],
            ['#^/no-data$#', new Page\NoDataPage()],
            ['#^/fixed$#', new Page\FixedPage()],
            ['#^/events$#', new Page\EventsPage()],
        ];
    }
}
