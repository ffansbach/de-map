<?php

class nodeListParser
{
	private $_sourceUrl = '';

	private $_cachePath = '';

	/**
	 * timeout for result-cache
	 *
	 * 24h
	 * this is for the parsed result
	 *
	 * @var integer
	 */
	private $_cacheTime = 86400;

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

	public function __construct()
	{

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
		$filename = $this->_cachePath.'result_'.$key.'.cache';
		$changed = file_exists($filename) ? filemtime($filename) : 0;
		$now = time();
		$diff = $now - $changed;

		if ( !$changed || ($diff > $this->_cacheTime) )
		{
			return false;
		}
		else
		{
			return unserialize(file_get_contents($filename));
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
		$filename = $this->_cachePath.'result_'.$key.'.cache';
		$cache = fopen($filename, 'wb');
		$write = fwrite($cache, serialize($data));
		fclose($cache);

		return ($write == true);
	}

	/*****************************
	 * parsing
	 */

	private function _getCommunityList()
	{
		$result = simpleCachedCurl($this->_sourceUrl, $this->_curlCacheTime);
		$communityList = json_decode($result);

		return $communityList;
	}

	public function _getCommunityData($cUrl)
	{
		$communityFile = simpleCachedCurl($cUrl, $this->_curlCacheTime);

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
	 * parse all communities found in the api-file
	 */
	private function _parseList()
	{
		// used to prevent duplicates
		$parsedSources = array();
		$communityList = $this->_getCommunityList();

		foreach($communityList AS $cName => $cUrl)
		{
			$this->_log('------');
			$this->_log('parsing '.$cName." ".$cUrl);
			$this->_currentParseObject['name'] = $cName;
			$this->_currentParseObject['source'] = $cUrl;

			$this->_addCommunityMessage('start parsing');

			$communityData = $this->_getCommunityData($cUrl);

			if($communityData == false)
			{
				$this->_addCommunityMessage('got no data');
				continue;
			}

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
				$cName = $communityData->metacommunity;
				$communityName = $communityData->metacommunity;

				$this->_addCommunityMessage('Metacommunity:' . $communityData->metacommunity);
			}

			$thisComm = array('name' => $communityName, 'url' => $communityData->url);

			if(!json_encode($thisComm))
			{
				$this->_addCommunityMessage('name or url corrupt - ignoring');
				// error in some data - ignore community
				continue;
			}

			$this->_communityList[$cName] = $thisComm;

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
					elseif($nmEntry->technicalType == 'ffmap')
					{
						$this->_addCommunityMessage('parse as ffmap');
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
						break;
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
			$thisComm = array('name' => $community['name'], 'url' => $community['url']);

			if(!json_encode($thisComm))
			{
				// error in some data - ignore community
				continue;
			}

			$this->_communityList[$cName] = $thisComm;

			$data = $this->_getFromNetmon($cName, $community['url']);

			if($data !== false)
			{
				// found something
				$parsedSources[] = $community['url'];
				break;
			}
		}
	}

	private function _getFromNetmon($comName, $comUrl)
	{
		$url = rtrim($comUrl, '/').'/api/rest/api.php';
		$url .= '?'.http_build_query(
						array(
							'rquest' => 'routerlist',
							'limit' => 1000,			// one day this will be not enough - TODO. add loop
							'sort_by' => 'router_id'
						)
				);

		$result = simpleCachedCurl($url, $this->_curlCacheTime);

		if(!$result)
		{
			$this->_addCommunityMessage($url.' returns no result');
			return false;
		}

		$xml = simplexml_load_string($result, 'SimpleXMLElement');

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

		foreach($routers AS $router)
		{
			if($router->latitude == '0' || $router->longitude == '0'
				|| empty($router->latitude) || empty($router->longitude))
			{
				// router has no location
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

			// add to routerlist for later use in JS
			$this->_addOrForget($thisRouter);
		}
	}

	private function _getFromFfmap($comName, $comUrl)
	{
		$comUrl .= 'nodes.json';

		$result = simpleCachedCurl($comUrl, $this->_curlCacheTime);

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

		foreach($routers AS $router)
		{
			$counter++;

			if(!empty($router->location))
			{
				// new style
				if(empty($router->location->latitude) || empty($router->location->longitude))
				{
					// router has no location
					$skipped++;
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

				$thisRouter = array(
					'id' => (string)$router->name,
					'lat' => (string)$router->geo[0],
					'long' => (string)$router->geo[1],
					'name' => (string)$router->name,
					'community' => $comName,
					'status' => $router->flags->online ? 'online' : 'offline',
					'clients' => (int)$router->clientcount
				);
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
										$duplicates.' duplicates');
	}

	private function _getFromOwm($comName, $comUrl)
	{
		$comUrl .= 'api/view_nodes';
		$comUrl = str_replace('www.', '', $comUrl);

		$result = simpleCachedCurl($comUrl, $this->_curlCacheTime);

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

		foreach($routers AS $router)
		{
			if(empty($router->value->latlng[0]) || empty($router->value->latlng[1]))
			{
				// router has no location
				continue;
			}

			$thisRouter = array(
				'id' => (string)$router->id,
				'lat' => (string)$router->value->latlng[0],
				'long' => (string)$router->value->latlng[1],
				'name' => (string)$router->value->hostname,
				'community' => $comName,
				'status' => '?',
				'clients' => '?'
			);

			// add to routerlist for later use in JS
			$this->_addOrForget($thisRouter);
		}
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

		//if(!in_array($key, $this->_nodeListHashes))
		if(!isset($this->_nodeListHashes[$key]))
		{
			array_push($this->_nodeList, $node);
			//$this->_nodeListHashes[] = $key;
			$this->_nodeListHashes[$key] = $this->_currentParseObject['name'];

			return true;
		}

		// add this if info about duplication-source is needed
		//$this->_addCommunityMessage(' dupplicate - source: '.$this->_nodeListHashes[$key]);

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
}
