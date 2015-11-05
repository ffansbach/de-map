<?php
/**
 * ajax response with parseresult
 *
 * this will try to load a cched result if not older than 24h
 */
$offset = 1 * 60 * 60;
header('Cache-Control: public, max-age='.$offset);
header ("Expires: " . gmdate ("D, d M Y H:i:s", time() + $offset) . " GMT");
header('Content-Type: application/json');
error_reporting(-1);
ini_set('display_errors', 'On');

require 'config.php';

if(!isset($_REQUEST[$forceReparseKey]))
{
	// fetch cached result - the shortcut
	$response = array(
		'communities' =>		getFromCache('communities'),
		'allTheRouters' =>		getFromCache('routers'),
		'metaCommunities' =>	getFromCache('metacommunities'),
		'isCachedresult' =>		true,
	);
}
else
{
	// reparse requested
	// actually parse now

	require 'lib/simpleCachedCurl.inc.php';
	require 'lib/nodelistparser.php';
	require 'lib/jsv4/jsv4.php';
	require 'lib/log.php';

	$apiUrl = 'https://raw.githubusercontent.com/freifunk/directory.api.freifunk.net/master/directory.json';

	$parser = new nodeListParser();

	// uncomment to enable debugoutput from simplecachedcurl
	// $parser->setDebug(true);

	$parser->setCachePath(dirname(__FILE__).'/cache/');
	$parser->setSource($apiUrl);

	$ffnw = new stdClass;
	$ffnw->name = 'Freifunk NordWest';
	$ffnw->nameShort = 'Freifunk NordWest';
	$ffnw->url = 'https://netmon.nordwest.freifunk.net/';
	$ffnw->parser = 'Netmon';
	$parser->addAdditional('ffnw', $ffnw);

	$ffj = new stdClass;
	$ffj->name = 'Freifunk Jena';
	$ffj->nameShort = 'Freifunk Jena';
	$ffj->url = 'https://freifunk-jena.de/ffmap/';
	$ffj->parser = 'Ffmap';
	$parser->addAdditional('ffj', $ffj);

	$ffffm = new stdClass;
	$ffffm->name = 'Frankfurt am Main';
	$ffffm->nameShort = 'Frankfurt am Main';
	$ffffm->url = 'http://map.ffm.freifunk.net/';
	$ffffm->parser = 'Ffmap';
	$parser->addAdditional('ffffm', $ffffm);

	$ff_ruhrg_fb = new stdClass;
	$ff_ruhrg_fb->name = 'Freifunk Ruhrgebiet - FB';
	$ff_ruhrg_fb->nameShort = 'Freifunk Ruhrgebiet - FB';
	$ff_ruhrg_fb->url = 'http://map.freifunk-ruhrgebiet.de/data/';
	$ff_ruhrg_fb->parser = 'Ffmap';
	$parser->addAdditional('ff_ruhrg_fb', $ff_ruhrg_fb);

	$parseResult = $parser->getParsed(true);

	$response = array(
		'communities' => $parseResult['communities'],
		'allTheRouters' =>  $parseResult['routerList']
	);

	if(is_array($dbAccess))
	{
		$db = new mysqli($dbAccess['host'], $dbAccess['user'], $dbAccess['pass'], $dbAccess['db']);
		$log = new log($db);
		$log->add(sizeof($parseResult['routerList']));
	}

}

/**
 * if processonly is set we handle a reparse cron request
 */
if(isset($_REQUEST['processonly']) && isset($parser))
{
	$report = array(
		'communities'	=> sizeof($response['communities']),
		'nodes'			=> sizeof($response['allTheRouters']),
		'stats'			=> $parser->getParseStatistics(),
	);

	echo json_encode($report, JSON_PRETTY_PRINT);
}
else
{
	echo json_encode($response, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

function getFromCache($key)
{
	$filename = dirname(__FILE__).'/cache/result_'.$key.'.json';

	if ( !file_exists($filename) )
	{
		return false;
	}
	else
	{
		return json_decode(file_get_contents($filename));
	}

}
