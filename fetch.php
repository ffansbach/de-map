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
	$newData = array();

	$midOver = 12;
	$dateOf = 6;

	$c = 0;
	$set = array();
	$setDate = false;
	$lastDate = false;

	foreach ($data as $item)
	{
		$c++;

		if($c == ($midOver + 1))
		{
			$mid = round(array_sum($set) / $midOver);
			$newData[] = array($setDate, $mid);

			$c = 1;
			$set = array();
			$setDate = false;
		}
		elseif($c == $dateOf)
		{
			$setDate = $item[0];
		}

		$set[] = $item[1];
		$lastDate = $item[0];
	}

	if(!empty($set))
	{
		$mid = round(array_sum($set) / count($set));
		$newData[] = array(($setDate ? $setDate : $lastDate), $mid);
	}

	return $newData;
}
