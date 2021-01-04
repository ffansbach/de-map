<?php
/*
simpleCachedCurl V1.1

Dirk Ginader
http://ginader.com

Copyright (c) 2013 Dirk Ginader

Dual licensed under the MIT and GPL licenses:
http://www.opensource.org/licenses/mit-license.php
http://www.gnu.org/licenses/gpl.html

code: http://github.com/ginader

easy to use cURL wrapper with added file cache

usage: created a folder named "cache" in the same folder as this file and chmod it 777
call this function with 3 parameters:
	$url (string) the URL of the data that you would like to load
	$expires (integer) the amound of seconds the cache should stay valid
	$debug (boolean, optional) write debug information for troubleshooting

returns either the raw cURL data or false if request fails and no cache is available

*/
function simpleCachedCurl($url,$expires,$debug=false)
{
	if($debug)
	{
		echo "simpleCachedCurl debug:\n";
		echo "$url\n";
	}

	$hash = md5($url);
	$filename = dirname(__FILE__).'/../cache/' . $hash . '.cache';
	$exists = file_exists($filename);
	$changed = $exists ? filemtime($filename) : 0;
	$now = time();
	$diff = $now - $changed;

	if($debug)
	{
		if($exists)
		{
			echo "File Exists! time: ".$changed."\n";
			echo "diff: ".$diff."\n";
		}
		else
		{
			echo "no cached File\n";
		}
	}

	$returnValue = false;

	if ( !$changed || ($diff > $expires) )
	{
		if($debug)
		{
			echo "no cache or expired --> make new request\n";
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_VERSION_IPV6);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_USERAGENT,'php parser for http://de-map.freifunk-emskirchen.de/');
		$rawData = curl_exec($ch);

		$status = curl_getinfo($ch);

		if($status['http_code'] == 301 || $status['http_code'] == 302)
		{
			$headerData = _curlGetHeader($url);
			list($header) = explode("\r\n\r\n", $headerData, 2);
			$matches = array();
			preg_match("/(Location:|URI:)[^(\n)]*/", $header, $matches);
			$url = trim(str_replace($matches[1],"",$matches[0]));
			$url_parsed = parse_url($url);
			return (isset($url_parsed))? simpleCachedCurl($url, $expires, $debug):'';
		}

		if(!$rawData)
		{
			if($debug)
			{
				echo "cURL request failed\n";
			}

			if($changed)
			{
				if($debug)
				{
					echo "at least we have an expired cache --> better than nothing --> read it\n";
				}

				$cache = unserialize(file_get_contents($filename));
				$returnValue = $cache;
			}
			else
			{
				if($debug)
				{
					echo "request failed and we have no cache at all --> FAIL\n";
					echo 'Curl-Fehler: ' . curl_error($ch)."\n";
				}

				$returnValue = false;
			}
		}
		else
		{

			if($debug)
			{
				echo "we got a return --> save it to cache\n";
			}

			$cache = fopen($filename, 'wb');
			$write = fwrite($cache, serialize($rawData));

			if($debug && !$write)
			{
				echo "writing to $filename failed. Make the folder '".dirname(__FILE__).'/../cache/'."' is writeable (chmod 777)";
			}

			fclose($cache);
		}

		curl_close($ch);

		$returnValue = $rawData;
	}
	else
	{

		if($debug)
		{
			echo "yay we hit the cache --> read it\n";
		}

		$cache = unserialize(file_get_contents($filename));
		$returnValue = $cache;
	}

	return $returnValue;
}

function _curlGetHeader($url)
{
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_NOBODY, true);
	curl_setopt($curl, CURLOPT_HEADER, true);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_CONNECTTIMEOUT ,5);
	curl_setopt($curl, CURLOPT_TIMEOUT, 20);
	$header = curl_exec($curl);
	curl_close($curl);
	return $header;
}
