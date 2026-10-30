<?php
/**
 * Log the number of communities and nodes to a mysql db.
 * Those logs are used in the frontend to show a chart with historic data.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/log.php';

// strings only and compared as such: "10" must not equal "1e1", and the time of the check tells nothing
if (!is_string($setDataLogPointToken) || $setDataLogPointToken === ''
    || !isset($_REQUEST['token']) || !is_string($_REQUEST['token'])
    || !hash_equals($setDataLogPointToken, $_REQUEST['token'])) {
    return;
}

if (!is_array($dbAccess)
    || !isset($dbAccess['host'], $dbAccess['user'], $dbAccess['pass'], $dbAccess['db'])) {
    echo 'no db config found';
    return;
}

$db = new \mysqli($dbAccess['host'], $dbAccess['user'], $dbAccess['pass'], $dbAccess['db']);

if ($db->connect_errno) {
    // the details (host, user name) belong in the server's error log, not in the response
    error_log('log_to_db.php: failed to connect to MySQL: (' . $db->connect_errno . ') ' . $db->connect_error);
    http_response_code(500);
    echo 'database not available';
    return;
}

$nodesJson = file_get_contents(__DIR__ . '/cache/result_routers.json');
$nodes = json_decode($nodesJson);

if (sizeof($nodes) > 0) {
    $log = new log($db);
    $log->add(sizeof($nodes));
}
