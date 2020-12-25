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
     * While we do not have a mocked file-system, remove the test-directory again.
     *
     * @return void
     */
    protected function tearDown() : void
    {
        $parent = new DirectoryAlias($this->cacheDirPath);
        $parent->delete();
    }

    /**
     * We should get a false in cases where the cache does not exist.
     */
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
        $nowTS = $now->format(DateTime::ATOM);
        $older = new DateTime();
        $older->modify('-2 days');
        $oldTS = $older->format(DateTime::ATOM);

        return [
            'valid and cacheHit' => [
                '{"communityFile": {"updated": "'.$nowTS.'","content": {"name": "Berlin"}}}',
                true,
                (object) ['name' => 'Berlin'],
            ],
            'valid and cacheHit 2' => [
                '{"communityFile": {"updated": "'.$nowTS.'","content": {"name": "Berlin", "metacommunity":"Berlin"}}}',
                true,
                (object) ['name' => 'Berlin', "metacommunity" => "Berlin"],
            ],
            'json is broken' => [
                '{"faulty json',
                false,
                (object) [],
            ],
            'cache is outdated' => [
                // case with outdated cache
                '{"communityFile": {"updated": "'.$oldTS.'","content": {"name": "Berlin"}}}',
                false,
                (object) [],
            ],
            'searched key is missing' => [
                // case valid json but no entry for the searched "communityFile"
                '{"somethingElse": {"updated": "'.$nowTS.'","content": {"name": "Berlin"}}}',
                false,
                (object) [],
            ],
            'cache entry for key exists, but no content' => [
                '{"communityFile": {"updated": "'.$nowTS.'"}}',
                false,
                (object) [],
            ],
        ];
    }
}
