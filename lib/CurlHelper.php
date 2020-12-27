<?php

namespace ffmap;

/**
 * Class CurlHelper
 * @package ffmap
 */
class CurlHelper
{
    protected int $callCounter = 0;

    /**
     * @return int
     */
    public function getCallCounter() : int
    {
        return $this->callCounter;
    }

    /**
     * @param $url
     * @return bool|string
     */
    public function doCall($url)
    {
        $curlHandler = curl_init();
        curl_setopt($curlHandler, CURLOPT_URL, $url);
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandler, CURLOPT_IPRESOLVE, CURL_VERSION_IPV6);
        /*
         * we often have communities that do not have their certificates in line
         * this is risky though if someone wants to blow us up by sending a lot of garbage
         */
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curlHandler, CURLOPT_MAXREDIRS, 2);
        curl_setopt($curlHandler, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curlHandler, CURLOPT_TIMEOUT, 20);
        curl_setopt($curlHandler, CURLOPT_USERAGENT, 'php parser for http://www.freifunk-karte.de/');

        $this->callCounter++;
        $rawData = curl_exec($curlHandler);
        $status = curl_getinfo($curlHandler);

        // a redirect was indicated
        if (in_array((int)$status['http_code'], [301, 302])) {
            $headerData = $this->getHeader($url);
            list($header) = explode("\r\n\r\n", $headerData, 2);

            if ($header != '') {
                $matches = [];
                preg_match("/(Location:|URI:)[^(\n)]*/", $header, $matches);

                if (!isset($matches[0], $matches[1])) {
                    return false;
                }

                $url = trim(str_replace($matches[1], "", $matches[0]));
                $url_parsed = parse_url($url);
                return (isset($url_parsed)) ? $this->doCall($url) : '';
            }
        }

        curl_close($curlHandler);

        return $rawData;
    }

    /**
     * @param $url
     * @return bool|string
     */
    protected function getHeader($url)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        $this->callCounter++;
        $header = curl_exec($curl);
        curl_close($curl);
        return $header;
    }
}
