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
		$query = "SELECT TS, count FROM `log` WHERE `count` > 1000 ORDER BY ts";
		$result = $this->_db->query($query);

		$resultArray = array();

		while($row = mysqli_fetch_assoc($result))
		{
			$date = date_create((string)$row['TS']);
			$ts = $date->getTimestamp();
			$resultArray[] = array($ts, $row['count']);
		}

		return $resultArray;
	}
}