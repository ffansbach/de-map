<?php
namespace Lib;

use http\Exception\InvalidArgumentException;
use InfluxDB\Database;
use InfluxDB\Client;
use InfluxDB\Point;
use InfluxDB2;

class InfluxLog
{
    /**
     * @var Client|Database
     */
    protected $dataBase;

    /**
     * @var InfluxDB2\Client
     */
    protected $client;

    /**
     * @var InfluxDB2\WriteApi
     */
    protected $writeApi;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @var string
     */
    protected $org;

    /**
     * InfluxLog constructor.
     * @param string $host
     * @param string $port
     * @param string $token
     * @param string $bucket
     */
    public function __construct(
        string $host,
        string $port,
        string $token,
        string $bucket,
        string $org
    ) {
        $this->client = new InfluxDB2\Client([
            "url" => $host.":".$port, // url and port of your instance
            "token" => $token,
            "bucket" => $bucket,
            "org" => $org,
            "precision" => InfluxDB2\Model\WritePrecision::NS,
        ]);

        $this->writeApi = $this->client->createWriteApi();
        $this->bucket = $bucket;
        $this->org = $org;
    }

    /**
     * @param mixed[] $data
     * @param string $measurementName
     * @return bool
     * @throws \InfluxDB\Exception
     */
    public function logPoint(
        array $data,
        string $measurementName = 'parser_result_count'
    ) {
        if (!isset($data['value'])) {
            throw new InvalidArgumentException('LogPoint data is missing a value');
        }

        $tags = isset($data['tags']) && is_array($data['tags']) ? $data['tags'] : [];
        $fields = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [];

        $dataArray = [
            'name' => $measurementName,
            'tags' => $tags,
            'fields' => $fields,
            'time' => microtime(true)];

        $this->writeApi->write($dataArray, InfluxDB2\Model\WritePrecision::S, $this->bucket, $this->org);

        return true;
    }
}
