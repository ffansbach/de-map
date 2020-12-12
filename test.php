<pre>
<?php 
echo "map.freifunk-kassel.de";
echo "\nIP:";
echo gethostbyname('map.freifunk-kassel.de');

$url = 'https://map.freifunk-kassel.de/data/nodelist.json';

echo "\n\ntry to fetch ".$url;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_VERSION_IPV6);
//curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_WHATEVER);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
//curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
//curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
//curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,5);
//curl_setopt($ch, CURLOPT_TIMEOUT, 20);
//curl_setopt($ch, CURLOPT_USERAGENT,'php parser for http://www.freifunk-karte.de/');
curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_1   );
var_dump(CURL_SSLVERSION_TLSv1_1  );
$rawData = curl_exec($ch);

$status = curl_getinfo($ch);

echo "\n\nStatus: ".$status['http_code'];

if(!$rawData)
{
    echo "\ncURL request failed\n";
    echo 'Curl-Fehler: ('.curl_errno($ch).') '.curl_error($ch)."\n";
}
else
{
    echo "cURL request SUCCESS\n";

    var_dump($rawData);
}

echo "\ncURL Info:\n";
var_dump($status);

?>
</pre>