<?php

class nodeListParser
{
	private $_sourceUrl = '';

	private $_cachePath = '';

	/**
	 * show debug-output
	 * @var boolean
	 */
	private $_debug = false;

	/**
	 * timeout for result-cache
	 *
	 * 365d
	 * this is for the parsed result
	 * If the result is older, we force a reparse.
	 * This usulally should not happen on a normal mapp-view => the long timeout
	 *
	 * @var integer
	 */
	private $_cacheTime = 31536000;

	/**
	 * cahcetime for curl
	 *
	 * @var integer
	 */
	private $_curlCacheTime = 86400;

	private $_additionals = array();

	private $_nodeList = array();
	private $_nodeListHashes = array();

	private $_communityList = array();

	private $_logfile = null;

	private $_parseStatistics = array(
		'errorCommunities' => array()
	);

	private $_currentParseObject = array(
		'name'	=> '',
		'source' => ''
	);

	private $_maxAge = 3;

	private $_urlBlackList = array('http://map.freifunk-ruhrgebiet.de/nodes.json');

	/**
	 * all communities that delivered a parsable nodelist
	 *
	 * If a valid nodelist has been found for a community, there will be no
	 * further parseatempts for this comm. - we will not try to load netmon or ffmap
	 * for those.
	 *
	 * @var array
	 */
	private $_nodelistCommunities = array();

	public function __construct()
	{
		$this->_parseStatistics['timestamp'] = date('c');
	}

	public function __destruct()
	{
		if(!empty($this->_logfile))
		{
			fclose($this->_logfile);
		}
	}

	public function setSource($url)
	{
		$this->_sourceUrl = $url;
	}

	/**
	 * (de)activate debug-output
	 * @param boolean $allowDebug
	 */
	public function setDebug($allowDebug)
	{
		$this->_debug = (bool)$allowDebug;
	}

	public function setCachePath($path)
	{
		$this->_cachePath = $path;
		$this->_prepareLogFile();
	}

	public function addAdditional($key, $item)
	{
		$this->_additionals[$key] = $item;
	}

