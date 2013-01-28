<?php

require_once("settings.php");

class ResponseOption {
	private $conn;
	private $option_name;
	private $value;


	public function __construct($option_name, $value, $conn) {
		$this->conn = $conn;
		$this->option_name = $option_name;
		$this->value = $value;
	}
	public function getText() { //this will return the textual form of the request option
		return $this->option_name . ": " . $this->value . "\n";
	}
	public function setOptionValue($new_value)
	{
		$this->value=$new_value;	
	}
	//the following function gets the type name for the request
	public function getOptionValue()
	{
		return $this->value;
	}
	public function getOptionName() 
	{
		return $this->option_name;
	}
}
