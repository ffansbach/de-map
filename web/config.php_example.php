<?php

/*
 * Settings of the map on the web server.
 *
 * Copy this file to config.php (next to index.php) and adjust it. config.php is not part of the
 * repository. Upload the whole web/ directory afterwards (see the README).
 */

// The tile server: a URL template with {z}/{x}/{y} (optionally {s}) and the credit its provider asks for.
// On an https site the tile URL has to be https, too, or the browser blocks the tiles.
$tileServerUrl = 'https://tile.openstreetmap.de/{z}/{x}/{y}.png';
$tileServerAttribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';

// Where the map starts. (The spelling of the variable name is historic and has to stay.)
$mapInitalView = array(
    'latitude' => 51.16,
    'longitude' => 10.45,
    'zoom' => 6,
);

// Optional: HTML/JavaScript that is added to the end of the page, e.g. a visitor counter. Empty = nothing.
$trackingCode = '';

// Optional, only for the "Statistik" tab: MySQL/MariaDB connection. An empty array switches the statistics off.
$dbAccess = array(
#    'host' => '',
#    'db' => '',
#    'user' => '',
#    'pass' => '',
);

// Optional, only for the "Statistik" tab: a long random token. log_to_db.php only counts when it is called with
// this token (the parser calls it after an upload: statsPingUrl in config/upload.local.json).
$setDataLogPointToken = 'a long random token';
