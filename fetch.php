<?php

$supported = array('about' => 'templates/about.tpl');

if(isset($_REQUEST['content']) && isset($supported[$_REQUEST['content']]))
{
	include(__DIR__.'/'.$supported[$_REQUEST['content']]);
}