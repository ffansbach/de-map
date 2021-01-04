<?php declare(strict_types=1);

namespace ffmap;

use PHPUnit\Framework\TestCase;

final class CommunityDebugTest extends TestCase
{
    public function testEmpty()
    {
        $subject = new CommunityDebug();
        $this->assertEquals([], $subject->getDebugLog());
    }

    /**
     * @dataProvider addMessageProvider
     * @param array $messages
     * @param array $communityData
     * @param array $expected
     */
    public function testAddMessage(array $messages, array $communityData, array $expected)
    {
        $subject = new CommunityDebug();

        foreach ($messages as $message) {
            $subject->addMessage($message, $communityData);
        }

        $this->assertEquals($expected, $subject->getDebugLog());
    }

    /**
     * @return array[]
     */
    public function addMessageProvider() : array
    {
        $communityData1 = [
            'name' => 'my town',
            'source' => 'http://test.de',
        ];

        $communityData2 = [
            'name' => 'my city',
            'source' => 'http://test.de/foo',
        ];

        return [
            'single message 1' => [
                'message' => ['test'],
                'communityData' => $communityData1,
                'expected' => ['my town' => [
                    'name' => 'my town',
                    'apifile' => 'http://test.de',
                    'message' => ['test'],
                ]],
            ],
            'single message 2' => [
                'message' => ['all went well'],
                'communityData' => $communityData1,
                'expected' => ['my town' => [
                    'name' => 'my town',
                    'apifile' => 'http://test.de',
                    'message' => ['all went well'],
                ]],
            ],
            'single message 2 alternative' => [
                'message' => ['all went well'],
                'communityData' => $communityData2,
                'expected' => ['my city' => [
                    'name' => 'my city',
                    'apifile' => 'http://test.de/foo',
                    'message' => ['all went well'],
                ]],
            ],
            'multiple messages' => [
                'message' => [
                    'test',
                    'another line of information',
                    'this went well'
                ],
                'communityData' => $communityData1,
                'expected' => ['my town' => [
                    'name' => 'my town',
                    'apifile' => 'http://test.de',
                    'message' => [
                        'test',
                        'another line of information',
                        'this went well'
                    ],
                ]],
            ],
        ];
    }

    /**
     * @dataProvider addBasicLogInfoProvider
     * @param array $community
     * @param array $communityData
     * @param array $expected
     */
    public function testAddBasicLogInfo(object $community, array $communityData, array $expected)
    {
        $subject = new CommunityDebug();

        $subject->addBasicLogInfo($community, $communityData);
        $this->assertEquals($expected, $subject->getDebugLog());
    }

    /**
     * @return array[]
     */
    public function addBasicLogInfoProvider() : array
    {
        $communityData1 = [
            'name' => 'my town',
            'source' => 'http://test.de',
        ];

        return [
            'empty case' => [
                'community' => (object)[],
                'communityData' => $communityData1,
                'expected' => ['my town' => [
                    'claimed_nodecount' => false,
                ]],
            ],
            'nodecount int' => [
                'community' => (object)[
                    'state' => (object)[
                        'nodes' => 1,
                    ],
                ],
                'communityData' => $communityData1,
                'expected' => ['my town' => [
                    'claimed_nodecount' => 1,
                ]],
            ],
            'nodecount int bigger' => [
                'community' => (object)[
                    'state' => (object)[
                        'nodes' => 123456,
                    ],
                ],
                'communityData' => $communityData1,
                'expected' => ['my town' => [
                    'claimed_nodecount' => 123456,
                ]],
            ],
            'nodecount string' => [
                'community' => (object)[
                    'state' => (object)[
                        'nodes' => '25',
                    ],
                ],
                'communityData' => $communityData1,
                'expected' => ['my town' => [
                    'claimed_nodecount' => 25,
                ]],
            ],
            'metacommunity 1' => [
                'community' => (object)[
                    'metacommunity' => 'mittelfranken',
                ],
                'communityData' => $communityData1,
                'expected' => ['my town' => [
                    'metacommunity' => 'mittelfranken',
                    'claimed_nodecount' => false
                ]],
            ],
            'all' => [
                'community' => (object)[
                    'metacommunity' => 'Berlin',
                    'state' => (object)[
                        'nodes' => 123,
                    ],
                ],
                'communityData' => $communityData1,
                'expected' => ['my town' => [
                    'metacommunity' => 'Berlin',
                    'claimed_nodecount' => 123
                ]],
            ],
        ];
    }
}
