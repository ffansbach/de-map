<?php

namespace ffmap;

class NodeListParser
{
    private string $sourceUrl = '';

    private string $cachePath = '';

    /**
     * timeout for result-cache
     *
     * 365d
     * this is for the parsed result
     * If the result is older, we force a reparse.
     * This usually should not happen on a normal map-view => the long timeout
     *
     * @var int
     */
    private int $cacheTime = 31536000;

    private array $additionals = array();

    private array $nodeList = array();
    private array $nodeListHashes = array();

    private array $communityList = array();

    private $logFile = null;

    private array $parseStatistics = array(
        'errorCommunities' => array()
    );

    private array $currentParseObject = array(
        'name' => '',
        'source' => ''
    );

    private int $maxAge = 3;

    private array $urlBlackList = array('http://map.freifunk-ruhrgebiet.de/nodes.json');

    /**
     * all communities that delivered a parsable nodelist
     *
     * If a valid nodelist has been found for a community, there will be no
     * further parseatempts for this comm. - we will not try to load netmon or ffmap
     * for those.
     *
     * @var array
     */
    private array $nodelistCommunities = array();

    /**
     * @var CommunityCacheHandler
     */
    private CommunityCacheHandler $CommunityCacheHandler;

    /**
     * @var CurlHelper
     */
    private CurlHelper $curlHelper;

    /**
     * @var CommunityDebug
     */
    private CommunityDebug $communityDebug;

    /**
     * NodeListParser constructor.
     * @param CommunityCacheHandler $cache
     * @param CurlHelper $curlHelper
     */
    public function __construct(CommunityCacheHandler $cache, CurlHelper $curlHelper)
    {
        $this->CommunityCacheHandler = $cache;
        $this->curlHelper = $curlHelper;
        $this->parseStatistics['timestamp'] = date('c');
    }

    public function __destruct()
    {
        if (!empty($this->logFile)) {
            fclose($this->logFile);
        }
    }

    /**
     * @param CommunityDebug $communityDebug
     */
    public function setCommunityDebug(CommunityDebug $communityDebug)
    {
        $this->communityDebug = $communityDebug;
    }

    /**
     * The source url for the community API-file
     *
     * @param string $url
     */
    public function setSource(string $url)
    {
        $this->sourceUrl = $url;
    }

    public function setCachePath($path)
    {
        $this->cachePath = $path;
        $this->prepareLogFile();
    }

    public function addAdditional($key, $item)
    {
        $this->additionals[$key] = $item;
    }

    /**
     * returns all node-date
     *
     * this will check the cache and - if needed -
     * trigger a new parse of the api-files
     *
     * @param bool $force force reparse if true
     * @return mixed
     */
    public function getParsed($force = false): array
    {
        if ($force === true) {
            $this->cacheTime = 0;
        }

        $routerList = $this->fromCache('routers');
        $communities = $this->fromCache('communities');

        if ($routerList == false) {
            $this->log('need to reparse');
            $this->parseList();
            $this->log('parseList done');

            $routerList = $this->nodeList;
            $communities = $this->communityList;

            $this->toCache('routers', $this->nodeList);
            $this->toCache('communities', $this->communityList);

            $this->parseStatistics['errorCommunities'] = $this->communityDebug->getDebugLog();
            $this->toCache('statistics', $this->parseStatistics);
        } else {
            $this->log('using cached result');
        }

        return [
            'routerList' => $routerList,
            'communities' => $communities,
        ];
    }

    /*****************************
     * CACHE
     */

    /**
     * get content by key from cache
     *
     * this will fetch the file only if older than $this->_cacheTime
     * or there is no chached file
     *
     * @param string $key
     * @return mixed|false
     */
    private function fromCache(string $key)
    {
        $filename = $this->cachePath . 'result_' . $key . '.json';
        $changed = file_exists($filename) ? filemtime($filename) : 0;
        $now = time();
        $diff = $now - $changed;

        if (!$changed || ($diff > $this->cacheTime)) {
            return false;
        } else {
            return json_decode(file_get_contents($filename));
        }
    }

