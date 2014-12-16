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

require 'config.php';
require 'lib/simpleCachedCurl.inc.php';
require 'lib/nodelistparser.php';

$apiUrl = 'https://raw.githubusercontent.com/freifunk/directory.api.freifunk.net/master/directory.json';

$parser = new nodeListParser();
$parser->setCachePath(dirname(__FILE__).'/cache/');
$parser->setSource($apiUrl);

$parser->addAdditional('ffnw', array(
		'name'	=> 'Freifunk NordWest',
		'nameShort'	=> 'FF NordWest',
		'url'	=> 'https://netmon.nordwest.freifunk.net/'
	)
);

$parseResult = $parser->getParsed(isset($_REQUEST[$forceReparseKey]));

$response = array(
	'communities' => $parseResult['communities'],
	'allTheRouters' =>  $parseResult['routerList']
);

echo json_encode($response, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
