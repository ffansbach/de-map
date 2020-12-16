<?php
/**
 * ajax response with parseresult
 *
 * this will try to load a cched result if not older than 24h
 */

use Lib\InfluxLog;

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

if (!isset($_REQUEST[$forceReparseKey])) {
    // fetch cached result - the shortcut
    $response = array(
        'communities' => getFromCache('communities'),
        'allTheRouters' => getFromCache('routers'),
        'metaCommunities' => getFromCache('metacommunities'),
        'isCachedresult' => true,
    );
} else {
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

    $parser->setCachePath(dirname(__FILE__) . '/cache/');
    $parser->setSource($apiUrl);

    $ffffm = new stdClass;
    $ffffm->name = 'Frankfurt am Main';
    $ffffm->nameShort = 'Frankfurt am Main';
    $ffffm->url = 'http://www.projekt2k.de/externes/ffkarte/fetchffm.php';
    $ffffm->parser = 'Ffmap';
    $parser->addAdditional('ffffm', $ffffm);

    $ff_ruhrg_fb = new stdClass;
    $ff_ruhrg_fb->name = 'Freifunk Ruhrgebiet - FB';
    $ff_ruhrg_fb->nameShort = 'Freifunk Ruhrgebiet - FB';
    $ff_ruhrg_fb->url = 'http://map.freifunk-ruhrgebiet.de/data/';
    $ff_ruhrg_fb->parser = 'Ffmap';
    $parser->addAdditional('ff_ruhrg_fb', $ff_ruhrg_fb);

    $ff_ddfl = new stdClass;
    $ff_ddfl->name = 'Freifunk Duesseldorf-Flingern';
    $ff_ddfl->nameShort = 'Duesseldorf Flingern';
    $ff_ddfl->url = 'https://karte.ffdus.de/data/nodes.json';
    $ff_ddfl->homePage = 'http://www.ffdus.de/';
    $ff_ddfl->parser = 'Ffmap';
    $parser->addAdditional('ff_duesseldorf-flingern', $ff_ddfl);

    $ff_kssl = new stdClass;
    $ff_kssl->name = 'Freifunk Kassel';
    $ff_kssl->nameShort = 'Kassel';
    $ff_kssl->url = 'http://www.projekt2k.de/externes/ffkarte/fetchkassel.php';
    $ff_kssl->homePage = 'https://freifunk-kassel.de/';
    $ff_kssl->parser = 'nodelist';
    $parser->addAdditional('ff_kassel', $ff_kssl);

    $ff_nw = new stdClass;
    $ff_nw->name = 'Freifunk Nordwest';
    $ff_nw->nameShort = 'Nordwest';
    $ff_nw->url = 'http://srv11.ffnw.de/nodelist_api/nodelist.json';
    $ff_nw->homePage = 'http://www.ffnw.de';
    $ff_nw->parser = 'nodelist';
    $parser->addAdditional('ff_nw', $ff_nw);

    $ff_nu = new stdClass;
    $ff_nu->name = 'Freifunk Niersufer';
    $ff_nu->nameShort = 'Niersufer';
    $ff_nu->url = 'http://api.freifunk-niersufer.de/niers/nodes.json';
    $ff_nu->homePage = 'http://www.freifunk-niersufer.de';
    $ff_nu->parser = 'Ffmap';
    $parser->addAdditional('ff_nu', $ff_nu);

    $ff_nr = new stdClass;
    $ff_nr->name = 'Freifunk Niederrhein';
    $ff_nr->nameShort = 'Niederrhein';
    $ff_nr->url = 'http://map.freifunk-niederrhein.de/data/fl/nodes.json';
    $ff_nr->homePage = 'http://www.freifunk-niederrhein.de';
    $ff_nr->parser = 'Ffmap';
    $parser->addAdditional('ff_nr', $ff_nr);

    $ff_wt = new stdClass;
    $ff_wt->name = 'Freifunk Wuppertal';
    $ff_wt->nameShort = 'Wuppertal';
    $ff_wt->url = 'https://map.freifunk-wuppertal.net/data/nodes.json';
    $ff_wt->homePage = 'http://www.freifunk-wuppertal.net';
    $ff_wt->parser = 'Ffmap';
    $parser->addAdditional('ff_wt', $ff_wt);

    $ff_ulm = new stdClass;
    $ff_ulm->name = 'Freifunk Ulm';
    $ff_ulm->nameShort = 'Ulm';
    $ff_ulm->url = 'https://map.freifunk-ulm.de/meshviewer/nodes.json';
    $ff_ulm->homePage = 'http://www.freifunk-ulm.de';
    $ff_ulm->parser = 'Ffmap';
    $parser->addAdditional('ff_ulm', $ff_ulm);

    $ff_stu = new stdClass;
    $ff_stu->name = 'Freifunk Stuttgart';
    $ff_stu->nameShort = 'Stuttgart';
    $ff_stu->url = 'https://netinfo.freifunk-stuttgart.de/json/global_map.json';
    $ff_stu->homePage = 'http://www.freifunk-stuttgart.de';
    $ff_stu->parser = 'nodelist';
    $parser->addAdditional('ff_stu', $ff_stu);

    foreach(["wtbg","sdlh","hd","mdb","doerfer","ln","hlb","bs","mb","mq","su","wa","ar"] as $index => $identifier) {
      ${'ff_winterb_'.$index} = new stdClass;
      ${'ff_winterb_'.$index}->name = 'Freifunk Winterberg '.$identifier;
      ${'ff_winterb_'.$index}->nameShort = 'Winterberg'.$identifier;
      ${'ff_winterb_'.$index}->url = 'https://map.freifunk-winterberg.net/data/'.$identifier.'/meshviewer.json';
      ${'ff_winterb_'.$index}->homePage = 'https://map.freifunk-winterberg.net/';
      ${'ff_winterb_'.$index}->parser = 'Ffmap';
      $parser->addAdditional('ff_winterberg_'.$index, ${'ff_winterb_'.$index});
    }

    $parseResult = $parser->getParsed(true);

    $response = array(
        'communities' => $parseResult['communities'],
        'allTheRouters' => $parseResult['routerList']
    );

    if (is_array($dbAccess)) {
        $db = new mysqli($dbAccess['host'], $dbAccess['user'], $dbAccess['pass'], $dbAccess['db']);
        $log = new log($db);
        $log->add(sizeof($parseResult['routerList']));
    }

}

/**
 * if processonly is set we handle a reparse cron request
 */
if (isset($_REQUEST['processonly']) && isset($parser)) {
    $report = array(
        'communities' => sizeof($response['communities']),
        'nodes' => sizeof($response['allTheRouters']),
        'stats' => $parser->getParseStatistics(),
    );

    echo json_encode($report, JSON_PRETTY_PRINT);
} else {
    echo json_encode($response, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

if (isset($_REQUEST['upload'])
    && sizeof($response['communities']) > 10
    && sizeof($response['allTheRouters'])) {

    $parseTime = microtime(true) - $startTS;

    include 'upload_cache.php';

    $uploadTime = microtime(true) - $startTS - $parseTime;

    $influxLog = new InfluxLog(
        $influxDB['host'],
        $influxDB['port'],
        $influxDB['user'],
        $influxDB['password'],
        $influxDB['dbName'],
    );

    $influxLog->logPoint([
        'value' => sizeof($response['allTheRouters']),
        'fields' => [
            'communities' => sizeof($response['communities']),
            'parse_time' => (int)$parseTime,
            'upload_time' => (int)$uploadTime,
        ]
    ]);
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
