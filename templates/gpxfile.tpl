<?php

$haveBounds = false;
if (isset($_REQUEST['minlat']) && isset($_REQUEST['maxlat']) && isset($_REQUEST['minlon']) && isset($_REQUEST['maxlon']))
{
    $minLat = $_REQUEST['minlat'];
    $minLon = $_REQUEST['minlon'];
    $maxLat = $_REQUEST['maxlat'];
    $maxLon = $_REQUEST['maxlon'];
    $haveBounds = true;
}


header("Content-Type: text/xml");
header('Content-Disposition: attachment; filename="ffnodes.gpx"');

?>
<gpx version="1.1" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd">

<?php

$filename = dirname(__FILE__).'/../cache/result_routers.json';
if ( !file_exists($filename) )
{
	echo "<!-- cache is still empty -->\n\n";
}
else
{
	$routerArray = json_decode(file_get_contents($filename));
	foreach ($routerArray as $r)
	{
		if ($haveBounds && ($r->lat < $minLat || $r->lat > $maxLat || $r->long < $minLon || $r->long > $maxLon))
		{
			continue;
		}

		echo '<wpt lon="'.$r->long.'" lat="'.$r->lat.'">
<name>'.htmlspecialchars($r->name).'</name>
<desc>'.htmlspecialchars($r->name).' ('.htmlspecialchars($r->community).')</desc>
</wpt>

';
	}
}

?>
</gpx>
