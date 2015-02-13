<?php
/**
 * ajax response with parseresult
 *
 * this will try to load a cched result if not older than 24h
 */
header('Cache-Control: no-cache, must-revalidate');
$offset = 24 * 60 * 60;
header ("Expires: " . gmdate ("D, d M Y H:i:s", time() + $offset) . " GMT");
header('Content-Type: application/json');
error_reporting(-1);
ini_set('display_errors', 'On');
require 'config.php';
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

$parser->addAdditional('ffnw', $ffnw);

$parseResult = $parser->getParsed(isset($_REQUEST[$forceReparseKey]));

$response = array(
	'communities' => $parseResult['communities'],
	'allTheRouters' =>  $parseResult['routerList']
);

if(isset($_REQUEST[$forceReparseKey]) && is_array($dbAccess))
{
	$db = new mysqli($dbAccess['host'], $dbAccess['user'], $dbAccess['pass'], $dbAccess['db']);
	$log = new log($db);
	$log->add(sizeof($parseResult['routerList']));
}

/**
 * if processonly is set we handle a reparse cron request
 */
if(!isset($_REQUEST['processonly']))
{
	echo json_encode($response, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}
else
{
	$report = array(
		'communities'	=> sizeof($parseResult['communities']),
		'nodes'	=> sizeof($parseResult['routerList']),
		'stats' => $parser->getParseStatistics()
	);

	echo json_encode($report, JSON_PRETTY_PRINT);
}
