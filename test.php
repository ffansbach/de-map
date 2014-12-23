<?php

header('Cache-Control: no-cache, must-revalidate');
$offset = 24 * 60 * 60;
header ("Expires: " . gmdate ("D, d M Y H:i:s", time() + $offset) . " GMT");
header('Content-Type: application/json');

require_once("lib/convex-hull.php");


require 'config.php';
require 'lib/simpleCachedCurl.inc.php';

$filename = dirname(__FILE__).'/cache/result_routers.cache';
$points = unserialize(file_get_contents($filename));

$chPoints = array();

$comms = array();

$max = 0;

foreach($points as $point)
{
	if($point['lat'] > 55.1 or $point['lat'] < 47.2
		or
		$point['long'] > 15.1 or $point['long'] < 5.8)
	{
		continue;
	}

	$cName = $point['community'];

	if(!isset($comms[$cName]))
	{
		$comms[$cName] = array('points' => array(), 'hull' => array());
	}

	$comms[$cName]['points'][] = array($point['lat'], $point['long']);
}

foreach ($comms AS &$comunity)
{
	$ch = new ConvexHull($comunity['points']);
	$comunity['hull'] = $ch->getHullPoints();
	$comunity['points'] = sizeof($comunity['points']);
}

echo json_encode($comms, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
