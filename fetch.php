<?php
error_reporting(-1);
ini_set('display_errors', 'On');

require 'config.php';

$supported = array(
	'about' => array(
		'tpl' => 'templates/about.tpl',
	),
	'stats' => array(
		'tpl' => 'templates/stats.tpl',
		'contr' => 'fetchStats',
	),
	'gpxfile' => array(
		'tpl' => 'templates/gpxfile.tpl',
	),
);

if(isset($_REQUEST['content']) && isset($supported[$_REQUEST['content']]))
{
	$data = false;

	if(!empty($supported[$_REQUEST['content']]['contr']))
	{
		$controller = $supported[$_REQUEST['content']]['contr'];

		if(function_exists($controller))
		{
			$data = $controller();
		}
	}

	include(__DIR__.'/'.$supported[$_REQUEST['content']]['tpl']);

}
elseif(isset($_REQUEST['get_debug']))
{
	$filename = 'cache/result_statistics.cache';

	if(file_exists($filename))
	{
		echo json_encode(unserialize(file_get_contents($filename)));
	}
}

function fetchStats()
{
	global $dbAccess;
	require 'lib/log.php';

	$db = new mysqli($dbAccess['host'], $dbAccess['user'], $dbAccess['pass'], $dbAccess['db']);
	$log = new log($db);
	$data = $log->get();

	$min = array();
	$max = array();

	$midOver = 12;
	$dateOf = 6;

	$c = 0;
	$set = array();
	$setDate = false;
	$lastDate = false;

	foreach ($data as $item)
	{
		$min[] = array($item['ts'], $item['min']);
		$max[] = array($item['ts'], $item['max']);
	}

	return array($min, $max);
}
