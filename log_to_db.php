<?php
/**
 * Log the number of communities and nodes to a mysql db.
 * Those logs are used in the frontend to show a chart with historic data.
 */
declare(strict_types = 1);

namespace ffmap;

require_once __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';

if (!isset($_REQUEST['token'])
    || $_REQUEST['token'] != $setDataLogPointToken) {
    return;
}

if (!is_array($dbAccess)
    || !isset($dbAccess['host'], $dbAccess['user'], $dbAccess['pass'], $dbAccess['db'])) {
    echo 'no db config found';
    return;
}

$db = new \mysqli($dbAccess['host'], $dbAccess['user'], $dbAccess['pass'], $dbAccess['db']);

if ($db->connect_errno) {
    echo 'Failed to connect to MySQL: (' . $db->connect_errno . ') ' . $db->connect_error;
    return;
}

$nodesJson = file_get_contents(__DIR__ . '/cache/result_routers.json');
$nodes = json_decode($nodesJson);

if (sizeof($nodes) > 0) {
    $log = new log($db);
    $log->add(sizeof($nodes));
}
