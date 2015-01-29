<?php

$supported = array(
	'about' => 'templates/about.tpl'
);

if(isset($_REQUEST['content']) && isset($supported[$_REQUEST['content']]))
{
	include(__DIR__.'/'.$supported[$_REQUEST['content']]);
}
elseif(isset($_REQUEST['get_debug']))
{
	$filename = 'cache/result_statistics.cache';

	if(file_exists($filename))
	{
		echo json_encode(unserialize(file_get_contents($filename)));
	}
}
