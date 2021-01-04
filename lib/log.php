<?php
namespace ffmap;

class log
{
    /**
     * @var mysqli
     */
    protected mysqli $db;

    /**
     * log constructor.
     * @param mysqli$db
     */
    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @param int $count
     */
    public function add(int $count)
    {
        $query = "INSERT INTO log SET `count` = ".(int)$count;
        $this->db->query($query);
    }

    /**
     * @return array[]
     */
    public function get(): array
    {
        $query = "SELECT
                    TS,
                    MIN(count) AS daymin,
                    MAX(count) AS daymax
                FROM
                    `log`
                WHERE
                    `count` > 5000
                GROUP BY DATE(`ts`)
                ORDER BY ts";
        $result = $this->db->query($query);

        $resultArray = array();
        $lastMin = 0;

        while ($row = mysqli_fetch_assoc($result)) {
            $date = date_create((string)$row['TS']);
            $ts = $date->getTimestamp();

            if ($row['daymin'] > ($lastMin * 0.75)) {
                $resultArray[] = array(
                    'ts' => $ts,
                    'max' => $row['daymax'],
                    'min' => $row['daymin']
                );
            }
            $lastMin = $row['daymin'];
        }

        return $resultArray;
    }
}
