<?php
/**
 * ajax response with parseresult
 *
 * this will try to load a cched result if not older than 24h
 */

namespace ffmap;

use Lib\InfluxLog;

require __DIR__ . '/vendor/autoload.php';

$startTS = microtime(true);

$offset = 1 * 60 * 60;
header('Cache-Control: public, max-age=' . $offset);
header("Expires: " . gmdate("D, d M Y H:i:s", time() + $offset) . " GMT");
header('Content-Type: application/json');

ini_set('display_errors', 'On');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('max_execution_time', (60 * 60));
set_time_limit((60 * 60));
ini_set('memory_limit', '1024M');

require 'config.php';

if (isset($_REQUEST[$forceReparseKey])) {
    // reparse requested
    // actually parse now

    require 'lib/jsv4/Jsv4.php';
    require 'lib/log.php';

    $apiUrl = 'https://raw.githubusercontent.com/freifunk/directory.api.freifunk.net/master/directory.json';

    $cachePath = dirname(__FILE__) . '/cache/';
    $cache = new CommunityCacheHandler($cachePath);
    $curlHelper = new CurlHelper();
    $parser = new NodeListParser($cache, $curlHelper);

    $parser->setCachePath($cachePath);
    $parser->setSource($apiUrl);

    require 'fixedCommunities.php';

    $parseResult = $parser->getParsed(true);

    $response = array(
        'communities' => count((array)$parseResult['communities']),
        'allTheRouters' => count($parseResult['routerList']),
        'memoryUsed' => round(memory_get_peak_usage(true) / 1024 / 1024, 1) . 'Mb',
    );

    if (!empty($dbAccess)) {
        $db = new mysqli($dbAccess['host'], $dbAccess['user'], $dbAccess['pass'], $dbAccess['db']);
        $log = new log($db);
        $log->add(sizeof($parseResult['routerList']));
    }
} else {
    // fetch cached result - the shortcut
    $response = array(
        'communities' => getFromCache('communities'),
        'allTheRouters' => getFromCache('routers'),
        'metaCommunities' => getFromCache('metacommunities'),
        'isCachedresult' => true,
    );
}

/**
 * if processonly is set we handle a reparse cron request
 */
if (isset($_REQUEST['processonly']) && isset($parser)) {
    $report = array(
        'communities' => $response['communities'],
        'nodes' => $response['allTheRouters'],
        'memoryUsed' => round(memory_get_peak_usage(true) / 1024 / 1024, 1) . 'Mb',
    );

    echo json_encode($report, JSON_PRETTY_PRINT);
} else {
    echo json_encode($response, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

if (isset($_REQUEST['upload'])
    && $response['communities'] > 10
    && $response['allTheRouters'] > 1000) {
    $parseTime = microtime(true) - $startTS;
    include 'upload_cache.php';
    $uploadTime = microtime(true) - $startTS - $parseTime;

    if (!empty($influxDB)) {
        $influxLog = new InfluxLog(
            $influxDB['host'],
            $influxDB['port'],
            $influxDB['user'],
            $influxDB['password'],
            $influxDB['dbName'],
        );

        $fields = [
            'communities' => (int)$response['communities'],
            'parse_time' => (int)$parseTime,
            'upload_time' => (int)$uploadTime,
            'upload_time_float' => (float)$uploadTime,
            'mem_usage' => memory_get_peak_usage(true),
            'curl_calls' => (int)$curlHelper->getCallCounter(),
            'env' => isset($influxDB['add_tag_env']) ? $influxDB['add_tag_env'] : 'undefined'
        ];

        try {
            $influxLog->logPoint([
                'value' => (int)$response['allTheRouters'],
                'fields' => $fields,
            ]);
        } catch (\InfluxDB\Exception $e) {
            echo 'Logging to InfluxDB failed: ' . $e->getMessage;
        }
    }
}

/**
 * @param $key string
 * @return false|mixed
 */
function getFromCache(string $key)
{
    $filename = dirname(__FILE__) . '/cache/result_' . $key . '.json';

    if (!file_exists($filename)) {
        return false;
    } else {
        return json_decode(file_get_contents($filename));
    }

}
