<?php
namespace Lib;

use http\Exception\InvalidArgumentException;
use InfluxDB\Database;
use InfluxDB\Client;
use InfluxDB\Point;

class InfluxLog
{
    /**
     * @var Client|Database
     */
    protected $dataBase;

    /**
     * InfluxLog constructor.
     * @param string $host
     * @param string $port
     * @param string $user
     * @param string $password
     * @param string $dbName
     */
    public function __construct(
        string $host,
        string $port,
        string $user,
        string $password,
        string $dbName
    ) {
        $connectString = sprintf('influxdb://%s:%s@%s:%s/%s', $user, $password, $host, $port, $dbName);
        $this->dataBase = $database = Client::fromDSN($connectString);
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

        $points = [
            new Point(
                $measurementName,
                $data['value'],
                $tags,
                $fields,
            ),
        ];

        return $this->dataBase->writePoints($points, Database::PRECISION_SECONDS);
    }
}
