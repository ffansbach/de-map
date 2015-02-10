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
}