<?php
//Response
//By: Ryan Null
//@Summary: This class handles server responses to a client according to RFC 2616 Standards Document

class Response {
	private $options;
	private $conn;
	public $status;
	private $response;
	public $entity;
	public $body;
	private $mime_type;
	private $path;
	private $head;
	private $method;
	public $second_method;
	private $is_built = false;


	public function __construct($conn) {
		//how do we build a response?
		$this->options = array();
		$this->conn = $conn;
		$this->status = CODE_200; //assume we are fine, all classes throw their own errors.
		$this->response = "";
	}
	public function addOption($option,$value) {
		debug("SET RESPONSE HEADER $option to '$value'",3);
		$new_option = new ResponseOption($option,$value,$this->conn);
		if ($new_option) //did we create a valid option?
			array_push($this->options,$new_option);
		else //no, we did something wrong, say so
			$this->giveResponseCodeAndClose(CODE_500,$this->conn);
	}
	public function giveResponseCodeAndClose($code,$conn,$method="") {
		global $currentRequest;
		debug("There was an error with the request, exiting",4);
		$this->status = $code;
		//use this switch later to be able to build certain entities for errors
		switch($code) {
			case CODE_201:
			case CODE_204:
			case CODE_300:
			case CODE_301:
			case CODE_302:
			case CODE_304:
			case CODE_404:
			case CODE_401:
			case CODE_403:
			case CODE_405:
			case CODE_412:
			case CODE_406:
			case CODE_400:
			case CODE_411:
			case CODE_505:
			case CODE_501:
				break;
			default:
				$this->status = CODE_500;
		}
		if ($this->is_built) {
			//do nothing
		} else {
			$this->build(null,null,null,$code,$method,$this->second_method);
			$this->is_built = true;
		}
	}
	public function build($path,$query,$fragment,$method,$second_method) {
		//static $flag=0;
		if($this->is_built)
			return;
		$this->response = "";
		debug("This is the case: " . $method,5);
		$this->path = $path;
		$real_path=DOCUMENT_ROOT . $path;
		debug("Real path " . $real_path, 5);
		$this->second_method = $second_method;
		debug("The second method: " . $second_method,5);
		$this->method = $method;
		
		switch ($method) {
			case "GET":
				$this->check_redirect($path,$method);		
				$perms=$this->build_option_allow($path,$query,$fragment);
				debug("permission: $perms", 5);
				if(stristr( $perms, 'GET' ) == true || $perms == "")//if permissions do not contain GET, or it is blank (blank equals does not exist)
				{
					debug("method here is, about to go in build: " . $method, 5);
					$this->response .= $this->build_entity($path,$query,$fragment);
					$this->response .= $this->build_head($path,$query,$fragment);
					$this->response .= $this->build_get($path,$query,$fragment);
				}
				else//throw a 403 response
				{ 
					$this->giveResponseCodeAndClose(CODE_403, $this->conn, $method);	
				}
				break;
			case "HEAD": 
				$this->check_redirect($path,$method);		
				$this->response .= $this->build_entity($path,$query,$fragment);
				$this->response .= $this->build_head($path,$query,$fragment);
				break;
			case "PUT":
			case "DELETE":
                //the entity class will take care of checking the permissions and everything else, it has already been instantiated and set all of the appropraite headers
                $this->build_entity($path,$query,$fragment);
                $this->response .= $this->build_head($path,$query,$fragment);
                $this->entity->data = $this->entity->data2;
				break;
			case "POST":
                $this->build_entity($path,$query,$fragment);
                $this->response .= $this->build_head($path,$query,$fragment);
                $this->response .= $this->entity->data2;
                break;
			case "OPTIONS":
				if ($path != "*") 
					$this->response .= $this->build_entity($path,$query,$fragment);
                else {
				    $perms=$this->build_option_allow($path,$query,$fragment);
				    $this->addOption("Allow",$perms);
                }
				$this->addOption("Content-Length", "0");
				$this->addOption("Content-Type", "message/http");
				$this->response .=$this->build_head($path,$query,$fragment);
				break;
			case "TRACE":
				global $currentRequest;
				//determine size of request
				//add in our headers here
				$this->addOption("Content-Type","message/http");//adding content-type
				$this->addOption("Content-Length",strlen($currentRequest->getRawRequest()));
				$this->response .= $this->build_head(null,null,null);
				debug($currentRequest->getRawRequest(), 5);
				$this->response .= $currentRequest->getRawRequest();
				break;
			default:
				debug("Does it come here?",5);
				if($second_method=='HEAD')
				{
					$amount=strlen($this->build_error_entity($method));
					$this->response .= $this->build_head(null,null,null);
				}
				else
				{
					$amount=strlen($this->build_error_entity($method));
					$this->response .= $this->build_head(null,null,null);
					$this->response .= $this->build_error_entity($method);
				}
		}
		$this->response .= "\n"; //add our final newline, after entity and all
		$this->is_built = true;
	}
	public function build_get($path,$query,$fragment) {
		if ($this->is_built) 
			return;
		//begin building our attached entity
		$response = $this->entity->fetch(); //add our entity to the output

		return $response;
	}
	public function build_error_entity($error) {
//if we need to return an entity with an error, put it in the switch below, if we don't ignore it and we will return a blank entity
		switch ($error)
		{
			case CODE_301:
				$filename="301.html";
				break;
			case CODE_302:
				$filename="302.html";
				break;
			case CODE_300:
				$filename='300.html';
				break;
				/* 304 doesnt return an entity
			case CODE_304:
				$filename="304.html";
				break;
				*/
			case CODE_406:
				$filename="406.html";
				break;
			case CODE_403:
				$filename="403.html";
				break;
			case CODE_404:
				$filename="404.html";
				break;
			case CODE_411:
				$filename="411.html";
				break;
            case CODE_413:
                $filename="413.html";
                break;
            case CODE_414:
                $filename="414.html";
                break;
            /*
			case CODE_501:
				$filename="501.html";
				break;	
            */
			case CODE_401:
				$filename="401.html";
				break;
			case CODE_505:
				$filename="505.html";
				break;
			default:
				$filename="error.html";
				break;
		}
		$page = file_get_contents(SERVER_DOC_ROOT . $filename);
		//$this->addOption("Content-Length", strlen($page));
		$this->addOption("Transfer-Encoding","chunked");
		$this->addOption("Content-Type","text/html");
		$this->body = $page;

		return $page;
	}
		
