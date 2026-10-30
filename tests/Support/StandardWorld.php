<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Support;

/**
 * The reference scenario: a small invented Freifunk world that the pipeline tests run against. The files in
 * tests/fixtures/golden/reference are the recorded nodes and communities of this world.
 *
 * Communities and what they exercise:
 *  - nl-city:        nodelist AND meshviewer map; the nodelist must win
 *  - mv-city:        meshviewer page URL without data link; data found by probing; has a meta community
 *  - flat-town:      direct .json URL in the flat meshviewer.json format; homePage differs from url
 *  - hop-town:       hopglass config.json with two dataPaths
 *  - dead-town:      map URL that delivers nothing
 *  - dup-a, dup-b:   two communities sharing one map URL
 *  - overlap-a/b:    two different URLs serving the same nodes
 *  - skipped-town:   on the skip list
 *  - netmon-old:     removed map type
 *  - broken-api:     API file not loadable
 *  - no-maps:        API file without nodeMaps
 *  - fixed communities (fixedCommunities.php): Ffmap, nodelist and a dead one
 */
final class StandardWorld
{
    public const SKIP = ['skipped-town'];

    public static function build(): World
    {
        $world = (new World())
            ->community('nl-city', ['nodeMaps' => [
                ['technicalType' => 'nodelist', 'url' => 'https://nl.test/nodelist.json'],
                ['technicalType' => 'meshviewer', 'url' => 'https://nl.test/map/'],
            ]])
            ->route('https://nl.test/nodelist.json', World::fixture('nodelist', 'nl'))
            ->route('https://nl.test/map/data/nodes.json', World::fixture('nodeinfo', 'nlmap'))
            ->community('mv-city', ['metacommunity' => 'Meta Nord', 'nodeMaps' => [
                ['technicalType' => 'meshviewer', 'url' => 'https://mv.test/map'],
            ]])
            ->route('https://mv.test/map/data/nodes.json', World::fixture('nodeinfo', 'mv'))
            ->community('flat-town', ['homePage' => 'https://flat.example/home', 'nodeMaps' => [
                ['technicalType' => 'ffmap', 'url' => 'https://flat.test/data/meshviewer.json'],
            ]])
            ->route('https://flat.test/data/meshviewer.json', World::fixture('meshviewer_flat', 'flat'))
            ->community('hop-town', ['nodeMaps' => [
                ['technicalType' => 'hopglass', 'url' => 'https://hop.test/'],
            ]])
            ->route('https://hop.test/config.json', (string) json_encode(['dataPath' => ['https://hop.test/a/', 'https://hop.test/b/']]))
            ->route('https://hop.test/a/nodes.json', World::fixture('nodeinfo', 'hopa'))
            ->route('https://hop.test/b/nodes.json', World::fixture('nodeinfo', 'hopb'))
            ->community('dead-town', ['nodeMaps' => [
                ['technicalType' => 'ffmap', 'url' => 'https://dead.test/map/'],
            ]])
            ->community('dup-a', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://dup.test/data/nodes.json']]])
            ->community('dup-b', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://dup.test/data/nodes.json']]])
            ->route('https://dup.test/data/nodes.json', World::fixture('nodeinfo', 'dup'))
            ->community('overlap-a', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://overlap-a.test/data/nodes.json']]])
            ->community('overlap-b', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://overlap-b.test/data/nodes.json']]])
            ->route('https://overlap-a.test/data/nodes.json', World::fixture('nodeinfo', 'ov'))
            ->route('https://overlap-b.test/data/nodes.json', World::fixture('nodeinfo', 'ov'))
            ->community('skipped-town', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://skipped.test/data/nodes.json']]])
            ->route('https://skipped.test/data/nodes.json', World::fixture('nodeinfo', 'skipped'))
            ->community('netmon-old', ['nodeMaps' => [['technicalType' => 'netmon', 'url' => 'https://netmon.test/']]])
            ->communityWithoutApiFile('broken-api')
            ->community('no-maps')
            ->fixed('ff_fixed_flat', 'https://fixed.test/data/meshviewer.json', 'Ffmap')
            ->route('https://fixed.test/data/meshviewer.json', World::fixture('meshviewer_flat', 'fixedflat'))
            ->fixed('ff_fixed_nl', 'https://fixed.test/nodelist.json', 'nodelist')
            ->route('https://fixed.test/nodelist.json', World::fixture('nodelist', 'fixednl'))
            ->fixed('ff_fixed_dead', 'https://fixed-dead.test/data/nodes.json', 'Ffmap');

        return $world;
    }
}
