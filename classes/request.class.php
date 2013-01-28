<?php
//Request class
//By: Ryan Null
//@Summary: This class handles requests from a client according to RFC 2616 Standards section 5

require_once("settings.php");

class Request {
	private $method = "";
	public $request_uri = "";
	private $http_version = "";
	private $entity = "";
	public  $response;
	private $scheme;
	private $host;
	private $port;
	private $path;
	private $query;
	private $fragment;
	private $conn;
	private $body = false;
	public $user = "";
	public $auth_type = "";

	//these are our inner variables
	private $_request_line = "";
	private $_request_options = array(); //we may have more than one of these
	private $_request = "";
	private $_raw_request;

	private $_allowed_methods = array("GET","HEAD","OPTIONS","TRACE","PUT","POST","DELETE"); //what method of requests do we undertand?
	private $_allowed_http_versions = array("HTTP/1.1","http/1.1"); //what versions of http do we understand?

	public function __construct($raw,$conn) {
		//constructor
		//just pass in the raw string sent by the client and this will parse everything out of it

		$this->conn = $conn;
		//create our response body
		$this->response = new Response($conn);

		//save the orignal raw_request
		$this->_raw_request = $raw;
		debug("Done loading request",5);
	}
	public function addBody($body) {
		$this->body = $body;
        debug("Body was attached to message",5);
        debug("Body has length of: " . strlen($body),5);
        $str = "Body COntent: \"" . $body . "\"";
        debug($str,5);
	}
    public function getBody() {
        return $this->body;
    }
	public function getQueryString() {
		return $this->query;
	}

	private function isHttpVersionAcceptable($http_version) {
		if (in_array($http_version,$this->_allowed_http_versions))
			return true;
		else
			return false;
	}
	public function isRequestOption($option_type) {
		$found = false;
		foreach ($this->_request_options as $option) 
		{
			if ($option->getOptionName() == $option_type) 
			{
				$found = true;
			}
		}
		return $found;
	}