	public function build_entity($path,$query,$fragment) {
		if ($this->is_built) 
			return;

		//fetch our entity object and set some basic vars
		$this->entity = new Entity(rawurldecode($path),$this->conn);
		
		//to gain mime type information, first we want to check if the information is within the content.
		//if not, just add the mime type information based on the extension.
		$file = $path;
		$real_path= DOCUMENT_ROOT . $file;//this is getting the real path of the request.
		debug("This is the path: $file", 5);
		debug("This is the real target: $real_path", 5);		


		//debug(print_r($this->options),5);
	}

	public function build_head($path,$query,$fragment) 
	{
		if ($this->is_built) 
			return;
		global $currentRequest;
		$response = "";
		$this->addOption("Date",gmdate("D, d M Y H:i:s ") . "GMT");
		$this->addOption("Server","Dien " . SERVER_VERSION);

		//if they want to close it, we must comply
		try {
			if ( is_object($currentRequest) && $currentRequest->getRequestOptionValueByName("Connection") == "close") {
				$this->addOption("Connection","close");
			}
		} catch (Exception $e) {
			//the current reqest class has not been completed, just keep going
			debug($e->getMessage(),5);
			$this->addOption("Connection","close");
		}

		//begin building our response
		$response = HTTP_VER . " " . $this->status . "\n";
		foreach ($this->options as $option) {
			$response .= $option->getText();
		}
		$response .= "\n"; //add our final newline after the headers and before the entity
		$this->head = $response;
		return $response;
	}
	
