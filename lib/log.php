<?php
class log
{
	private $_db;

	public function __construct($db)
	{
		$this->_db = $db;
	}

	public function add($count)
	{
		$query = "INSERT INTO log SET `count` = ".(int)$count;
		$this->_db->query($query);
	}

	public function get()
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
		$result = $this->_db->query($query);

		$resultArray = array();

		while($row = mysqli_fetch_assoc($result))
		{
			$date = date_create((string)$row['TS']);
			$ts = $date->getTimestamp();
			$resultArray[] = array(
				'ts' => $ts,
				'max' => $row['daymax'],
				'min' => $row['daymin']
			);
		}

		return $resultArray;
	}
}