	public function getRequestOptionValueByName($option_type) 
	{
		$found = false;
		foreach ($this->_request_options as $option) 
		{
			if ($option->getOptionName() == $option_type) 
			{
				$found = $option->getOptionValue();
			}
		}
		return $found;
	}
	public function getAllowedMethods() {
		return $this->_allowed_methods;
	}
	public function getMethod() {
		return $this->method;
	}
	public function getRawRequest() {
		return $this->_raw_request;
	}
	public function respond() { //respond to their request
		debug("We are now building a response",5);
		$this->response->build($this->path,$this->query,$this->fragment,$this->method,null);
		$this->response->send();
	}
	public function giveResponseCodeAndClose($error,$conn,$method) {
		$this->response->giveResponseCodeAndClose($error,$conn,$method);
	}
	public function validate() {
		//are we tracing?
		if (substr($this->_raw_request,0,5) == "TRACE") 
		{ 	
			//set trace and bail, we need to know nothing more
			$this->method = "TRACE";
			$this->path = null;
			$this->fragment = null;
			$this->query = null;
			$this->response = new Response($this->conn); //create our response
			return;
		}
		//load their request-line into memory
		$this->_request = explode("\r\n",$this->_raw_request); //separate requests from each line
		/*
		* This is all ciustom code to accomodate nelsons damn program
		*/
		if (count($this->_request) <= 1) {
			$this->_request = explode("\n",trim($this->_raw_request)); //for some reason nelson is not using carriage returns in his program
			//array_shift($this->_request); //remove his first dang newline
		}
		/*
		* end the custom fitting
		*/

		$request_line_parts = explode(" ",$this->_request[0]); //our important thing will always be first
		$this->method = $request_line_parts[0];
		$this->request_uri = $request_line_parts[1]; //this is the actual URI
		debug("URI: " . $this->request_uri,5);
		$this->http_version = $request_line_parts[2]; //this is the version

		//remove our just processed line from the queue
		unset($this->_request[0]);

		//we will have all request headers until we encounter a blank cr/lf line
		foreach ($this->_request as $request_line) {
			//as long as we are getting headers and not the blank line we are going to be happy
			if ($request_line != "") {
				$request_option = new RequestOption($request_line,$this->conn);
				//catch Duplicate Headers
				if ($this->isRequestOption(substr($request_line,0,strpos($request_line,":"))))
					$this->giveResponseCodeAndClose(CODE_400,$this->conn,$this->method);

				array_push($this->_request_options,$request_option);
			} else {
				continue;
			}
		}

		//build our new ful URI from information given to us in the request headers
		//This is how i am viewing this, we are given a URI or path and a host by the client,
		//we want to take that information we were provided with and form a valid URL.
		//Why not use parse_url in this formula!

		$trimmed_uri=trim($this->request_uri); //this line is trimming the URI
		$request_uri_info=parse_url($trimmed_uri); //this line gets the components of the URL.
		//the if...then statement below checks to see if there is a protocol present
		if(!isset($request_uri_info['scheme']))
		{
			//if there is no protocol present, I add one and assign it to the trimmed_uri.
			//After assigning the trimmed_uri, I parse the URL, if the URL is not parsable,
			//explode the trimmed_uri.  This way we can get the scheme and path from the provided information. 
			$trimmed_uri=$this->scheme. '://' . $trimmed_uri;
			if($temp_request_uri_info=@parse_url($trimmed_uri))
			{
				$this->scheme=$temp_request_uri_info['scheme'];
			}
			else
			{
				$this->scheme = "http";
			}
		}
		else
		{
			$this->scheme=$request_uri_info['scheme'];
			debug ("Has the scheme " . $this->scheme, 5);
			$this->path=$request_uri_info['path'];
		}
		//the if...then statement below checks to see if there is a host present
		if(!isset($request_uri_info['host']))
		{
			$testing_host=$this->getRequestOptionValueByName("Host");
			if($testing_host==false)
			{
				debug("No Host was provided", 5);
				$this->giveResponseCodeAndClose(CODE_400,$this->conn,$this->method);
			}
			else
			{
				$this->host=$this->getRequestOptionValueByName("Host");	
				debug("Host found: " . $this->host,5);
			}
		}
		else
		{
			$this->host=$request_uri_info['host'];
			debug("There is a host: " . $this->host, 5);
		}

		//parser added for path control
		if (!isset($request_uri_info['path'])) 
		{
			//there was no path that could be processed
			//this means we are not absolute, and must be relative, just tack the path in here from the request line
			//$test_path=$this->request_uri;
			$this->path = $this->request_uri;
			
			
		} 
		else
		{
			$this->path = $request_uri_info['path'];
		}

		
		debug ("Parsed Path: " . $this->path, 5);

		//the if...then statement below checks to see if there is a port number
		if(isset($request_uri_info['port']))
		{
			$this->port=':' .$request_uri_info['port'];
		}

		//checking to see if there is a query
		if(isset($request_uri_info['query']))
		{
			$this->query='?' .$request_uri_info['query'];
			debug("Given Query String: " . $this->query,5);
		}

		//checking to see if there is a fragment
		if(isset($request_uri_info['fragment']))
		{
			$this->fragment='#' .$request_uri_info['fragment'];
		}

		//here is the new built URI from the information we are given
		$this->request_uri= $this->scheme. '://' .$this->host .$this->port .$this->path .$this->query .$this->fragment;
		debug("Compiled URI: " . $this->request_uri, 5);
		
		//validate all given information(except request headers, they are self validating
		//valid URI?
		/*
		   //@TODO - request nelson to compile this in
		if (!filter_var($this->request_uri, FILTER_VALIDATE_URL, array(FILTER_FLAG_SCHEME_REQUIRED,FILTER_FLAG_HOST_REQUIRED,FILTER_FLAG_PATH_REQUIRED)))
			Response::giveResponseCodeAndClose(CODE_400,$conn,$this->method);
		*/
		//are we talking the same protocol?
		if (!in_array($this->http_version,$this->_allowed_http_versions)) {
			debug("They tried to use version {$this->http_version}, and we don't",4);
			$this->giveResponseCodeAndClose(CODE_505,$this->conn,$this->method);
		}
		//did they ask for an allowable method
		if (!in_array($this->method,$this->_allowed_methods)) {
			debug("They tried method " . $this->method . " and we don't support that",5);
			$this->giveResponseCodeAndClose(CODE_501,$this->conn,$this->method); //we really should be giving them an options rsponse so they know what we can do
		}
		debug("Done loading request",5);
	}
}
