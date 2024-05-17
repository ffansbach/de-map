<?php
namespace ffmap;

class HealthCheck
{
    private string $identifier = "";
    private $url = "https://hc-ping.com/";

    public function __construct(string $identifier)
    {
        $this->setIdentifier($identifier);
    }

    public function setIdentifier(string $identifier)
    {
        $this->identifier = $identifier;
    }

    public function pingRuntimeStart()
    {
        if($this->identifier === "") {
            throw new \ErrorException("HC identifier not set", 0, E_ERROR);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url.$this->identifier."/start");
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }
    public function ping()
    {
        if($this->identifier === "") {
            throw new ErrorException("HC identifier not set", 0, E_ERROR);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url.$this->identifier);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }
}
