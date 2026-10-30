<?php

/*
 * Weak secret to prevent external trigger of log_to_db.php (the statistics counter).
 */
$setDataLogPointToken = 'key/token for log_to_db,php';

/*
 * healthchecks.io identifier of the check that `bin/parser run --legacy-config=config.php` pings.
 */
$healtcheckIdentifier = 'your healthchecks.io uuid';

$environment = 'dev'; //for logging purpose . ie InfluxDB

$tileServerUrl = 'https://tile.openstreetmap.de/{z}/{x}/{y}.png';
$tileServerAttribution = '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>';

$mapInitalView = array(
    'latitude' => 51.16,
    'longitude' => 10.45,
    'zoom' => 6,
);

$dbAccess = [
#    mysql connection parameters for node statistics
#    empty if not wanted
#    'host' => '',
#    'db' => '',
#    'user' => '',
#    'pass' => ''
];

$serverUpload = [
#    ftp for upload of results
#    empty if not wanted
#    'host' => '',
#    'user' => '',
#    'password' => '',
#    'target' => '/cache',
];

/*
 * InfluxDB v2, read by `bin/parser run --legacy-config=config.php`.
 */
$influxDB = [
    'host' => 'your influxdb host',
    'port' => '8086',
    'org' => 'your org',
    'token' => '...',
    'bucket' => 'freifunk_karte',

    'add_tag_env' => $environment,
];

$trackingCode = "<!-- Piwik -->....<!-- End Piwik Code -->";