	public function build_option_allow($path,$query,$fragment) 
	{
		global $currentRequest;
		//this will need to return the string of the compiled options and not include "Allow"

		//if they asked for global options
		$test_path=DOCUMENT_ROOT . $path;
		debug("Path is $test_path", 5);		
		if($path=="*")
		{
			return implode(",",$currentRequest->getAllowedMethods());

		}
		//we dont need to test for a trainling slash, it forces a premature exit
		if( is_dir($test_path) || is_file($test_path) )
		{
			//add information about how if we do not have permission to show the resource,
			//show what is allowed for that resource.
			$file=DOCUMENT_ROOT . $path;
			$permissions=$this->FilePermission($file);
			debug("This is the permissions $permissions", 5);
			
			//if others can read it, they are allowed to GET, HEAD, OPTIONS
			if(($permissions[0]>=4)&&($permissions[1]>=4)&&($permissions[2]>=4)) 
			{
				return implode(",", $currentRequest->getAllowedMethods());
			}
			elseif(($permissions[0]<4)&&($permissions[1]<4)&&($permissions[2]<4)) 
			{
				//return $this->giveResponseCodeAndClose(CODE_403,$this->conn);
				//$perms=implode(",", $currentRequest->getAllowedMethods());
				$index=array_search('OPTIONS', $currentRequest->getAllowedMethods());//if permissions do not contain GET
				$index_two=array_search('TRACE', $currentRequest->getAllowedMethods());//if permissions do not contain GET
				$perms=$currentRequest->getAllowedMethods();
				$perms=$perms[$index] . "," . $perms[$index_two];
				return $perms;
			}	
		}
	}
	
