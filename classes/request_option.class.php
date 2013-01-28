<?php

require_once("settings.php");

class RequestOption {
	private $_allowed_options = 
array("Host","User-Agent","Connection","Accept","Accept-Language","Accept-Encoding","Accept-Charset","Keep-Alive","Cookie", 
"Cache-Control","Pragma","UA-CPU","Referer", "If-Modified-Since","If-Unmodified-Since","If-Match","If-None-Match","Accept", 
"Accept-Charset", "Accept-Encoding", "Accept-Language", "User-Agent", "Referer", "Negotiate", "Authorization", "Content-Length",
"Content-type", "Content-Type", "Content-Disposition"); //what request headers do we understand?
	private $option_name;
	private $value;
	public function __construct($request_line,$conn) {
		global $currentRequest;

		if (preg_match("@(.*)\: (.*)[\r\n]*@",$request_line,$match)) {
			//all is good
		} else {
			debug("Request Options Failed On: " . $request_line,4);
			$currentRequest->giveResponseCodeAndClose(CODE_400,$conn,$currentRequest->getMethod());
		}
		$this->option_name = $match[1];
		$this->value = $match[2];

		//can we handle this request???
		if (!in_array($this->option_name,$this->_allowed_options)) {
			debug("We can't handle a " . $this->option_name . " request header",4);
			global $currentRequest;
			$currentRequest->giveResponseCodeAndClose(CODE_501,$conn,$currentRequest->getMethod());
		}
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
