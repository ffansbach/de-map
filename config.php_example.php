<?php

$forceReparseKey = 'neuladen';

// want another style? check http://leaflet-extras.github.io/leaflet-providers/preview/
$tileServerUrl = 'http://{s}.tile.openstreetmap.de/tiles/osmde/{z}/{x}/{y}.png';
$tileServerAttribution = '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>';

$mapInitalView = array(
	'latitude' => 49.447733,
	'longitude' => 10.767502,
	'zoom' => 10,
);

/*
 * this will be used for some css and js
 * don't forget to add a slash
 */
$localNetmon = 'https://netmon.freifunk-emskirchen.de/';
