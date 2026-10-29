<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Community;

use FreifunkKarte\Community\CommunityInfo;
use FreifunkKarte\Community\MetaCommunityResolver;
use FreifunkKarte\Community\MetaLink;
use PHPUnit\Framework\TestCase;

final class MetaCommunityResolverTest extends TestCase
{
    private static function member(string $key, string $name, string $url, ?string $meta): CommunityInfo
    {
        return new CommunityInfo($key, $name, $url, $meta);
    }

    /**
     * @param list<CommunityInfo> $communities
     * @param array<string, string|null> $overrides
     */
    private function link(string $meta, array $communities, array $overrides = []): MetaLink
    {
        $links = (new MetaCommunityResolver($overrides))->resolve($communities);
        $this->assertArrayHasKey($meta, $links);

        return $links[$meta];
    }

    public function testCommunitiesWithoutMetaAreIgnored(): void
    {
        $this->assertSame([], (new MetaCommunityResolver())->resolve([
            self::member('a', 'A', 'https://a.test/', null),
            self::member('b', 'B', 'https://b.test/', ''),
        ]));
    }

    public function testAMemberThatIsTheMetaCommunityGivesItsOwnUrl(): void
    {
        $link = $this->link('Freifunk München', [
            self::member('dillingen-donau', 'Freifunk Dillingen', 'https://freifunk-dillingen-donau.de/', 'Freifunk München'),
            self::member('muenchen', 'Freifunk München', 'https://ffmuc.net/', 'Freifunk München'),
            self::member('ulm', 'Freifunk Ulm', 'https://ulm.example/', 'Freifunk München'),
        ]);

        $this->assertSame(['https://ffmuc.net/', MetaLink::MEMBER, 3], [$link->url, $link->source, $link->members]);
    }

    public function testMemberIsRecognisedByDirectoryKeyToo(): void
    {
        $link = $this->link('Freifunk Uelzen', [
            self::member('uelzen', 'Uelzen', 'https://www.freifunk-uelzen.de', 'Freifunk Uelzen'),
            self::member('celle', 'Celle', 'https://celle.test/', 'Freifunk Uelzen'),
        ]);

        $this->assertSame('https://www.freifunk-uelzen.de', $link->url);
    }

    public function testNamesAreComparedWithoutNoiseWordsAndPunctuation(): void
    {
        $link = $this->link('Freifunk SüdPfalz e.V.', [
            self::member('a', 'Freifunk Südpfalz', 'https://suedpfalz.test/', 'Freifunk SüdPfalz e.V.'),
            self::member('b', 'B', 'https://b.test/', 'Freifunk SüdPfalz e.V.'),
        ]);

        $this->assertSame('https://suedpfalz.test/', $link->url);
    }

    public function testAHostSharedByMostMembersIsUsed(): void
    {
        $link = $this->link('Neanderfunk', [
            self::member('a', 'A', 'https://neanderfunk.de/a', 'Neanderfunk'),
            self::member('b', 'B', 'http://www.neanderfunk.de/b', 'Neanderfunk'),
            self::member('c', 'C', 'https://neanderfunk.de/c', 'Neanderfunk'),
            self::member('d', 'D', 'https://other.test/', 'Neanderfunk'),
        ]);

        $this->assertSame(['https://neanderfunk.de/', MetaLink::SHARED_HOST], [$link->url, $link->source]);
    }

    public function testASharedHostNeedsTwoMembersAndSixtyPercent(): void
    {
        $oneOfTwo = $this->link('X', [
            self::member('a', 'A', 'https://same.test/', 'X'),
            self::member('b', 'B', 'https://other.test/', 'X'),
        ]);
        $this->assertSame([null, MetaLink::NONE], [$oneOfTwo->url, $oneOfTwo->source]);

        $twoOfFive = $this->link('Y', [
            self::member('a', 'A', 'https://same.test/1', 'Y'),
            self::member('b', 'B', 'https://same.test/2', 'Y'),
            self::member('c', 'C', 'https://c.test/', 'Y'),
            self::member('d', 'D', 'https://d.test/', 'Y'),
            self::member('e', 'E', 'https://e.test/', 'Y'),
        ]);
        $this->assertNull($twoOfFive->url);
    }