    /**
     * put something in the filecache
     * @param string $key
     * @param mixed $data
     * @return bool
     */
    private function toCache(string $key, $data): bool
    {
        $this->log('writing cache for ' . $key, false);
        $filename = $this->cachePath . 'result_' . $key . '.json';
        $cache = fopen($filename, 'wb');
        $write = fwrite($cache, json_encode($data));
        fclose($cache);

        return ($write == true);
    }

    /*****************************
     * parsing
     */

    /**
     * @return mixed
     */
    private function getCommunityList()
    {
        $result = $this->curlHelper->doCall($this->sourceUrl);
        return json_decode($result);
    }

    /**
     * @param string $cUrl
     * @param string $cName
     * @return false|mixed
     */
    public function getCommunityData(string $cUrl, string $cName)
    {
        $cacheTimeout = '-1 day';
        $communityData = $this->CommunityCacheHandler->readCache(
            $cName,
            'communityFile',
            $cacheTimeout
        );

        if ($communityData) {
            $this->log('using cached community data', false);
        } else {
            $communityFile = $this->curlHelper->doCall($cUrl);

            if ($communityFile) {
                $communityData = json_decode($communityFile);

                if ($communityData) {
                    $this->log('caching community data', false);
                    $this->CommunityCacheHandler->storeCache(
                        $cName,
                        'communityFile',
                        $communityData
                    );
                    return $communityData;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }

        // return cache;
        return $communityData;
    }

    /**
     * @param $url
     * @return false|string
     */
    private function getUrl($url)
    {
        $urlParts = parse_url($url);

        if (isset($urlParts['path'])) {
            $arr = explode('/', $urlParts['path']);

            if (sizeof($arr) > 1) {
                if (strpos($arr[sizeof($arr) - 1], '.') !== false) {
                    $arr[sizeof($arr) - 1] = '';
                }

                $arr = array_filter($arr);
                $urlParts['path'] = implode('/', $arr);
            }
        }

        if (empty($urlParts['host']) && empty($urlParts['path'])) {
            return false;
        } else {
            if (empty($urlParts['scheme'])) {
                $urlParts['scheme'] = 'http';
            }

            $preparedUrl = $urlParts['scheme'] . '://';

            if (!empty($urlParts['host'])) {
                $preparedUrl .= $urlParts['host'] . '/';
            }

            if (!empty($urlParts['path'])) {
                $preparedUrl .= $urlParts['path'] . '/';
            }
        }

        return $preparedUrl;
    }

    /**
     * search for and parse nodelists
     *
     * This looks for [nodeMaps] in format json - nodelist and parses them.
     * This format takes priority over all other nodeMaps
     *
     * @param object $communityList all communities
     * @return void
     */
    private function parseNodeLists(object $communityList)
    {
        $parsedSources = array();

        $parserNodeList = new parser\NodeList(
            $this->CommunityCacheHandler,
            $this->curlHelper,
            $this->communityDebug,
            [$this, 'addOrForget']
        );

        $parserNodeList->setMaxAge($this->maxAge);
        $this->parserNodeList = $parserNodeList;
        $totalCommunityCount = count((array)$communityList);
        $countIndex = 0;

        foreach ($communityList as $cName => $cUrl) {
            $countIndex++;
            $this->log('Step 1 of 3 - ' . $countIndex . ' / ' . $totalCommunityCount, false);
            $this->log('parseNodeLists ' . $cName);

            $this->currentParseObject['name'] = $cName;
            $this->currentParseObject['source'] = $cUrl;

            $communityData = $this->getCommunityData($cUrl, $cName);

            if ($communityData == false) {
                $this->addCommunityMessage('got no data');
                continue;
            }

            $this->addCommunityMessage('try to find nodeMaps containing [nodeList]-Format');
            $this->communityDebug->addBasicLogInfo($communityData, $this->currentParseObject);

            if (!isset($communityData->nodeMaps)) {
                $this->addCommunityMessage('└ has no nodeMaps at all!');
                continue;
            }

            $hasNodeList = false;

            // iterate over all nodeMaps-entries and look for nodelist
            foreach ($communityData->nodeMaps as $nmEntry) {
                if (isset($nmEntry->technicalType)
                    && isset($nmEntry->url)
                    && $nmEntry->technicalType == 'nodelist'
                ) {
                    // we found one
                    $hasNodeList = true;

                    $this->addCommunityMessage('├ found: ' . $nmEntry->url);

                    if (in_array($nmEntry->url, $parsedSources)) {
                        // already parsed ( meta community?)
                        $this->addCommunityMessage('│├ already parsed - skipping - ' . $nmEntry->url);
                        continue;
                    }

                    $this->addCommunityMessage('│├ parse as "nodeList"');
                    $data = $parserNodeList->getFrom($this->currentParseObject, $nmEntry->url);

                    if ($data) {
                        $communityData->shortName = $cName;
                        $this->addCommunity($communityData);
                    }
                }
            }

            if (!$hasNodeList) {
                $this->addCommunityMessage('└ has no "technicalType": "nodeList"');
            } else {
                $this->addCommunityMessage('└ finished prioritized "nodelist" parsing');
            }
        }

        $this->nodelistCommunities = $parserNodeList->getNodelistCommunities();
    }

    /**
     * parse all communities found in the api-file
     */
    private function parseList()
    {
        // used to prevent duplicates
        $parsedSources = array();
        $this->log('getCommunityList');
        $communityList = $this->getCommunityList();

        /* first try to parse the nodelist-format
         * we would prefer to use this.
         * any community that delivers a nodelist will be skipped in the next step
         */
        $this->log('_parseNodeLists');
        $this->parseNodeLists($communityList);

        $totalCommunityCount = count((array)$communityList);
        $countIndex = 0;

        foreach ($communityList as $cName => $cUrl) {
            $countIndex++;
            $this->log('Step 2 of 3 - ' . $countIndex . ' / ' . $totalCommunityCount, false);
            $this->log('parsing ' . $cName . " " . $cUrl);
            $this->currentParseObject['name'] = $cName;
            $this->currentParseObject['source'] = $cUrl;

            $this->addCommunityMessage('start checking for usable nodemaps');

            // check if community has delivered a nodelist
            if (in_array($cName, $this->nodelistCommunities)) {
                $this->addCommunityMessage('skipping - we already have found a nodeList');
                $this->addCommunityMessage('This is good! nodeLists have priority over other map types.');
                $this->addCommunityMessage('Since we already found nodes for this community, no further search is required.');
                continue;
            }

            $communityData = $this->getCommunityData($cUrl, $cName);

            if ($communityData == false) {
                $this->addCommunityMessage('got no data');
                continue;
            }

            $this->addCommunityMessage('try to find nodeMaps containing any parsable Format');
            $this->communityDebug->addBasicLogInfo($communityData, $this->currentParseObject);

            if (!isset($communityData->nodeMaps)) {
                $this->addCommunityMessage('has no nodeMaps');
                continue;
            }

            // this community belongs to a meta-community.
            if (isset($communityData->metacommunity)) {
                $this->addCommunityMessage('Metacommunity:' . $communityData->metacommunity);
            }

            $communityData->shortName = $cName;

            $this->addCommunity($communityData);

            $data = false;

            $cachedNodeSource = $this->CommunityCacheHandler->readCache($cName, 'nodeSource', '-1 day');

            if ($cachedNodeSource != false) {
                $cachedUrl = $cachedNodeSource->url;
                $cachedType = $cachedNodeSource->type;

                $this->addCommunityMessage('found cached url "'.$cachedUrl
                    .'" of type "'.$cachedType.'" less than one day old');

                if (in_array($cachedUrl, $parsedSources)) {
                    // already parsed ( meta community?)
                    $this->addCommunityMessage('already parsed - skipping - ' . $cachedUrl);
                    continue;
                }

                if (in_array(strtolower($cachedType), ['netmon', 'owm'])) {
                    // todo - this may be removed once cached is free of those
                    $this->addCommunityMessage(
                        '│└  Parser for '.$cachedType.' has been removed. '
                        .'As of Dec 2020 it was not in active use.'
                    );
                } elseif (strtolower($cachedType) == 'nodelist') {
                    $this->addCommunityMessage('│├ type nodelist - has already been parsed');
                } else {
                    $parser = "getFrom" . $cachedType;
                    $data = $this->{$parser}($cName, $cachedUrl);

                    if ($data !== false) {
                        // found something
                        $parsedSources[] = $cachedUrl;
                    }
                }
            }

            foreach ($communityData->nodeMaps as $nmEntry) {
                if (isset($nmEntry->technicalType)
                    && isset($nmEntry->url)
                ) {
                    $this->addCommunityMessage('├ found "'.$nmEntry->url.'" of type "'.$nmEntry->technicalType.'"');
                    $url = $this->getUrl($nmEntry->url);

                    if (!$url) {
                        // no usable url ignore this entry
                        $this->addCommunityMessage('│└ url broken');
                        continue;
                    }

                    if (in_array($url, $parsedSources)) {
                        // already parsed ( meta community?)
                        $this->addCommunityMessage('│├ already parsed - skipping - ' . $url);
                        $this->addCommunityMessage('│└ this happens if multiple communities use the same map url.');
                        continue;
                    }

                    $this->addCommunityMessage('│├ try to find parser for: ' . $url);

                    if ($nmEntry->technicalType == 'netmon') {
                        $this->addCommunityMessage(
                            '│└  Parser for netmon has been removed. '
                            .'As of Dec 2020 it was not in active use.'
                        );
                    } elseif (in_array($nmEntry->technicalType, ['ffmap', 'meshviewer', 'hopglass'])) {
                        if (preg_match('/\.json$/', $nmEntry->url)) {
                            $url = $nmEntry->url;
                        }

                        $this->addCommunityMessage('│├ parse as ffmap/meshviewer');
                        $data = $this->getFromFfmap($cName, $url);

                        if ($data !== false) {
                            $this->CommunityCacheHandler->storeCache(
                                $cName,
                                'nodeSource',
                                (object) [
                                    'url' => $url,
                                    'type' => 'Ffmap',
                                ]
                            );
                        }
                    } elseif ($nmEntry->technicalType == 'openwifimap') {
                        $this->addCommunityMessage(
                            '│└  Parser for openwifimap has been removed. '
                            .'As of Dec 2020 it was not in active use.'
                        );
                    } elseif ($nmEntry->technicalType == 'nodelist') {
                        $this->addCommunityMessage('│├ type nodelist - has already been parsed');
                    } else {
                        $this->addCommunityMessage('│├ no parser for: ' . $nmEntry->technicalType);
                    }

                    if ($data !== false) {
                        // found something
                        $parsedSources[] = $url;
                        break;
                    }
                } else {
                    $this->addCommunityMessage('│└ url or type missing');
                }
            }

            if ($data === false) {
                $this->addCommunityMessage('└ no parsable nodeMap found');
            } else {
                $this->addCommunityMessage('└ parse nodeMap done');
            }
        }


        $totalCommunityCount = count((array)$this->additionals);
        $countIndex = 0;

        foreach ($this->additionals as $cName => $community) {
            $countIndex++;
            $this->log('Step 3 of 3 - ' . $countIndex . ' / ' . $totalCommunityCount, false);
            $this->log('parse fixed community ' . $cName);
            $this->currentParseObject['name'] = $cName;
            $this->currentParseObject['source'] = $community->url;

            $community->shortName = $cName;
            $this->addCommunity($community);

            if (strtolower($community->parser) == 'nodelist') {
                $data = $this->parserNodeList->getFrom($this->currentParseObject, $community->url);
            } else {
                $parser = "getFrom" . $community->parser;
                $data = $this->{$parser}($cName, $community->url);
            }

            if ($data !== false) {
                // found something
                $parsedSources[] = $community->url;
            }
        }
    }

    /**
     * adds a community with name and url to the communitylist for the result
     *
     * @param object $community
     * @return bool
     */
    private function addCommunity(object $community): bool
    {
        $thisComm = array(
            'name' => $community->name,
            'url' => $community->url,
            'meta' => false,
        );

        if (isset($community->homePage)) {
            $thisComm['url'] = $community->homePage;
        }

        // add metacommunity if set
        if (isset($community->metacommunity)) {
            $thisComm['meta'] = $community->metacommunity;
        }

        if (!json_encode($thisComm)) {
            $this->addCommunityMessage('name or url corrupt - ignoring');
            // error in some data - ignore community
            return false;
        }

        $this->communityList[$community->shortName] = $thisComm;
        return true;
    }

    /**
     * @param string $comName
     * @param string $comUrl
     * @return array[]
     */
    private function getNodesFromCachedFfmapUrl(string $comName, string $comUrl)
    {
        $routers = [];
        // try readying cache
        $this->addCommunityMessage('│├ checking for cached ffmap/meshviewer source URLs');
        $cachedValidSourceUrl = $this->CommunityCacheHandler->readCache(
            $comName,
            'ffmapWorkingUrl'.md5($comUrl),
            '-7 days'
        );

        if ($cachedValidSourceUrl !== false) {
            if (is_string($cachedValidSourceUrl->resultUrl)) {
                $cachedValidSourceUrl->resultUrl = [$cachedValidSourceUrl->resultUrl];
            }

            foreach ($cachedValidSourceUrl->resultUrl as $url) {
                $this->addCommunityMessage('│├ cache-entry found: ' . $url);
                do {
                    $result = $this->curlHelper->doCall($url);

                    if (!$result) {
                        $this->addCommunityMessage('│└ ' . $url . ' returns no result');
                        break;
                    }

                    $responseObject = json_decode($result);

                    if (!$responseObject) {
                        $this->addCommunityMessage('│└ ' . $url . ' returns no valid json');
                        break;
                    }

                    $routers = array_merge($routers, (array)$responseObject->nodes);

                    if (!$routers) {
                        $this->addCommunityMessage('│└ ' . $url . ' contains no nodes');
                        break;
                    }

                    $this->addCommunityMessage('│├ ' . $url . ' returned something usable from cache');
                } while (false);
            }
        }

        if (empty($routers)) {
            $this->CommunityCacheHandler->clearCacheEntry($comName, 'ffmapWorkingUrl'.md5($comUrl));
        }

        return $routers;
    }

    /**
     * @param string $comName
     * @param string $comUrl
     * @return bool
     */
    private function getFromFfmap(string $comName, string $comUrl): bool
    {
        $urls = [
            'options' => [],
            'must_check' => [],
        ];

        $routers = false;

        $this->getNodesFromCachedFfmapUrl($comName, $comUrl);

        $gotResult = !empty($routers);

        if (!$gotResult) {
            $gotResult = false;

            if (!preg_match('/\.json$/', $comUrl)) {
                // try to get config.json
                $configUrl = $comUrl . '/config.json';
                $this->addCommunityMessage('Looking for config.json at ' . $configUrl);
                $configResult = $this->curlHelper->doCall($configUrl);

                if (!$configResult) {
                    $this->addCommunityMessage($configUrl . ' returns no result');
                } else {
                    $responseObject = json_decode($configResult);

                    if (!$responseObject) {
                        $this->addCommunityMessage($configUrl . ' returns no valid json');
                    } elseif (empty($responseObject->dataPath)) {
                        $this->addCommunityMessage($configUrl . ' contains no dataPath');
                    } else {
                        if (!is_array($responseObject->dataPath)) {
                            $responseObject->dataPath = array($responseObject->dataPath);
                        } else {
                            $this->addCommunityMessage('this seems to be a HopGlass-config');
                        }

                        foreach ($responseObject->dataPath as $path) {
                            $path = $path . 'nodes.json';

                            if (!preg_match('/https?:/', $path)) {
                                $parts = parse_url($comUrl);
                                $path = $parts['scheme'] . '://' . $parts['host'] . '/' . ltrim($path, '/');
                            }

                            $this->addCommunityMessage('adding dataPath:' . $path . ' to url-list');
                            $urls['must_check'][] = $path;
                        }
                    }
                }

                $urls['options'][] = $comUrl . 'nodes.json';
                $urls['options'][] = $comUrl . 'data/nodes.json';
                $urls['options'][] = $comUrl . 'json/nodes.json';
                $urls['options'][] = $comUrl . 'meshviewer/data/meshviewer.json';
                $urls['options'][] = $comUrl . 'data/meshviewer.json';

                if (preg_match('/\/meshviewer\//', $comUrl)) {
                    $comUrl = str_replace('/meshviewer', '', $comUrl);
                    $urls['options'][] = $comUrl . 'nodes.json';
                    $urls['options'][] = $comUrl . 'data/nodes.json';
                    $urls['options'][] = $comUrl . 'json/nodes.json';
                    $urls['options'][] = $comUrl . 'json/ffka/nodes.json';
                }
            }

            $urls['options'][] = $comUrl;
            $goodUrls = [];

            foreach ($urls['must_check'] as $urlTry) {
                $routersFound = $this->ffmapUrlCheck($urlTry);

                if (empty($routersFound)) {
                    // got nothing
                    continue;
                }

                $goodUrls[] = $urlTry;

                if (is_array($routers)) {
                    array_merge($routers, $routersFound);
                } else {
                    $routers = $routersFound;
                }
            }

            if (!empty($routers)) {
                $this->CommunityCacheHandler->storeCache(
                    $comName,
                    'ffmapWorkingUrl' . md5($comUrl),
                    (object)[
                        'resultUrl' => $goodUrls,
                        'mapUrl' => $comUrl
                    ]
                );

                // we have something to work with
                $gotResult = true;
            }

            if (!$gotResult) {
                foreach ($urls['options'] as $urlTry) {
                    $routers = $this->ffmapUrlCheck($urlTry);

                    if (empty($routers)) {
                        // got nothing
                        continue;
                    }

                    $this->CommunityCacheHandler->storeCache(
                        $comName,
                        'ffmapWorkingUrl' . md5($comUrl),
                        (object)[
                            'resultUrl' => [$urlTry],
                            'mapUrl' => $comUrl
                        ]
                    );

                    // we have something to work with
                    $gotResult = true;
                    break;
                }
            }
        }

        if (!$gotResult) {
            $this->addCommunityMessage('│└ sorry - found no nodes.json :-(');
            return false;
        }

        $this->addCommunityMessage('│├ found a nodes.json - start parsing');

        $counter = 0;
        $skipped = 0;
        $duplicates = 0;
        $added = 0;
        $dead = 0;

        foreach ($routers as $router) {
            $counter++;

            if (!empty($router->nodeinfo->location)) {
                // new style
                if (empty($router->nodeinfo->location->latitude)
                    || empty($router->nodeinfo->location->longitude)
                    || !is_numeric($router->nodeinfo->location->latitude)
                    || !is_numeric($router->nodeinfo->location->longitude)) {
                    // router has no location
                    $skipped++;
                    continue;
                }

                if (!$router->flags->online) {
                    $date = date_create((string)$router->lastseen);

                    // was online in last 24h ?
                    if ((time() - $date->getTimestamp()) > 60 * 60 * 24 * $this->maxAge) {
                        // router has been offline for a long time now
                        $dead++;
                        continue;
                    }
                }

                $thisRouter = [
                    'id' => (string)$router->nodeinfo->node_id,
                    'lat' => (string)$router->nodeinfo->location->latitude,
                    'long' => (string)$router->nodeinfo->location->longitude,
                    'name' => (string)$router->nodeinfo->hostname,
                    'community' => $comName,
                    'status' => $router->flags->online ? 'online' : 'offline',
                    'clients' => isset($router->statistics->clients)
                        ? $this->getClientCount($router->statistics->clients)
                        : 0,
                ];
            } elseif (!empty($router->location)) {
                // new style
                if (empty($router->location->latitude) || empty($router->location->longitude)) {
                    // router has no location
                    $skipped++;
                    continue;
                }

                if (isset($router->flags) && !$router->flags->online) {
                    // router is offline and we don't know how long - skip
                    $dead++;
                    continue;
                } elseif (isset($router->is_online)) {
                    if (!$router->is_online) {
                        // router is offline and we don't know how long - skip
                        $dead++;
                        continue;
                    }
                    $router->flags = new \stdClass();
                    $router->flags->online = true;
                }

                $thisRouter = [
                    'id' => (string)$router->node_id,
                    'lat' => (string)$router->location->latitude,
                    'long' => (string)$router->location->longitude,
                    'name' => (string)$router->hostname,
                    'community' => $comName,
                    'status' => $router->flags->online ? 'online' : 'offline',
                    'clients' => isset($router->clients) ? $this->getClientCount($router->clients) : 0,
                ];
            } else {
                // old style
                if (empty($router->geo[0]) || empty($router->geo[1])) {
                    // router has no location
                    $skipped++;
                    continue;
                }

                if (!$router->flags->online) {
                    // touter is offline and we don't know how long - skip
                    $dead++;
                    continue;
                }

                $thisRouter = array(
                    'id' => (string)$router->name,
                    'lat' => (string)$router->geo[0],
                    'long' => (string)$router->geo[1],
                    'name' => (string)$router->name,
                    'community' => $comName,
                    'status' => $router->flags->online ? 'online' : 'offline',
                    'clients' => 0
                );

                if (!empty($router->clientcount)) {
                    $thisRouter['clients'] = (int)$router->clientcount;
                }
            }

            // add to routerlist for later use in JS
            if ($this->addOrForget($thisRouter)) {
                $added++;
            } else {
                $duplicates++;
            }
        }

        $this->addCommunityMessage('│└ parsing done. ' .
            $counter . ' nodes found, ' .
            $added . ' added, ' .
            $skipped . ' skipped, ' .
            $duplicates . ' duplicates, ' .
            $dead . ' dead');

        return true;
    }

    /**
     * @param string $url
     * @return array|object
     */
    private function ffmapUrlCheck(string $urlTry)
    {
        $this->addCommunityMessage($urlTry . ' try to fetch');

        if (in_array($urlTry, $this->urlBlackList)) {
            $this->addCommunityMessage($urlTry . ' is blacklisted - skipping');
            return [];
        }

        $result = $this->curlHelper->doCall($urlTry);

        if (!$result) {
            $this->addCommunityMessage($urlTry . ' returns no result');
            return [];
        }

        $responseObject = json_decode($result);

        if (!$responseObject) {
            $this->addCommunityMessage($urlTry . ' returns no valid json');
            return [];
        }

        $routers = $responseObject->nodes;

        if (!$routers) {
            $this->addCommunityMessage($urlTry . ' contains no nodes');
            return [];
        }

        return $routers;
    }

    /**
     * @param $clients mixed[]|int
     * @return int
     */
    private function getClientCount($clients): int
    {
        $clientCount = 0;

        if (is_array($clients) or is_object($clients)) {
            if (is_countable($clients)) {
                $clientCount = sizeof($clients);
            } elseif (isset($clients->total)) {
                $clientCount = (int)$clients->total;
            }
        } elseif (is_numeric($clients)) {
            $clientCount = (int)$clients;
        }

        return $clientCount;
    }

    /**
     * add a node to the list or skip if it is already in the list
     *
     * a hash of name, id and location ist used for deduplication
     *
     * @param mixed[] $node
     * @return bool
     */
    public function addOrForget(array $node): bool
    {
        $identifier = $node['name'] . $node['id'];

        $key = md5($identifier . $node['lat'] . $node['long']);

        if (!isset($this->nodeListHashes[$key])) {
            array_push($this->nodeList, $node);
            $this->nodeListHashes[$key] = $this->currentParseObject['name'];

            return true;
        }

        return false;
    }

    /**
     * @return void
     */
    private function prepareLogFile()
    {
        $this->logFile = fopen($this->cachePath . "logfile.txt", "a");
    }

    /**
     * @param string $msg
     * @param bool $inlcudeStats
     * @return void
     */
    private function log(string $msg, bool $inlcudeStats = true)
    {
        echo date("d.m.Y, H:i:s", time()) . ' ' . $msg . "\n";

        if ($inlcudeStats) {
            echo 'MemoryUsage: ' . round(memory_get_peak_usage(true) / 1024 / 1024, 1) . "MB\n";
            echo "Found nodes: " . count($this->nodeList) . "\n\n";
        }

        flush();

        if ($this->logFile) {
            fputs(
                $this->logFile,
                date("d.m.Y, H:i:s", time()) . ' ' .
                $msg .
                "\n" .
                'total nodes found: ' . count($this->nodeList) .
                "\n"
            );
        }
    }

    /**
     * returns the array with info about the parseprocess
     * @return mixed[]
     */
    public function getParseStatistics(): array
    {
        return $this->parseStatistics;
    }

    /**
     * adds an message-entry for the current community
     * @param string $message
     */
    private function addCommunityMessage(string $message)
    {
        $this->communityDebug->addMessage($message, $this->currentParseObject);
    }
}
