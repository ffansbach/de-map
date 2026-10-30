<?php
/**
 * ajax response for the map: the files the parser has written to cache/
 *
 * The parse itself is done by `bin/parser run` (cron), which uploads the result files. This only reads them.
 * No config.php, no vendor/: the web part has to work with the files alone.
 */

$offset = 1 * 60 * 60;
header('Cache-Control: public, max-age=' . $offset);
header("Expires: " . gmdate("D, d M Y H:i:s", time() + $offset) . " GMT");
header('Content-Type: application/json');

$response = array(
    'communities' => getFromCache('communities'),
    'allTheRouters' => getFromCache('routers'),
    'metaCommunities' => getFromCache('metacommunities'),
    'isCachedresult' => true,
);

echo json_encode($response, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

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