	//this function changes the octal permissions from using the function fileperms() into permissions we are use
	//to seeing
	public function FilePermission($file)
	{
		if(!file_exists($file))
		{	
			return false;
		}
    		$permission_octal= fileperms($file);
		
		$cut=2;
		$permission=substr(decoct($permission_octal), $cut);
		$permissions=ltrim($permission, "0");
		return $permissions;
	}

	
	//this is the main function that gets the mime type
	public function MimeType($path) 
	{ 
      		// get base name of the filename provided by user 
      		$filename = basename($path); 

      		// take the last part of the file to get the file extension 
      		$file_ext = @substr($filename,strpos($filename,"."));

		// find mime type 
		$mt =  $this->FindType($file_ext); 
		debug("Content Type is: " . $mt,5);

		return $mt;
   	}
	public function charsetType($path) 
	{ 
      		// get base name of the filename provided by user 
      		$filename = basename($path); 

      		// take the last part of the file to get the file extension 
      		$file_ext = @substr($filename,strpos($filename,"."));

		// find mime type 
		$mt =  $this->CharsetFindType($file_ext); 
		debug("Charset Type is: " . $mt,5);

      	return $mt;
   	}
	public function CharsetFindType($ext) 
	{ 
      		// goes to the array of mime types to the mime type.
      		$charset= $this->CharsetArray(); 
			//we want to check for the hidden
			foreach ($charset as $type=>$enc) {
				if ( preg_match("@\." . $type . "@iU", $ext))
					return $enc;
			}

       		return "ISO-8859-1";
   	}	
	//This function finds the mime type
	public function FindType($ext) 
	{ 
      		// goes to the array of mime types to the mime type.
      		$mimetypes= $this->MimeArray(); 

			//we want to check for the hidden
			foreach ($mimetypes as $type=>$enc) {
				$regex = "/" . $type . "/iU";
				if ( preg_match($regex,$ext))
					return $enc;
			}
       
      		// return mime type for extension 
      		if (isset($mimetypes[$ext])) 
			{ 
         		return $mimetypes[$ext];           
      		} 
			else 
			{ 
         		return 'application/octet-stream'; 
      		}
   	}
	public function MimeArray()
        {
                return array(
                        "doc" => "application/msword",
                        "bin" => "application/octet-stream",
                        "exe" => "application/octet-stream",
                        "class" => "application/octet-stream",
                        //"so" => "application/octet-stream",
                        "dll" => "application/octet-stream",
                        "pdf" => "application/pdf",
                        "tar" => "application/x-tar",
                        "src" => "application/x-wais-source",
                        "zip" => "application/zip",
                        "bmp" => "image/bmp",
                        "gif" => "image/gif",
                        "jpeg" => "image/jpeg",
                        "jpg" => "image/jpeg",
                        "png" => "image/png",
                        "tiff" => "image/tiff",
                        "tif" => "image/tif",
                        "css" => "text/css",
                        "html" => "text/html",
                        "htm" => "text/html",
                        "asc" => "text/plain",
                        "txt" => "text/plain",
                        "rtf" => "text/rtf",
                        "xml" => "text/xml"
                        );
        }
	public function CharsetArray()
	{
	    	return array (
			"jis" => "iso-2022-jp",
			"koi8-r" => "koi8-r",
			"euc-kr" => "euc-kr"
			);
	}
	public function check_redirect($path,$method)
	{
		$path_1=$path;
		$pattern_1='/index.html';
		$pattern_1=preg_quote($pattern_1, '/');
		//$pattern_array=array("/^(\S*)" . $pattern_1 . "/"=>'\1/fairlane.html', "/^(\S*)\/1\.[123]\/(\S*)/"=>'\1/1.1/\2');
		$pattern_array = array(
				"^(.*)/galaxie(.*)$"=>"\1/fairlane\2"
				);
		debug("path: " . $path,5);

		foreach($pattern_array as $pattern=>$replacement)
		{
			debug("Pattern: " . $pattern,5);
			if(preg_match($pattern, $path_1))
			{
				$result=preg_replace($pattern,$replacement,$path);
				if($path_1 != $result)
				{
					debug("Replacement: " . $replacement,5);
					$this->addOption("Location",$result);
					$this->giveResponseCodeAndClose(CODE_302,$this->conn,$method);
				}
				else
				{
					//do nothing
				}
			}
			else
			{//do nothing
			}
		}
	}

	//Here is the mime type array
	public function validate() {
		//I dont know what this will do in the end but it will be used to validate our output data before we send it.... somehow
	}
	public function transform() {
		//this will go through each of our options and apply any transformation encodings
	
	}
	public function send() 
	{
		global $currentRequest;

		if (!@$this->entity->data)
			$this->entity->data = $this->body;

		//is it a chunked response?
		if ($this->getOptionValue("Transfer-Encoding") == "chunked") {

			Connection::send($this->conn,$this->head);
			
			if ($this->method != "HEAD" && $this->second_method != "HEAD") {
				$split_file = explode("\n",$this->entity->data);
				for ($i = 0; $i < count($split_file); $i ) {
					$chunk = $split_file[$i] . "\n";
					$i++;
					if (@$split_file[$i]) {
						$chunk .= $split_file[$i] . "\n";
						$i++;
					}
					$size = sprintf("%x",strlen($chunk));
					$chunk = $size . "\n" . $chunk;
					Connection::send($this->conn,$chunk);
				}
				Connection::send($this->conn,"0\n\n");
			}
		} else {
			Connection::send($this->conn,$this->response);
		}
	}

	public function setOption($overwriteHeader, $valueOverwritten)
	{
		//brings in the the header you want overwritten and its value
		foreach ($this->options as $option)
		{
			if($option->getOptionName()==$overwriteHeader)
			{
				$option->setOptionValue($valueOverwritten);
			}
			debug("options: " . $option->getOptionValue(),5);
		}
	}
	public function getOptionValue($name) {
		foreach ($this->options as $option) {
			if ($option->getOptionName() == $name) {
				return $option->getOptionValue();
			}
		}
		return false;
	}

}