	/**
	 * returns all node-date
	 *
	 * this will check the cache and - if needed -
	 * trigger a new parse of the api-files
	 *
	 * @param  boolean $force force reparse if true
	 * @return mixed
	 */
	public function getParsed($force = false)
	{
		if($force === true)
		{
			$this->_curlCacheTime = 0;
			$this->_cacheTime = 0;
		}

		$routerList = $this->_fromCache('routers');
		$communities = $this->_fromCache('communities');

		if($routerList == false)
		{
			$this->_log('need to reparse');
			$this->_parseList();

			$routerList = $this->_nodeList;
			$communities = $this->_communityList;

			$this->_toCache('routers', $this->_nodeList);
			$this->_toCache('communities', $this->_communityList);
			$this->_toCache('statistics', $this->_parseStatistics);
		}
		else
		{
			$this->_log('using cached result');
		}

		$response = array(
			'routerList' => $routerList,
			'communities' => $communities
		);

		return $response;
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
	 * @param  string $key
	 * @return mixed
	 */
	private function _fromCache($key)
	{
		$filename = $this->_cachePath.'result_'.$key.'.json';
		$changed = file_exists($filename) ? filemtime($filename) : 0;
		$now = time();
		$diff = $now - $changed;

		if ( !$changed || ($diff > $this->_cacheTime) )
		{
			return false;
		}
		else
		{
			return json_decode(file_get_contents($filename));
		}
	}

	/**
	 * put something in the filecaceh
	 * @param  string $key
	 * @param  mixed $data
	 * @return bool
	 */
	private function _toCache($key, $data)
	{
		$this->_log('writing cache for '.$key);
		$filename = $this->_cachePath.'result_'.$key.'.json';
		$cache = fopen($filename, 'wb');
		$write = fwrite($cache, json_encode($data));
		fclose($cache);

		return ($write == true);
	}

	/*****************************
	 * parsing
	 */

	private function _getCommunityList()
	{
		$result = simpleCachedCurl($this->_sourceUrl, $this->_curlCacheTime, $this->_debug);
		$communityList = json_decode($result);

		return $communityList;
	}

	public function _getCommunityData($cUrl)
	{
		$communityFile = simpleCachedCurl($cUrl, $this->_curlCacheTime, $this->_debug);

		if($communityFile)
		{
			$communityData = json_decode($communityFile);

			if($communityData)
			{
				return $communityData;
			}
		}

		return false;
	}

	private function _getUrl($url)
	{
		$preparedUrl = false;

		$urlParts = parse_url($url);

		if(isset($urlParts['path']))
		{
			$arr = explode('/', $urlParts['path']);

			if(sizeof($arr) > 1)
			{
				if(strpos($arr[sizeof($arr)-1], '.') !== false)
				{
					$arr[sizeof($arr)-1] = '';
				}

				$arr = array_filter($arr);
				$urlParts['path'] = implode('/', $arr);
			}
		}

		if(empty($urlParts['host']) && empty($urlParts['path']))
		{
			// no useable path
		}
		else
		{
			if(empty($urlParts['scheme']))
			{
				$urlParts['scheme'] = 'http';
			}

			$preparedUrl = $urlParts['scheme'].'://';

			if(!empty($urlParts['host']))
			{
				$preparedUrl .= $urlParts['host'].'/';
			}

			if(!empty($urlParts['path']))
			{
				$preparedUrl .= $urlParts['path'].'/';
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
	 * @param  object $communityList all communities
	 * @return void
	 */
	private function _parseNodeLists($communityList)
	{
		$parsedSources = array();

		foreach($communityList AS $cName => $cUrl)
		{

			$this->_currentParseObject['name'] = $cName;
			$this->_currentParseObject['source'] = $cUrl;

			$communityData = $this->_getCommunityData($cUrl);

			if($communityData == false)
			{
				$this->_addCommunityMessage('got no data');
				continue;
			}

			$this->_addCommunityMessage('try to find nodeMaps containing [nodeList]-Format');
			$this->_addBasicLogInfo($communityData);

			if(!isset($communityData->nodeMaps))
			{
				$this->_addCommunityMessage('has no nodeMaps');
				continue;
			}

			$hasNodeList = false;

			// iterate over all nodeMaps-entries annd look for nodelist
			foreach($communityData->nodeMaps AS $nmEntry)
			{
				if(
					isset($nmEntry->technicalType)
					&&
					isset($nmEntry->url)
					&&
					$nmEntry->technicalType == 'nodelist'
				)
				{
					// we found one
					$hasNodeList = true;

					if(in_array($nmEntry->url, $parsedSources))
					{
						// already parsed ( meta community?)
						$this->_addCommunityMessage('already parsed - skipping - '.$url);
						continue;
					}

					$this->_addCommunityMessage('parse as [nodeList]');
					$data = $this->_getFromNodelist($cName, $nmEntry->url);

					if($data)
					{
						$communityData->shortName = $cName;
						$this->_addCommunity($communityData);
					}
				}
			}

			if(!$hasNodeList)
			{
				$this->_addCommunityMessage('has no [nodeList]');
			}
		}

	}

	/**
	 * parse all communities found in the api-file
	 */
	private function _parseList()
	{
		// used to prevent duplicates
		$parsedSources = array();
		$communityList = $this->_getCommunityList();

		/* first try to parse the nodelist-format
		 * we would prefere to use this.
		 * any community that delivers a nodelist will be skipped in the next step
		 */
		$this->_parseNodeLists($communityList);

		foreach($communityList AS $cName => $cUrl)
		{
			$this->_log('parsing '.$cName." ".$cUrl);
			$this->_currentParseObject['name'] = $cName;
			$this->_currentParseObject['source'] = $cUrl;

			// check if community has delivered a nodelist
			if(in_array($cName, $this->_nodelistCommunities))
			{
				$this->_addCommunityMessage('skipping - we already have found a nodeList');
				continue;
			}

			$this->_addCommunityMessage('start parsing');

			$communityData = $this->_getCommunityData($cUrl);

			if($communityData == false)
			{
				$this->_addCommunityMessage('got no data');
				continue;
			}

			$this->_addCommunityMessage('try to find nodeMaps containing any parseable Format');
			$this->_addBasicLogInfo($communityData);

			if(!isset($communityData->nodeMaps))
			{
				$this->_addCommunityMessage('has no nodeMaps');
				continue;
			}

			$communityName = $communityData->name;

			// this community belongs to a meta-community.
			// use meta-communityname
			if(isset($communityData->metacommunity))
			{
				$communityName = $communityData->metacommunity;

				$this->_addCommunityMessage('Metacommunity:' . $communityData->metacommunity);
			}

			$communityData->shortName = $cName;

			$this->_addCommunity($communityData);

			$data = false;

			foreach($communityData->nodeMaps AS $nmEntry)
			{

				if(
					isset($nmEntry->technicalType)
					&&
					isset($nmEntry->url)
				)
				{
					$url = $this->_getUrl($nmEntry->url);

					if(!$url)
					{
						// no usable url ignore this entry
						$this->_addCommunityMessage('url broken');
						continue;
					}

					if(in_array($url, $parsedSources))
					{
						// already parsed ( meta community?)
						$this->_addCommunityMessage('already parsed - skipping - '.$url);
						continue;
					}

					$this->_addCommunityMessage('try to find parser for: '.$url);

					if($nmEntry->technicalType == 'netmon')
					{
						$this->_addCommunityMessage('parse as netmon');
						$data = $this->_getFromNetmon($cName, $url);
					}
					elseif($nmEntry->technicalType == 'ffmap' || $nmEntry->technicalType == 'meshviewer')
					{
						if(preg_match('/\.json$/', $nmEntry->url))
						{
							$url = $nmEntry->url;
						}

						$this->_addCommunityMessage('parse as ffmap/meshviewer');
						$data = $this->_getFromFfmap($cName, $url);
					}
					elseif($nmEntry->technicalType == 'openwifimap')
					{
						$this->_addCommunityMessage('parse as openwifimap');
						$data = $this->_getFromOwm($cName, $url);
					}

					if($data !== false)
					{
						// found something
						$parsedSources[] = $url;
						// don't break here to enable more than one active map
					}
				}
				else
				{
					$this->_addCommunityMessage('url or type missing');
				}
			}

			if($data === false)
			{
				$this->_addCommunityMessage('no parseable nodeMap found');
			}
		}

		foreach($this->_additionals AS $cName => $community)
		{
			$this->_currentParseObject['name'] = $cName;
			$this->_currentParseObject['source'] = $community->url;

			$community->shortName = $cName;
			$this->_addCommunity($community);

			$parser = "_getFrom".$community->parser;

			$data = $this->{$parser}($cName, $community->url);

			if($data !== false)
			{
				// found something
				$parsedSources[] = $community->url;
			}
		}
	}

	/**
	 * adds a community with name and url to the communitylist for the result
	 *
	 * @param array $community
	 */
	private function _addCommunity($community)
	{
		$thisComm = array(
			'name' => $community->name,
			'url' => $community->url,
			'meta' => false,
		);

		if(isset($community->homePage))
		{
			$thisComm['url'] = $community->homePage;
		}

		// add metacommunity if set
		if(isset($community->metacommunity))
		{
			$thisComm['meta'] = $community->metacommunity;
		}

		if(!json_encode($thisComm))
		{
			$this->_addCommunityMessage('name or url corrupt - ignoring');
			// error in some data - ignore community
			return false;
		}

		$this->_communityList[$community->shortName] = $thisComm;
	}

	/**
	 * parse a nodelist-format
	 *
	 * @param string $comName Name of community
	 * @param string $comUrl url to fetch data from
	 */
	private function _getFromNodelist($comName, $comUrl)
	{
		$result = simpleCachedCurl($comUrl, $this->_curlCacheTime, $this->_debug);

		$responseObject = json_decode($result);

		if(!$responseObject)
		{
			$this->_addCommunityMessage($comUrl.' returns no valid json');
			return false;
		}

		$schemaString = file_get_contents(__DIR__.'/../schema/nodelist-schema-1.0.0.json');
		$schema = json_decode($schemaString);
		$validationResult = Jsv4::validate($responseObject, $schema);

		if(!$validationResult)
		{
			$this->_addCommunityMessage($comUrl.' is no valid nodelist');
			return false;
		}

		if(empty($responseObject->nodes))
		{
			$this->_addCommunityMessage($comUrl.' contains no nodes');
			return false;
		}

		$routers = $responseObject->nodes;

		// add community to the list of nodelist-communities
		// this will make us skipp further search for other formats
		$this->_nodelistCommunities[] = $comName;

		$counter = 0;
		$skipped = 0;
		$duplicates = 0;
		$added = 0;
		$dead = 0;

		foreach($routers AS $router)
		{
			$counter++;

			if(	empty($router->position->lat)
				||
				(
					empty($router->position->lon)
					&&
					empty($router->position->long)
				)
			)
			{
				// router has no location
				$skipped++;
				continue;
			}

			$thisRouter = array(
				'id' => (string)$router->id,
				'lat' => (string)$router->position->lat,
				'long' => (!empty($router->position->lon) ? (string)$router->position->lon : (string)$router->position->long),
				'name' => isset($router->name) ? (string)$router->name : (string)$router->id,
				'community' => $comName,
				'status' => 'unknown',
				'clients' => 0
			);

			if(isset($router->status))
			{
				if(isset($router->status->clients))
				{
					$thisRouter['clients'] = (int)$router->status->clients;
				}

				if(isset($router->status->online))
				{
					$thisRouter['status'] = (bool)$router->status->online ? 'online' : 'offline';
				}
			}


			if( $thisRouter['status'] == 'offline' )
			{
				$isDead = false;

				if(empty($router->status->lastcontact))
				{
					$isDead = true;
				}
				else
				{
					$date = date_create((string)$router->status->lastcontact);

					// was online in last days? ?
					$isDead = ((time() - $date->getTimestamp()) > 60*60*24*$this->_maxAge);
				}

				if($isDead)
				{
					$dead++;
					continue;
				}
			}

			// add to routerlist for later use in JS
			if($this->_addOrForget($thisRouter))
			{
				$added++;
			}
			else
			{
				$duplicates++;
			}
		}

		$this->_addCommunityMessage('parsing done. '.
										$counter.' nodes found, '.
										$added.' added, '.
										$skipped.' skipped, '.
										$duplicates.' duplicates, '.
										$dead.' dead');

		return true;
	}

	private function _getFromNetmon($comName, $comUrl)
	{
		$url = rtrim($comUrl, '/').'/api/rest/api.php';
		$url .= '?'.http_build_query(
						array(
							'rquest' => 'routerlist',
							'limit' => 3000,			// one day this will be not enough - TODO. add loop
							'sort_by' => 'router_id'
						)
				);

		$result = simpleCachedCurl($url, $this->_curlCacheTime, $this->_debug);

		if(!$result)
		{
			$this->_addCommunityMessage($url.' returns no result');
			return false;
		}

		$xml = @simplexml_load_string($result, 'SimpleXMLElement');

		if(!$xml)
		{
			$this->_addCommunityMessage($url.' returns no valid xml');
			return false;
		}

		$routers = $xml->routerlist->router;

		if(!$routers)
		{
			$this->_addCommunityMessage($url.' contains no nodes');
			return false;
		}

		$counter = 0;
		$skipped = 0;
		$duplicates = 0;
		$added = 0;
		$dead = 0;

		foreach($routers AS $router)
		{
			$counter++;

			if($router->latitude == '0' || $router->longitude == '0'
				|| empty($router->latitude) || empty($router->longitude))
			{
				// router has no location
				$skipped++;
				continue;
			}

			$thisRouter = array(
				'id' => (int)$router->router_id,
				'lat' => (string)$router->latitude,
				'long' => (string)$router->longitude,
				'name' => (string)$router->hostname,
				'community' => $comName,
				'status' => (string)$router->statusdata->status,
				'clients' => (int)$router->statusdata->client_count
			);

			if( $thisRouter['status'] == 'offline')
			{
				if(!empty($router->statusdata->last_seen))
				{
					// was online in last days?
					$date = date_create((string)$router->statusdata->last_seen);

					if( (time() - $date->getTimestamp()) > 60*60*24*$this->_maxAge)
					{
						$dead++;
						continue;
					}
				}
				else
				{
					// offline for unknown-time - skip
					$dead++;
					continue;
				}
			}

			// add to routerlist for later use in JS
			if($this->_addOrForget($thisRouter))
			{
				$added++;
			}
			else
			{
				$duplicates++;
			}
		}

		$this->_addCommunityMessage('parsing done. '.
										$counter.' nodes found, '.
										$added.' added, '.
										$skipped.' skipped, '.
										$duplicates.' duplicates, '.
										$dead.' dead');

		return true;
	}

	private function _getFromFfmap($comName, $comUrl)
	{
		if(!preg_match('/\.json$/', $comUrl))
		{
			$comUrl .= 'nodes.json';
		}
		if(in_array($comUrl, $this->_urlBlackList))
		{
			$this->_addCommunityMessage($comUrl.' is blacklisted - skipping');
			return false;
		}

		$result = simpleCachedCurl($comUrl, $this->_curlCacheTime, $this->_debug);

		if(!$result)
		{
			$this->_addCommunityMessage($comUrl.' returns no result');
			return false;
		}

		$responseObject = json_decode($result);

		if(!$responseObject)
		{
			$this->_addCommunityMessage($comUrl.' returns no valid json');
			return false;
		}

		$routers = $responseObject->nodes;

		if(!$routers)
		{
			$this->_addCommunityMessage($comUrl.' contains no nodes');
			return false;
		}

		$counter = 0;
		$skipped = 0;
		$duplicates = 0;
		$added = 0;
		$dead = 0;

		foreach($routers AS $router)
		{
			$counter++;

			if(!empty($router->nodeinfo->location))
			{
				// new style
				if(empty($router->nodeinfo->location->latitude) || empty($router->nodeinfo->location->longitude))
				{
					// router has no location
					$skipped++;
					continue;
				}

				if(!$router->flags->online)
				{
					$date = date_create((string)$router->lastseen);

					// was online in last 24h ?
					if( (time() - $date->getTimestamp()) > 60*60*24*$this->_maxAge)
					{
						// router has been offline for a long time now
						$dead++;
						continue;
					}
				}

				$thisRouter = array(
					'id' => (string)$router->nodeinfo->node_id,
					'lat' => (string)$router->nodeinfo->location->latitude,
					'long' => (string)$router->nodeinfo->location->longitude,
					'name' => (string)$router->nodeinfo->hostname,
					'community' => $comName,
					'status' => $router->flags->online ? 'online' : 'offline',
					'clients' => (int)$router->statistics->clients
				);
			}
			elseif(!empty($router->location))
			{
				// new style
				if(empty($router->location->latitude) || empty($router->location->longitude))
				{
					// router has no location
					$skipped++;
					continue;
				}

				if(!$router->flags->online)
				{
					// touter is offline and we don't know how long - skip
					$dead++;
					continue;
				}

				$thisRouter = array(
					'id' => (string)$router->node_id,
					'lat' => (string)$router->location->latitude,
					'long' => (string)$router->location->longitude,
					'name' => (string)$router->hostname,
					'community' => $comName,
					'status' => $router->flags->online ? 'online' : 'offline',
					'clients' => (int)sizeof($router->clients)
				);
			}
			else
			{
				// old style
				if(empty($router->geo[0]) || empty($router->geo[1]))
				{
					// router has no location
					$skipped++;
					continue;
				}

				if(!$router->flags->online)
				{
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

				if(!empty($router->clientcount))
				{
					$thisRouter['clients'] = (int)$router->clientcount;
				}
			}

			// add to routerlist for later use in JS
			if($this->_addOrForget($thisRouter))
			{
				$added++;
			}
			else
			{
				$duplicates++;
			}
		}

		$this->_addCommunityMessage('parsing done. '.
										$counter.' nodes found, '.
										$added.' added, '.
										$skipped.' skipped, '.
										$duplicates.' duplicates, '.
										$dead.' dead');

		return true;
	}

	private function _getFromOwm($comName, $comUrl)
	{
		$comUrl .= 'api/view_nodes';
		$comUrl = str_replace('www.', '', $comUrl);

		$result = simpleCachedCurl($comUrl, $this->_curlCacheTime, $this->_debug);

		if(!$result)
		{
			$this->_addCommunityMessage($comUrl.' returns no result');
			return false;
		}

		$responseObject = json_decode($result);

		if(!$responseObject)
		{
			$this->_addCommunityMessage($comUrl.' returns no valid json');
			return false;
		}

		$routers = $responseObject->rows;

		if(!$routers)
		{
			$this->_addCommunityMessage($comUrl.' contains no nodes');
			return false;
		}

		$counter = 0;
		$skipped = 0;
		$duplicates = 0;
		$added = 0;
		$dead = 0;

		foreach($routers AS $router)
		{
			if(empty($router->value->latlng[0]) || empty($router->value->latlng[1]))
			{
				// router has no location
				$skipped++;
				continue;
			}

			$date = date_create((string)$router->value->mtime);

			// was online in last 24h ?
			$isOnline = ((time() - $date->getTimestamp()) < 60*60*24);

			if( (time() - $date->getTimestamp()) > 60*60*24*$this->_maxAge)
			{
				// router has been offline for a long time now
				$dead++;
				continue;
			}

			$thisRouter = array(
				'id' => (string)$router->id,
				'lat' => (string)$router->value->latlng[0],
				'long' => (string)$router->value->latlng[1],
				'name' => (string)$router->value->hostname,
				'community' => $comName,
				'status' => $isOnline ? 'online' : 'offline',
				'clients' => '?'
			);

			// add to routerlist for later use in JS
			if($this->_addOrForget($thisRouter))
			{
				$added++;
			}
			else
			{
				$duplicates++;
			}
		}

		$this->_addCommunityMessage('parsing done. '.
										$counter.' nodes found, '.
										$added.' added, '.
										$skipped.' skipped, '.
										$duplicates.' duplicates, '.
										$dead.' dead');

		return true;
	}

	/**
	 * add a node to the list or skip if it is allready in the list
	 *
	 * a hash of name, id and location ist used for deduplication
	 *
	 * @param mixed[] $node
	 * @return boolean
	 */
	private function _addOrForget($node)
	{
		$key = md5($node['name'].$node['id'].$node['lat'].$node['long']);

		if(!isset($this->_nodeListHashes[$key]))
		{
			array_push($this->_nodeList, $node);
			$this->_nodeListHashes[$key] = $this->_currentParseObject['name'];

			return true;
		}

		return false;
	}

	private function _prepareLogFile()
	{
		$this->_logfile = fopen($this->_cachePath."logfile.txt","a");
	}

	private function _log($msg)
	{
		fputs(
			$this->_logfile,
    		date("d.m.Y, H:i:s",time()).' '.
    		$msg.
    		"\n"
    	);
	}

	/**
	 * returns the array with info about the parseprocess
	 * @return mixed[]
	 */
	public function getParseStatistics()
	{
		return $this->_parseStatistics;
	}

	/**
	 * adds an message-entry for the current community
	 * @param string $message
	 */
	private function _addCommunityMessage($message)
	{
		if(!isset($this->_parseStatistics['errorCommunities'][$this->_currentParseObject['name']]))
		{
			$this->_parseStatistics['errorCommunities'][$this->_currentParseObject['name']] = array(
				'name' => $this->_currentParseObject['name'],
				'apifile' => $this->_currentParseObject['source'],
				'message' => array()
			);
		}

		$this->_parseStatistics['errorCommunities'][$this->_currentParseObject['name']]['message'][] = $message;
	}

	/**
	 * adds some basic information from the communityfile to the loging/debug-object
	 *
	 * @param simpleXML $community
	 */
	private function _addBasicLogInfo($community)
	{
		$statisticsNode = &$this->_parseStatistics['errorCommunities'][$this->_currentParseObject['name']];
		$statisticsNode['claimed_nodecount'] = false;

		if(!empty($community->state) && !empty($community->state->nodes))
		{
			$statisticsNode['claimed_nodecount'] = (int)$community->state->nodes;
		}

		if(isset($community->metacommunity))
		{
			$statisticsNode['metacommunity'] = $community->metacommunity;
		}
	}
}
