<?php
namespace ffmap\parser;

use ffmap;

class Base
{
    /**
     * @var ffmap\CommunityCacheHandler
     */
    protected ffmap\CommunityCacheHandler $communityCacheHandler;

    /**
     * @var ffmap\CurlHelper
     */
    protected ffmap\CurlHelper $curlHelper;

    /**
     * @var ffmap\CommunityDebug
     */
    protected ffmap\CommunityDebug $communityDebug;

    /**
     * @var array
     */
    protected array $currentParseObject;

    /**
     * @var array
     */
    protected array $perNodeCallback;

    protected int $maxAge = 3;

    /**
     * ParserNodeList constructor.
     * @param ffmap\CommunityCacheHandler $cache
     * @param ffmap\CurlHelper $curlHelper
     * @param ffmap\CommunityDebug $communityDebug
     * @param array $callback
     */
    public function __construct(
        ffmap\CommunityCacheHandler $cache,
        ffmap\CurlHelper $curlHelper,
        ffmap\CommunityDebug $communityDebug,
        array $callback
    ) {
        $this->communityCacheHandler = $cache;
        $this->curlHelper = $curlHelper;
        $this->communityDebug = $communityDebug;
        $this->perNodeCallback= $callback;
    }

    /**
     * @param int $maxAge
     */
    public function setMaxAge(int $maxAge)
    {
        $this->maxAge = $maxAge;
    }
}