    public function testSubdomainsOfFreifunkNetAreNotMergedIntoOneHost(): void
    {
        $link = $this->link('Freifunk Saar', [
            self::member('a', 'A', 'https://a.freifunk.net/', 'Freifunk Saar'),
            self::member('b', 'B', 'https://b.freifunk.net/', 'Freifunk Saar'),
            self::member('c', 'C', 'https://freifunk.net/', 'Freifunk Saar'),
        ]);

        $this->assertNull($link->url);
    }

    public function testASingleMemberMetaCommunityGetsNoSeparateLink(): void
    {
        $link = $this->link('Freifunk Dresden', [self::member('dresden', 'Freifunk Dresden', 'https://ffdd.test/', 'Freifunk Dresden')]);

        $this->assertSame([null, MetaLink::SINGLE, 1], [$link->url, $link->source, $link->members]);
    }

    public function testOverrideWinsOverEverything(): void
    {
        $members = [
            self::member('muenchen', 'Freifunk München', 'https://ffmuc.net/', 'Freifunk München'),
            self::member('ulm', 'Ulm', 'https://ulm.test/', 'Freifunk München'),
        ];

        $link = $this->link('Freifunk München', $members, ['Freifunk München' => 'https://muenchen.example/']);

        $this->assertSame(['https://muenchen.example/', MetaLink::OVERRIDE], [$link->url, $link->source]);
    }

    public function testOverrideCanGiveASingleMemberMetaCommunityALink(): void
    {
        $link = $this->link('Funk', [self::member('a', 'A', 'https://a.test/', 'Funk')], ['Funk' => 'https://funk.test/']);

        $this->assertSame('https://funk.test/', $link->url);
    }

    public function testNullOverrideSuppressesALink(): void
    {
        $link = $this->link('Neanderfunk', [
            self::member('a', 'A', 'https://neanderfunk.de/a', 'Neanderfunk'),
            self::member('b', 'B', 'https://neanderfunk.de/b', 'Neanderfunk'),
        ], ['Neanderfunk' => null]);

        $this->assertSame([null, MetaLink::SUPPRESSED], [$link->url, $link->source]);
    }

    public function testResultKeepsOrderOfFirstAppearance(): void
    {
        $links = (new MetaCommunityResolver())->resolve([
            self::member('a', 'A', '', 'Zeta'),
            self::member('b', 'B', '', 'Alpha'),
            self::member('c', 'C', '', 'Zeta'),
        ]);

        $this->assertSame(['Zeta', 'Alpha'], array_keys($links));
        $this->assertSame(2, $links['Zeta']->members);
    }

    public function testOverrideFile(): void
    {
        $file = sys_get_temp_dir() . '/meta_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($file, (string) json_encode([
            'A' => ['url' => 'https://a.test/'],
            'B' => ['url' => null],
            'C' => ['url' => ''],
            'D' => 'not an object',
            'E' => ['no' => 'url'],
        ]));

        try {
            $resolver = MetaCommunityResolver::fromFile($file);
            $links = $resolver->resolve([
                self::member('1', '1', '', 'A'), self::member('2', '2', '', 'A'),
                self::member('3', '3', '', 'B'), self::member('4', '4', '', 'B'),
                self::member('5', '5', '', 'C'), self::member('6', '6', '', 'C'),
            ]);
        } finally {
            unlink($file);
        }

        $this->assertSame('https://a.test/', $links['A']->url);
        $this->assertSame(MetaLink::SUPPRESSED, $links['B']->source);
        $this->assertSame(MetaLink::NONE, $links['C']->source, 'an empty url is no override');
    }

    public function testMissingOverrideFileMeansNoOverrides(): void
    {
        $this->assertSame([], (new MetaCommunityResolver())->resolve([]));
        $this->assertSame([], MetaCommunityResolver::fromFile('/nonexistent/file.json')->resolve([]));
    }

    public function testTheShippedOverrideFileIsValid(): void
    {
        $content = json_decode((string) file_get_contents(__DIR__ . '/../../config/metacommunities.json'), true);

        $this->assertIsArray($content);
    }
}
