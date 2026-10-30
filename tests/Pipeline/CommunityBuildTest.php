<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline;

use FreifunkKarte\Pipeline\CommunityBuild;
use FreifunkKarte\Pipeline\CommunityReport;
use PHPUnit\Framework\TestCase;

final class CommunityBuildTest extends TestCase
{
    public function testApiDocumentGivesInfoMapsAndTypes(): void
    {
        $build = CommunityBuild::fromApiDocument('town', 'https://api/town.json', [
            'name' => 'Town',
            'homePage' => 'https://town.example',
            'url' => 'https://ignored.example',
            'metacommunity' => 'Region',
            'state' => ['nodes' => '42'],
            'nodeMaps' => [
                ['url' => 'map.example/', 'technicalType' => 'MeshViewer'],
                ['url' => 'https://list.example/nodelist.json', 'technicalType' => 'nodelist'],
            ],
        ]);

        $this->assertSame(['town', 'Town', 'https://town.example', 'Region'], [$build->info->key, $build->info->name, $build->info->url, $build->info->meta]);
        $this->assertTrue($build->listed);
        $this->assertSame(CommunityBuild::ORIGIN_DIRECTORY, $build->origin);
        $this->assertSame(CommunityReport::NO_DATA, $build->outcome);
        $this->assertSame(['meshviewer', 'nodelist'], $build->declaredTypes);
        $this->assertSame(['http://map.example/', 'https://list.example/nodelist.json'], array_map(static fn ($e) => $e->mapUrl, $build->entries));
        $this->assertSame(['meshviewer', 'nodelist'], array_map(static fn ($e) => $e->declaredType, $build->entries));
        $this->assertSame(['meta-community: Region'], $build->messages);
        $this->assertSame(42, $build->claimedNodes);
        $this->assertFalse($build->isFixed());
    }

    public function testNameAndUrlFallBack(): void
    {
        $build = CommunityBuild::fromApiDocument('town', 'u', ['name' => '', 'url' => 'https://town.example', 'nodeMaps' => []]);

        $this->assertSame('town', $build->info->name);
        $this->assertSame('https://town.example', $build->info->url);
        $this->assertNull($build->info->meta);
        $this->assertNull($build->claimedNodes);
        $this->assertSame([], $build->entries);
    }

    public function testDocumentWithoutNodeMapsIsNotListed(): void
    {
        $build = CommunityBuild::fromApiDocument('town', 'u', ['name' => 'Town', 'state' => ['nodes' => 7]]);

        $this->assertFalse($build->listed);
        $this->assertSame(CommunityReport::NO_NODEMAPS, $build->outcome);
        $this->assertSame(['API file has no nodeMaps'], $build->messages);
        $this->assertSame(7, $build->claimedNodes);
    }

    public function testCorruptNameIsNotListed(): void
    {
        $build = CommunityBuild::fromApiDocument('town', 'u', ['name' => "Bad \xB1 name", 'nodeMaps' => []]);

        $this->assertFalse($build->listed);
        $this->assertSame(CommunityReport::NO_DATA, $build->outcome);
        $this->assertStringContainsString('not valid UTF-8', $build->messages[0]);
    }

    public function testMapsWithoutUrlOrTypeAreIgnoredAndSaid(): void
    {
        $build = CommunityBuild::fromApiDocument('town', 'u', ['nodeMaps' => [
            'not a map',
            ['technicalType' => 'meshviewer'],
            ['url' => '  ', 'technicalType' => 'meshviewer'],
            ['url' => 'https://typeless.example/'],
            ['url' => 'https://ok.example/', 'technicalType' => 'ffmap'],
        ]]);

        $this->assertSame(['https://ok.example/'], array_map(static fn ($e) => $e->mapUrl, $build->entries));
        $this->assertSame(['(none)', 'ffmap'], $build->declaredTypes);
        $this->assertSame([
            'ignored a map without url',
            'ignored a map without url',
            'ignored a map without url',
            'ignored map without technicalType: https://typeless.example/',
        ], $build->messages);
    }

    public function testFixedCommunity(): void
    {
        $build = CommunityBuild::fixed('ff_fixed', ['url' => 'nodes.example/list.json', 'parser' => 'NodeList', 'name' => 'Fixed', 'homePage' => 'https://fixed.example']);

        $this->assertTrue($build->isFixed());
        $this->assertTrue($build->listed);
        $this->assertNull($build->apiUrl);
        $this->assertSame('Fixed', $build->info->name);
        $this->assertSame(['nodelist'], $build->declaredTypes);
        $this->assertSame('http://nodes.example/list.json', $build->entries[0]->mapUrl);
        $this->assertSame('ff_fixed', $build->entries[0]->community);
        $this->assertSame(['fixed community'], $build->messages);
    }

    public function testSkippedAndUnavailable(): void
    {
        $skipped = CommunityBuild::skipped('town', 'https://api/town.json');
        $unavailable = CommunityBuild::apiUnavailable('town', 'https://api/town.json', 'HTTP 404');

        $this->assertFalse($skipped->listed);
        $this->assertSame(CommunityReport::SKIPPED, $skipped->outcome);
        $this->assertSame(['skipped by configuration'], $skipped->messages);
        $this->assertSame('town', $skipped->info->name);
        $this->assertFalse($unavailable->listed);
        $this->assertSame(CommunityReport::API_UNAVAILABLE, $unavailable->outcome);
        $this->assertSame(['API file not available: HTTP 404'], $unavailable->messages);
    }

    public function testWithMessageLeavesTheOriginalAlone(): void
    {
        $build = CommunityBuild::skipped('town', 'u');
        $more = $build->withMessage('another');

        $this->assertSame(['skipped by configuration'], $build->messages);
        $this->assertSame(['skipped by configuration', 'another'], $more->messages);
        $this->assertSame($build->info, $more->info);
    }
}
