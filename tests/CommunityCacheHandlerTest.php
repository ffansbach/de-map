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

    /**
     * @dataProvider storeCacheProvider
     * @param string $communityKey
     * @param string $entryKey
     * @param object $data
     * @param object $expected
     * @param string $prefill
     * @throws Exception
     */
    public function testStoreCache(
        string $communityKey,
        string $entryKey,
        object $data,
        object $expected,
        string $prefill = ''
    ) {
        if ($prefill != '') {
            $this->cacheDirectory->createFile('c_'.$communityKey.'.json', $prefill);
        }

        $subject = new CommunityCacheHandler($this->cacheDirPath);
        $subject->storeCache($communityKey, $entryKey, $data);

        $filePathName = $this->cacheDirPath.'/communities/c_'.$communityKey.'.json';
        $this->assertTrue(file_exists($filePathName));
        $actualContent = file_get_contents($filePathName);
        $actualContent = json_decode($actualContent);

        // checking Timestamp
        $storedDateTime =  new \DateTime($actualContent->$entryKey->updated);
        $storedTS = $storedDateTime->getTimestamp();
        $expectedDateTime =  new \DateTime($expected->$entryKey->updated);
        $expectedTS = $expectedDateTime->getTimestamp();
        // is between -1 and +1 of expected TS
        $this->assertTrue(($storedTS >= ($expectedTS-1) && $storedTS <= ($expectedTS+1)));

        $actualContent->$entryKey->updated = 'checked';
        $expected->$entryKey->updated = 'checked';
        $this->assertEquals($expected, $actualContent);
    }

    /**
     * @return array[]
     */
    public function storeCacheProvider(): array
    {
        $now = new DateTime();
        $nowTS = $now->format(DateTime::ATOM);

        return [
            'trivial case' => [
                'kassel',
                'communityFile',
                (object) ['foo' => 'bar'],
                (object) [
                    'communityFile' => (object) [
                        'updated' => $nowTS,
                        'content' => (object) [
                            'foo' => 'bar'
                        ]
                    ]
                ]
            ],
            'trivial case 2' => [
                'munich',
                'communityFile',
                (object) ['foo' => 'bar', 'dog' => 'wuf'],
                (object) [
                    'communityFile' => (object) [
                        'updated' => $nowTS,
                        'content' => (object) [
                            'foo' => 'bar',
                            'dog' => 'wuf'
                        ]
                    ]
                ]
            ],
            'data already exists' => [
                'munich',
                'communityFile',
                (object) ['foo' => 'bar', 'dog' => 'wuf'],
                (object) [
                    'communityFile' => (object) [
                        'updated' => $nowTS,
                        'content' => (object) [
                            'foo' => 'bar',
                            'dog' => 'wuf'
                        ]
                    ],
                    'otherData' => (object) [
                        "cat" => "meow"
                    ]
                ],
                '{"otherData": {"cat": "meow"}}',
            ],
            'file already exists but data is broken' => [
                'munich',
                'communityFile',
                (object) ['foo' => 'bar', 'dog' => 'wuf'],
                (object) [
                    'communityFile' => (object) [
                        'updated' => $nowTS,
                        'content' => (object) [
                            'foo' => 'bar',
                            'dog' => 'wuf'
                        ]
                    ],
                ],
                '{"otherData": {"cat": ',
            ],
            'data with same key exists - overwrite/update' => [
                'munich',
                'communityFile',
                (object) ['foo' => 'bar', 'dog' => 'wuf'],
                (object) [
                    'communityFile' => (object) [
                        'updated' => $nowTS,
                        'content' => (object) [
                            'foo' => 'bar',
                            'dog' => 'wuf'
                        ]
                    ],
                    'other' => (object) [
                        "updated" => "2020-12-24T07:15:02+01:00",
                        "url" => "xyz"
                    ]
                ],
                '{"communityFile": '
                    .'{"updated": "2020-12-24T07:15:02+01:00", "content": {"x": "y"}},'
                .'"other" :'
                    .'{"updated": "2020-12-24T07:15:02+01:00", "url": "xyz"}'
                .'}',
            ],

        ];
    }
}
