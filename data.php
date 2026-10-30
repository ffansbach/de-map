<?php
/**
 * ajax response for the map: the files the parser has written to cache/
 *
 * The parse itself is done by `bin/parser run` (cron), which uploads the result files. This only reads them.
 * No config.php, no vendor/: the web part has to work with the files alone.
 *
 * The files change once per run. ETag and Last-Modified come from their modification times and sizes, so a
 * browser that asks again after max-age gets an empty 304 and nothing is read or decoded.
 */

$offset = 10 * 60;
header('Cache-Control: public, max-age=' . $offset);
header("Expires: " . gmdate("D, d M Y H:i:s", time() + $offset) . " GMT");

$validators = getValidators(array('communities', 'routers', 'metacommunities'));

if ($validators !== null) {
    header('ETag: ' . $validators['etag']);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $validators['modified']) . ' GMT');

    if (isNotModified($validators)) {
        http_response_code(304);
        exit;
    }
}

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
 * @return string
 */
function getCacheFile(string $key)
{
    return dirname(__FILE__) . '/cache/result_' . $key . '.json';
}

/**
 * @param $key string
 * @return false|mixed
 */
function getFromCache(string $key)
{
    $filename = getCacheFile($key);

    if (!file_exists($filename)) {
        return false;
    } else {
        return json_decode(file_get_contents($filename));
    }
}

/**
 * What identifies the current content: name, modification time and size of the result files.
 * The ETag is weak because the web server may compress the body; the content is the same either way.
 *
 * @param $keys string[]
 * @return array|null null if a file is missing (nothing to validate then)
 */
function getValidators(array $keys)
{
    clearstatcache();
    $parts = array();
    $modified = 0;

    foreach ($keys as $key) {
        $filename = getCacheFile($key);

        if (!is_file($filename)) {
            return null;
        }

        $mtime = (int)filemtime($filename);
        $parts[] = $key . ':' . $mtime . ':' . filesize($filename);
        $modified = max($modified, $mtime);
    }

    return array('etag' => 'W/"' . md5(implode('|', $parts)) . '"', 'modified' => $modified);
}

/**
 * Whether the browser already has this version (RFC 9110: If-None-Match wins over If-Modified-Since).
 *
 * @param $validators array
 * @return bool
 */
function isNotModified(array $validators)
{
    if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
        $wanted = normalizeEtag($validators['etag']);

        foreach (explode(',', $_SERVER['HTTP_IF_NONE_MATCH']) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*' || normalizeEtag($candidate) === $wanted) {
                return true;
            }
        }

        return false;
    }

    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);

        return $since !== false && $validators['modified'] <= $since;
    }

    return false;
}

/**
 * The bare tag: without the weak marker, the quotes and the "-gzip"/"-br" some servers append when they compress.
 *
 * @param $etag string
 * @return string
 */
function normalizeEtag(string $etag)
{
    $etag = trim($etag);

    if (strncmp($etag, 'W/', 2) === 0) {
        $etag = substr($etag, 2);
    }

    return preg_replace('/(-gzip|-br|-deflate)?"?$/', '', ltrim($etag, '"'));
}
