<?php

/*
 * Weak secrets to prevent external trigger for some actions.
 */
$forceReparseKey = 'a_key_to_prevent_everyone_from_calling_data.php?reparse';
$setDataLogPointToken = 'key/token for log_to_db,php';

$environment = 'dev';

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

$influxDB = [
    'host' => 'your influxdb host',
    'port' => '8086',
    'dbName' => 'freifunk_karte',
    'user' => 'influx user',
    'password' => 'influx pass',

    'add_tag_env' => $environment,
];

$trackingCode = "<!-- Piwik -->....<!-- End Piwik Code -->";
