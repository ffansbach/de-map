<?php declare(strict_types=1);

namespace ffmap;

use DateTime;
use Exception;
use Lukaswhite\Directory\Directory as DirectoryAlias;
use PHPUnit\Framework\TestCase;

final class CommunityCacheHandlerTest extends TestCase
{
    /**
     * @var string
     */
    protected string $cacheDirPath = '../cache_test';

    protected DirectoryAlias $cacheDirectory;

    /**
     * @throws Exception
     */
    protected function setUp() : void
    {
        $parent = new DirectoryAlias($this->cacheDirPath);

        if ($parent->exists()) {
            throw new Exception('Directory "'.$this->cacheDirPath.'" already exists');
        }

        $parent->create();

        $this->cacheDirectory = new DirectoryAlias($this->cacheDirPath.'/communities');
        $this->cacheDirectory->create();
    }

    /**
     *
     */
    protected function tearDown() : void
    {
        $parent = new DirectoryAlias($this->cacheDirPath);
        $parent->delete();
    }

    public function testReadCacheNotExisting() : void
    {
        $subject = new CommunityCacheHandler($this->cacheDirPath);

        $this->assertFalse($subject->readCache(
            'berlin',
            'communityFile',
            '-1 day'
        ));
    }

    /**
     * @dataProvider readCacheExistingProvider
     * @param string $cacheContent
     * @param bool $expectedSuccess
     * @param object $expected
     */
    public function testReadCacheExisting(string $cacheContent, bool $expectedSuccess, object $expected) : void
    {
        $this->cacheDirectory->createFile('c_berlin.json', $cacheContent);
        $subject = new CommunityCacheHandler($this->cacheDirPath);

        $result = $subject->readCache(
            'berlin',
            'communityFile',
            '-1 day'
        );

        if (!$expectedSuccess) {
            $this->assertFalse($result);
        } else {
            $this->assertInstanceOf('stdClass', $result);
            $this->assertEquals($expected, $result);
        }
    }

    /**
     * @return array[]
     */
    public function readCacheExistingProvider(): array
    {
        $now = new DateTime();
        $older = new DateTime();
        $older->modify('-2 days');

        return [
            [
                '{"communityFile": {"updated": "'.$now->format(DateTime::ATOM)
                .'","content": {"name": "Berlin"}}}',
                true,
                (object) ['name' => 'Berlin'],
            ],
            [
                '{"communityFile": {"updated": "'.$now->format(DateTime::ATOM)
                .'","content": {"name": "Berlin", "metacommunity":"Berlin"}}}',
                true,
                (object) ['name' => 'Berlin', "metacommunity" => "Berlin"],
            ],
            [
                // case with broken json
                '{"faulty json',
                false,
                (object) [],
            ],
            [
                // case with outdated cache
                '{"communityFile": {"updated": "'.$older->format(DateTime::ATOM)
                .'","content": {"name": "Berlin"}}}',
                false,
                (object) [],
            ],
        ];
    }
}
