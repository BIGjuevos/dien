<?php
class Entity {
	private $target_dir;
	private $real_dir;
	private $real_target;
	private $filename;
	private $target;
	public $data = null;
    	public $data2 = "";
	private $conn;
	private $allow_options=array();
	private $creation_date;
	private $etag;
	private $request_etags=array();//there can be more than one etags
	private $date_status = array("If-Modified-Since"=>0, "If-Unmodified-Since"=>0, "If-Match"=>0, "If-None-Match"=>0);
						  //0 = default value
						  //1 = ignore me
						  //2 = client copy is good
						  //3 = client copy is bad
	//private $request_accept=array();
	private $cgi;


	public function __construct($target,$conn) {
		$this->target = $target;
		$this->target_dir = dirname($target);
		$this->filename = basename($target);
		$this->conn = $conn;
		global $currentRequest;

		//time to translate to something we can actually get
		$this->real_target = DOCUMENT_ROOT . "/" . $this->target_dir . "/" . $this->filename;
		debug("our target dir is " . $this->target_dir,4);
		debug("our filename is " . $this->filename,4);
		debug("our target is " . $this->target,4);
		debug("our translated target is " . $this->real_target,4);
		$this->creation_date = filemtime($this->real_target);
		debug("creation date of of our target is " . date ("F d Y H:i:s." , $this->creation_date), 4);
		//debug("creation date of of our target is " . $this->creation_date, 4);
		


		//are they allowed to accedd this item
		$tree = explode("/",$this->target);
		$protected = false;
		for ($i=0; $i < count($tree); $i++) {
			$directory = "";
			for ($j = 0; $j <= $i; $j++)
				$directory .= $tree[$j] . "/";
			debug("searching for protection in: " . DOCUMENT_ROOT . $directory . "WeMustProtectThisHouse!",5);
			if (file_exists(DOCUMENT_ROOT . $directory . "WeMustProtectThisHouse!")) {
				$protected = DOCUMENT_ROOT . $directory . "WeMustProtectThisHouse!";
				break;
			}
		}

		if ($protected) {
			debug("This directory is portectd via the file in " . $protected, 5);
			$ok = $this->build_auth($protected);
			if (!$ok) {
				$currentRequest->giveResponseCodeAndClose(CODE_401,$this->conn,$currentRequest->getMethod());
				return; //dont build it
			} else {
				//do nothing allow them to continue as they properly authenticated
				$temp_methods = array("PUT","DELETE");
				if ( !in_array($currentRequest->getMethod(),$this->allow_options) && in_array($currentRequest->getMethod(),$temp_methods)) {
					debug("setting a 405",5);
		    		$currentRequest->giveResponseCodeAndClose(CODE_405,$this->conn,$currentRequest->getMethod());
					return false;
		        }
			}
		} else {
			$temp_methods = array("PUT","DELETE","POST");
			if ( in_array($currentRequest->getMethod(),$temp_methods)) {
				debug("setting a 405",5);
		    	$currentRequest->giveResponseCodeAndClose(CODE_405,$this->conn,$currentRequest->getMethod());
            }
        }

		debug("Prior to intruiging check",5);
		debug("Current status: " . $currentRequest->response->status,5);
		debug("Auth State: " . @$ok,5);

		//checking to see if it is a CGI file
		$check_ext=$this->get_extension($this->target);
		debug("Extension: " . $check_ext,5);
		if(($check_ext == ".cgi") && is_file($this->real_target))
		{
			debug("This is a CGI file",5);
			switch ($currentRequest->getMethod()) {
                case "OPTIONS":
                    $currentRequest->response->addOption("Allow","POST,GET,HEAD");
                    return;
				case "GET":
				case "POST":
				case "HEAD":
					//go to the CGI class
					global $cgi_file;
					debug("CGI file: " . $this->target,5);
					if ($currentRequest->getMethod() == "POST") {
						$this->cgi=new Cgi($this->target,$this->conn,$currentRequest->getBody());
					} else {
						$this->cgi=new Cgi($this->target,$this->conn); //makes a new CGI program
					}
					$output=$this->cgi->get_output(); //receives the output of the program
					$this->data=$output;
			        $offset=strpos($this->data,"\n\n");
			        $this->data=trim(substr($this->data,$offset));
					debug("Output: " . $output,5);
			
					if(preg_match("@Content-[Tt]ype: (.*)[\r\n]+@i", $output, $match))
					{
						debug(print_r($match),5);
						$content_type=$match[1];
						$currentRequest->response->addOption("Content-Type", $content_type);
					}
					if(preg_match("@Status: (.*)[\r\n]+@i", $output, $match))
					{
						$status=$match[1];

						debug("Status: " . $status,5);//if status does not equal to 200 then change the status else ignore.		
						$currentRequest->response->status=$match[1];
					}
					if(preg_match("@Location: (.*)[\r\n]+@i",$output,$match))
					{
                        $currentRequest->response->addOption("Location",$match[1]);
                        $this->data = "";
                        $currentRequest->giveResponseCodeAndClose(CODE_302,$this->conn);
					}
					debug("Content_type: " . $content_type,5);
					$currentRequest->response->addOption("Transfer-Encoding", "chunked");
					break;
				default:
					$currentRequest->response->giveResponseCodeAndClose(CODE_405,$this->conn);
			}
			return; //dont fetch an entity body, trust us
		}

	debug("Status before unsafe operations: " . $currentRequest->response->status,5);

        switch ($currentRequest->getMethod()) {
            case "PUT":
                debug("Somebody wants to store something on our filesystem",5);
                if ( $this->put_file() ) {
                    //happy header are already set
                    return;
                } else {
                    //there was an error we didnt catch already, throw a 500
			        $currentRequest->giveResponseCodeAndClose(CODE_500,$this->conn,$currentRequest->getMethod());
                    return;
                }
                break;
            case "DELETE":
                debug("DELETE.... OK if you insist",3);
                if ($this->delete_file() ) {
                    //happy headers are set in the function
                    return;
                } else {
                    //there was an error we didnt catch already, throw a 500
			        $currentRequest->giveResponseCodeAndClose(CODE_500,$this->conn,$currentRequest->getMethod());
                    return;
                }
                break;
            default: //by default fetch an entity
        		$trash = $this->fetch(); //we dont fetch it twice, this first time does all of the analysis, and the second request
								 //doesnt actually fetch it, it just returns the data we have stored in memory.
        	}
	}
	public function build_auth($wmpth) {
		global $currentRequest;
		//determine our auth type
		$wmpth = explode("\n",file_get_contents($wmpth)); //reads on file
		//associative aray of user=>password
		$users = array(); //creates user array
		$request_array=array();

		//parse in the file
		foreach ($wmpth as $id=>$line) {//an array containing the lines of the file
			//assign the realm
			if ( preg_match("@ALLOW-([A-Z]+)$@", $line, $match) )
			{//grab the allow statement
				$this->allow_options[]=$match[1];
			}
			if ( preg_match("@authorization-type=(.*)$@",$line,$match) ) $type = $match[1];
			if ( preg_match("@realm=\"(.*)\"$@",$line,$match) ) $config_realm = $match[1];
			if ( preg_match("@^([a-z]+):.*:([a-z0-9]+)$@iU",$line,$match) ) $users[$match[1]] = $match[2];//for digest
			if ( preg_match("@^([a-z]+):([a-f0-9]+)$@",$line,$match) ) $users[$match[1]] = $match[2];//for basic
		}

		debug("Allowed Auth Options: " . var_export($this->allow_options,true),5);

		//if not enough information was procided
		if ( (empty($users) || !$config_realm || !$type)) {
            		if ( in_array($currentRequest->getMethod(),$this->allow_options) ) {
                		//Ryan says, screw nelson,we will allow unsafe operations on directories if the WMPTH file allow is
                		return true;
            		} else {
				//they aren't allowed to do that
	    			$currentRequest->giveResponseCodeAndClose(CODE_405,$this->conn,$currentRequest->getMethod());
				return true;
	           	}
		} else {
		}
				


		//ok, lets see if they are tryin to authenticate
		if ($clientAuth = $currentRequest->getRequestOptionValueByName("Authorization")) {//get authentication value
			debug("Request: " . $currentRequest->getRequestOptionValueByName("Authorization"),5);
			debug("Type: " . $type,5);
			$currentRequest->auth_type = $type;
			if ($type == "Basic") {
				$clientAuth = explode(" ",$clientAuth);//explode the authenication value by space
				//basic authentication
				$creds = explode(":",base64_decode($clientAuth[1]));
				$currentRequest->user = $creds[0];
				print_r($users);
				foreach ($users as $user=>$pass) {
					if ($user == $creds[0] && $pass == md5($creds[1])) {
						return true; //they successfully authenticated, allow them in
					}
				}
			} else if ($type == "Digest") {
				//do digest based method
				//it is already exploded on the spaces...so we have to trim...we should see what exists in the request
				debug("Does it come in the Digest loop",5);
				if ( preg_match("@realm=\"(.*)\"@U",$clientAuth,$match) ) $realm = $match[1];
				if ( preg_match("@username=\"(.*)\"@U",$clientAuth,$match) ) $username = $match[1];
				if ( preg_match("@nonce=\"(.*)\"@U",$clientAuth,$match) ) $nonce = $match[1];
				if ( preg_match("@uri=\"(.*)\"@U",$clientAuth,$match) ) $uri = $match[1];
				if ( preg_match("@algorithm=(MD5|MD5-sess)@U",$clientAuth,$match) ) $algorithm = $match[1]; else $algorithm="MD5";
				if ( preg_match("@response=\"(.*)\"@U",$clientAuth,$match) ) $response = $match[1];
				if ( preg_match("@qop=(auth|auth-int)@U",$clientAuth,$match) ) $qop = $match[1];
				if ( preg_match("@nc=(.*),@U",$clientAuth,$match) ) $nc = $match[1];
				if ( preg_match("@cnonce=\"(.*)\"@U",$clientAuth,$match) ) $cnonce = $match[1];

				$currentRequest->user = $username;

				//check the nonce value to see if it is valid
				$generated_nonce=md5("salt");
				if($generated_nonce!=$nonce)
				{
					$currentRequest->giveResponseCodeAndClose(CODE_401,$this->conn,$currentRequest->getMethod());
					debug("nonces were not equal",5);
				}
				//let us look at the response value of the request.
				//we must compare what they gave use as a response to what we have in our file
				//go through our user array for matching users and passwords, we also must include the realm.
				
				foreach($users as $user=>$pass)
				{
					//go through the loop
					//if the md5 of the request_digest matches the response, then let them in
					//1. calculate $request_diguest
					if($algorithm=="MD5")
					{
						//$MD5_alg=$user . ":" . $realm . ":" . $pass;
						$MD5_alg=$pass;
					}
					elseif($algorithm=="MD5-sess")
					{
						/*$MD5_alg=md5($user . ":" . $realm . ":" . $pass) . ":" . $nonce . 
						":" . $cnonce;*/
						$MD5_alg=$pass . ":" . $nonce . ":" . $cnonce;
					}
					else
					{
						//debug("could not determine algorithm",5);
						//$currentRequest->giveResponseCodeAndClose(CODE_400,$this->conn,$currentRequest->getMethod());

					}

					if($qop=="auth")
					{
						$qop_alg=$currentRequest->getMethod() . ":" . $uri;
					}
					elseif($qop=="auth-int")
					{
						$qop_alg=$currentRequest->getMethod() . ":" . $uri . ":" . md5($this->get_file());
					}
					else
					{
						//$currentRequest->giveResponseCodeAndClose(CODE_400,$this->conn,$currentRequest->getMethod());
						//debug("could not determine qop",5);
					}

					$request_digest=md5($MD5_alg . ":" . $nonce . ":" . $nc . 
					":" . $cnonce . ":" . $qop . ":" . md5($qop_alg));
					if($request_digest==$response)
					{
						if ($realm != $config_realm)
							continue;
						else if ($username != $user)
							continue;
						else {
							if($qop=="auth")
							{
								$respose=$response . md5(":" . $uri); 
							}
							elseif($qop="auth-int")
							{
								$respose=$response . md5(":" . $uri . ":" . "00000000000"); 								
							}
							$currentRequest->response->addOption("Authentication-Info","Digest cnonce=\"$cnonce\", qop=$qop, rspauth=\"$response\", nc=$nc");

							return true;
						}
					}
					else
					{
						debug("digests were not equla",5);
						//$currentRequest->giveResponseCodeAndClose(CODE_401,$this->conn,$currentRequest->getMethod());

					}
					debug("algorithm: " . $algorithm,5);
					debug("qop: " . $qop,5);
					debug("Response: " . $response,5);
					debug("A1: " . $MD5_alg . ":" . md5($MD5_alg),5);
					debug("A2: " . $qop_alg . ":" . md5($qop_alg),5);
					debug("Method: " . $currentRequest->getMethod(),5);
					debug("user: " . $user,5);
					debug("nc: " . $nc,5);
					debug("pass: " . $pass,5);
					debug("realm: " . $realm,5);
					debug("uri: " . $uri,5);
					debug("Our Request Digest: " . $request_digest,5);
					debug("======================================",5);
				}
			} else {
				$currentRequest->giveResponseCodeAndClose(CODE_500,$this->conn,$currentRequest->getMethod());
			}
		}

		//they did not request to auth, or they got it wrong
		if ($type == "Basic") {
			$currentRequest->response->addOption("WWW-Authenticate","Basic realm=\"$config_realm\"");
		} else if ($type=="Digest") {
			$opaque = md5(rand(1,10000));
			$nonce = md5("salt");
			$algorithm = "MD5";
			$qop = "auth,auth-int";
			$currentRequest->response->addOption("WWW-Authenticate","Digest realm=\"$config_realm\", nonce=\"$nonce\", algorithm=$algorithm, qop=\"$qop\", opaque=\"$opaque\", stale=\"false\"");
			
		}
		return false; //the need to auth, returning false wil have us return a 401
	}
	/*
	public function getOptions()//allow the response class to get the options array for the user
	{
		return $this->allow_options;
	}
	public function transferEncoding() {
		global $currentRequest;
		$prefs = array(
				"compress"=>0,
				"gzip"=>0,
				"deflate"=>0,
				"identity"=>0);
		$encodings = explode(",",$currentRequest->getRequestOptionValueByName("Transfer-Encoding"));
		if (empty($encodings))
			return "identity";

		foreach ($encodings as $encoding) {
			$encoding = trim($encoding);
			preg_match("/(gzip|compress|deflate|identity|\*)([ ]*;q=([0-9]\.[0-9])|)/iU",$encoding,$qvals);
			if (array_key_exists($qvals[1],$prefs)) {
				if (is_numeric($qvals[3])) {
					$prefs[$qvals[1]] = $qvals[3];
				} else {
					$prefs[$qvals[1]] = 1;
				}
			}
		}
		arsort($prefs);
		debug("Encoding Prefs:",4);
		var_dump($prefs);
		$types = array_keys($prefs);
		$currentRequest->response->addOption("TE:",$types[0]);
		debug("WE are encoding in: " . $types[0],5);
		return $types[0];
	}
	*/
	public function fetch() {
		global $currentRequest;
		if ($this->data !== null) //we haven't already fetched it
			return $this->data;

		debug("Fetching the entity...",5);

		//$this->etag='"' . $this->creation_date . "-" . fileinode($this->real_target) . "-" . filesize($this->real_target) . '"';
		//debug("Our generated ETag: " . $this->etag,3);

		//what are we trying to find
		//@TODO - we need to be avble to recognize when someone wants us to parse code and not just return a document, I am unsure if this is an assignment or not
		//do they want a directory?

		//do we need to substitute in our special index file?
		if (is_file($this->real_target . "fairlane.html")) {
			debug("looking for index file: " . $this->real_target . "fairlane.html",5);
			$this->real_target .= "fairlane.html";
		} else {
			debug("no index file found: " . $this->real_target . "fairlane.html",5);
		}

		//add the etag
		//debug("Last-Modified: " . gmdate("D, d M Y H:i:s ",$this->creation_date) . "GMT");
		if (file_exists($this->real_target))
			$currentRequest->response->addOption("Last-Modified",gmdate("D, d M Y H:i:s ",filemtime($this->real_target)) . "GMT");

		if ($currentRequest->isRequestOption("If-Modified-Since"))
			$this->if_modified_since($currentRequest->getRequestOptionValueByName("If-Modified-Since"));
		if ($currentRequest->isRequestOption("If-Unmodified-Since"))
			$this->if_not_modified_since($currentRequest->getRequestOptionValueByName("If-Unmodified-Since"));

		debug("We were passed the ETags: " . $currentRequest->getRequestOptionValueByName("If-Match"),5);
		if ($currentRequest->isRequestOption("If-Match"))
			$this->if_match($currentRequest->getRequestOptionValueByName("If-Match"));
		if ($currentRequest->isRequestOption("If-None-Match"))
			$this->if_none_match($currentRequest->getRequestOptionValueByName("If-None-Match"));

		//bail out, and dont return entiry
		if ($currentRequest->response->status == CODE_412)
			return;
		
		if( is_dir($this->real_target)) {
			//catch a 301 for directories
			$this->etag='"' . filemtime($this->real_target) . "-" . fileinode($this->real_target) . "-" . filesize($this->real_target) . '"';
			debug("Our generated ETag: " . $this->etag,3);

			if (substr($this->target,-1) == "/") 
			{
				//they asked properly
				$currentRequest->response->addOption("ETag",$this->etag);

			 	if (
                                        ($this->date_status["If-Modified-Since"] == 3) || 
                                        ($this->date_status["If-Unmodified-Since"] == 3) || 
                                        ($this->date_status["If-Match"]==3) || 
                                        ($this->date_status["If-None-Match"]==3) )
                                {
					
					$this->data = $this->get_dir();
					$currentRequest->response->addOption("Content-Type", "text/html");
			        //$currentRequest->response->addOption("Content-Length",$this->length());
				}
				else if (
                                        //either they were up to date when we checked it, or they didnt send us anything
                                        $this->date_status["If-Modified-Since"] <= 1 && 
                                        $this->date_status["If-Unmodified-Since"] <= 1 &&
                                        $this->date_status["If-Match"] <= 1 &&
                                        $this->date_status["If-None-Match"] <= 1) 
				{
					$this->data = $this->get_dir();	
					$currentRequest->response->addOption("Content-Type", "text/html");
			        //$currentRequest->response->addOption("Content-Length",$this->length());
				}
				else
				{
                                        debug("if-match value: " . $this->date_status["If-Match"] . " and " . $this->date_status["If-None-Match"],5);
                                        $currentRequest->giveResponseCodeAndClose(CODE_304,$this->conn,$currentRequest->getMethod());
                                }
				
			} else {
				//they are idiots and tell them that
				global $currentRequest;
				$currentRequest->response->addOption("Location",$this->target . "/");
				debug("New Location: " . $this->target . "/",4);
				$currentRequest->giveResponseCodeAndClose(CODE_301,$this->conn,$currentRequest->getMethod());
			}
		} else if ( is_file($this->real_target) ) {
			//is the file they want out of date?
			//only call if they ask for it
			$this->etag='"' . filemtime($this->real_target) . "-" . fileinode($this->real_target) . "-" . filesize($this->real_target) . '"';
			debug("Our generated ETag: " . $this->etag,3);
	
			$currentRequest->response->addOption("ETag",$this->etag);

			//if any mothods are out of date
			if (
					($this->date_status["If-Modified-Since"] == 3) || 
					($this->date_status["If-Unmodified-Since"] == 3) || 
					($this->date_status["If-Match"]==3) || 
					($this->date_status["If-None-Match"]==3) )
			{
				//they are out of date, one way or another
				//therefore return their stuff
				debug("if-match value: " . $this->date_status["If-Match"] . " and " . $this->date_status["If-None-Match"],5);
				$this->data = $this->get_file();
				$charset=$currentRequest->response->charsetType($this->real_target);
				$mime_type=$currentRequest->response->MimeType($this->real_target);
	                	$currentRequest->response->addOption("Content-Type",$mime_type . "; charset=" . $charset);
		                $currentRequest->response->addOption("Content-Length",$this->length());

			} else if (
					//either they were up to date when we checked it, or they didnt send us anything
					$this->date_status["If-Modified-Since"] <= 1 && 
					$this->date_status["If-Unmodified-Since"] <= 1 &&
					$this->date_status["If-Match"] <= 1 &&
					$this->date_status["If-None-Match"] <= 1) {
				//they didnt ask for any methods for us to check
				//give them their file
				$this->data = $this->get_file();
				$charset=$currentRequest->response->charsetType($this->real_target);
				debug("Charset: " . $charset,5);
				$mime_type=$currentRequest->response->MimeType($this->real_target);
		                $currentRequest->response->addOption("Content-Type",$mime_type . "; charset=" . $charset);
		                $currentRequest->response->addOption("Content-Length",$this->length());

			} else {
				//they are up to date, tell them to leave us alone
				debug("if-match value: " . $this->date_status["If-Match"] . " and " . $this->date_status["If-None-Match"],5);
				$currentRequest->giveResponseCodeAndClose(CODE_304,$this->conn,$currentRequest->getMethod());
			}
			debug("DUMP OF STATUSES: " . var_export($this->date_status),5);
		} else {
			//looking through the directory
			$target=$this->target;
			$file_name=$this->filename;
        		$target_dir=$this->target_dir;
        		$directory=DOCUMENT_ROOT . $target_dir;
			
			$open_dir=opendir($directory);
			$files=$this->get_file_alias($open_dir, $directory, $file_name);

			if(!empty($files))
			{
				//do the check for accept
				//if there is an accept value send in the files array as well
				//return files within the array that fits the accept values
				global $currentRequest;
				debug(var_export($files,true), 5);
				debug("original target: " . $this->real_target,5);

				if ($currentRequest->isRequestOption("Accept"))
				{
					$accept_files=$this->accept($currentRequest->getRequestOptionValueByName("Accept"), $files);
					debug(print_r($accept_files),5);
					if(count($accept_files)==1)
					{
						$this->real_target=$this->return_file_suggestion($accept_files);						
						debug("redirect: " . $this->real_target,5);
						$directory_name=dirname($this->real_target);
						$file_name=basename($this->real_target);		
						$this->etag='"' . filemtime($this->real_target) . "-" . fileinode($this->real_target) . "-" . 
						filesize($this->real_target) . ";" . md5($currentRequest->request_uri) . '"';
						debug("Our generated ETag: " . $this->etag,3);
						$currentRequest->response->addOption("ETag",$this->etag);
						$mime_type=$currentRequest->response->MimeType($this->real_target);
				        	$currentRequest->response->addOption("Content-Type",$mime_type);
						$this->data=$this->get_file();
				        	$currentRequest->response->addOption("Content-Length",$this->length());
					        $currentRequest->response->addOption("Content-Location",basename($this->real_target));
					        $currentRequest->response->addOption("Vary","negotiate,accept");
    						$currentRequest->response->addOption("TCN","choice");
					}
					else
					{
						$currentRequest->response->addOption("TCN", "list");
						$list=$this->negotiate($currentRequest->getRequestOptionValueByName("Negotiate"), $accept_files);	
						$currentRequest->response->addOption("Alternates",$list);
						$this->data=$this->get_300($accept_files);
						$currentRequest->giveResponseCodeAndClose(CODE_300,$this->conn, $currentRequest->getMethod());
					}
				}
				if($currentRequest->isRequestOption("Accept-Language"))//if Negotiate is present
				{
					$accept_files=$this->accept_language($currentRequest->getRequestOptionValueByName("Accept-Language"), $files);
					if(count($accept_files)==1)
					{
						$this->real_target=$this->return_file_suggestion($accept_files);
						debug("redirect: " . $this->real_target,5);

						$directory_name=dirname($this->real_target);
						$file_name=basename($this->real_target);
				
						$this->etag='"' . filemtime($this->real_target) . "-" . fileinode($this->real_target) . "-" . 
						filesize($this->real_target) . ";" . md5($currentRequest->$request_uri) . '"';
						debug("Our generated ETag: " . $this->etag,3);
						$currentRequest->response->addOption("ETag",$this->etag);
						$mime_type=$currentRequest->response->MimeType($this->remove_extension_for_mime($this->real_target));
					        $currentRequest->response->addOption("Content-Type",$mime_type);
						$this->data=$this->get_file();
			        		$currentRequest->response->addOption("Content-Length",$this->length());
					        $currentRequest->response->addOption("Content-Location",basename($this->real_target));
				        	$currentRequest->response->addOption("Vary","negotiate,accept-language,accept-charset");
				        	$currentRequest->response->addOption("TCN","choice");
						$ext=substr($this->get_language($this->real_target),-2);
						$currentRequest->response->addOption("Content-Language",$ext);
					}
					else
					{
						$currentRequest->response->addOption("TCN", "list");
						$list=$this->negotiate($currentRequest->getRequestOptionValueByName("Negotiate"), $accept_files);	
						$currentRequest->response->addOption("Alternates",$list);
						$this->data=$this->get_300($accept_files);
						$currentRequest->giveResponseCodeAndClose(CODE_300,$this->conn, $currentRequest->getMethod());
					}
	
				}
				if($currentRequest->isRequestOption("Accept-Charset"))
				{
					$accept_files=$this->accept_charset($currentRequest->getRequestOptionValueByName("Accept-Charset"), $files);
					if(count($accept_files)==1)
					{
						$this->real_target=$this->return_file_suggestion($accept_files);
						debug("redirect: " . $this->real_target,5);
						$directory_name=dirname($this->real_target);
						$file_name=basename($this->real_target);
			
						$this->etag='"' . filemtime($this->real_target) . "-" . fileinode($this->real_target) . "-" . 
						filesize($this->real_target) . ";" . md5($currentRequest->request_uri) . '"';
						debug("Our generated ETag: " . $this->etag,3);
						$currentRequest->response->addOption("ETag",$this->etag);
						$this->data=$this->get_file();
				        	$currentRequest->response->addOption("Content-Length",$this->length());
			        		$currentRequest->response->addOption("Content-Location",basename($this->real_target));
					        $currentRequest->response->addOption("Vary","negotiate,accept-language,accept-charset");
					        $currentRequest->response->addOption("TCN","choice");
						$remove_charset=$this->remove_extension_for_mime($this->real_target);
						$language=substr($this->get_language($remove_charset),-2);
						$currentRequest->response->addOption("Content-Language",$language);	
						$mime_type=$currentRequest->response->MimeType($this->remove_extension_for_mime($remove_charset));
						$charset=$currentRequest->response->charsetType($this->real_target);
						debug("Here is charset: " . $charset,5);
					        $currentRequest->response->addOption("Content-Type",$mime_type . "; charset=" . $charset);
					}
					else
					{
						$currentRequest->response->addOption("TCN", "list");
						$list=$this->negotiate($currentRequest->getRequestOptionValueByName("Negotiate"), $accept_files);
						$currentRequest->response->addOption("Alternates",$list);
						$this->data=$this->get_300($accept_files);
						$currentRequest->giveResponseCodeAndClose(CODE_300,$this->conn, $currentRequest->getMethod());
						
					}

				}
				if($currentRequest->isRequestOption("Accept-Encoding"))
				{
					$accept_files=$this->accept_encoding($currentRequest->getRequestOptionValueByName("Accept-Encoding"), $files);
					if(count($accept_files)==1)
					{
						$this->real_target=$this->return_file_suggestion($accept_files);
						debug("redirect: " . $this->real_target,5);
						$directory_name=dirname($this->real_target);
						$file_name=basename($this->real_target);
			
						$this->etag='"' . filemtime($this->real_target) . "-" . fileinode($this->real_target) . "-" . 
						filesize($this->real_target) . ";" . md5($currentRequest->request_uri) . '"';
						debug("Our generated ETag: " . $this->etag,3);
						$currentRequest->response->addOption("ETag",$this->etag);
						$this->data=$this->get_file();
				        	$currentRequest->response->addOption("Content-Length",$this->length());
			        		$currentRequest->response->addOption("Content-Location",basename($this->real_target));
					        $currentRequest->response->addOption("Vary","negotiate,accept-encoding");
					        $currentRequest->response->addOption("TCN","choice");
						$remove_encoding=$this->remove_extension_for_mime($this->real_target);
						$mime_type=$currentRequest->response->MimeType($remove_encoding);
						$currentRequest->response->addOption("Content-Encoding", $this->find_contentEncoding($this->real_target));
						$charset=$currentRequest->response->charsetType($this->real_target);
						debug("Here is charset: " . $charset,5);
					        $currentRequest->response->addOption("Content-Type",$mime_type . "; charset=" . $charset);
					}
					else
					{
						$currentRequest->response->addOption("TCN", "list");
						$list=$this->negotiate($currentRequest->getRequestOptionValueByName("Negotiate"), $accept_files);
						$currentRequest->response->addOption("Alternates",$list);
						$this->data=$this->get_300($accept_files);
						$currentRequest->giveResponseCodeAndClose(CODE_300,$this->conn, $currentRequest->getMethod());
						
					}

				}
				if($currentRequest->isRequestOption("Negotiate"))
				{
					$accept_files=$this->negotiate($currentRequest->getRequestOptionValueByName("Negotiate"), $files);
					$currentRequest->response->addOption("TCN","list");
					$currentRequest->response->addOption("Alternates",$accept_files);
					$this->data=$this->get_300($files);
					$currentRequest->response->addOption("Content-Length",$this->length());
					$currentRequest->giveResponseCodeAndClose(CODE_300,$this->conn, $currentRequest->getMethod());
				}
				else
				{
					$accept_files=$this->negotiate($currentRequest->getRequestOptionValueByName("Negotiate"), $files);
					$currentRequest->response->addOption("TCN","list");
					$currentRequest->response->addOption("Alternates",$accept_files);
					$this->data=$this->get_300($files);
					$currentRequest->giveResponseCodeAndClose(CODE_300,$this->conn, $currentRequest->getMethod());
				}

			}
			else
			{
				//this file does not exist
				global $currentRequest;
				debug("could not find the document ",5);
				$currentRequest->giveResponseCodeAndClose(CODE_404,$this->conn, $currentRequest->getMethod());
			}

		}

		$this->guessLanguage();

		$this->contentEncoding();
		/*
		switch ($this->transferEcoding()) {
			case "gzip":
				$this->data = gzencode($this->data);
				break;
			case "compress":
				$this->data = gzcompress($this->data);
				break;
			case "deflate":
				$this->data = gzdeflate($this->data);
				break;
			default:
				//this is identity, dont transform the output
		}
		*/

		return $this->data;
	}
	public function contentEncoding() {
		global $currentRequest;
		$encodings = array(
				"Z"=>"compress",
				"gz"=>"gzip",
				"zip"=>"deflate"
				);
		$file_ext = explode(".",$this->filename);
		foreach ($encodings as $ext=>$encode) {
			foreach($file_ext as $fext) {
				if ($fext == $ext) {
					$currentRequest->response->addOption("Content-Encoding",$encode);
					return;
				}
			}
		}
	}
	public function find_contentEncoding($file) {
		global $currentRequest;
		$file=basename($file);
		$encodings = array(
				"Z"=>"compress",
				"gz"=>"gzip",
				"zip"=>"deflate"
				);
		$file_ext = explode(".",$file);
		foreach ($encodings as $ext=>$encode) {
			foreach($file_ext as $fext) {
				if ($fext == $ext) {
					//$currentRequest->response->addOption("Content-Encoding",$encode);
					return $encode;
				}
			}
		}
	}
	public function guessLanguage() {
		global $currentRequest;
 		// take the last part of the file to get the file extension 
      	$file_ext = substr($this->filename,strpos($this->filename,"."));

		// find mime type 
		$mt =  $this->FindLanguage($file_ext); 
		if ($mt) {
			debug("Language is: " . $mt,5);
			$currentRequest->response->addOption("Content-Language",$mt);
		}

      	return $mt;
   	}
	public function FindLanguage($ext) 
	{ 
      		// goes to the array of mime types to the mime type.
      		$charset= array("en","es","de","ja","ru","ko");
			//we want to check for the hidden
			foreach ($charset as $lang) {
				if ( preg_match("@\." . $lang . "@iU", $ext))
					return $lang;
			}

       		return false;
   	}	
	public function get_file() {
		global $currentRequest;
		$file = file_get_contents($this->real_target);
		return $file;
	}
	public function get_dir() {
		global $currentRequest;
		$dir = scandir($this->real_target);
		$currentRequest->response->addOption("Transfer-Encoding","chunked");
		$listing  = "<html><head><title>Directory Listing For " . $this->target . "</title></head><body>\n";
		$listing .= "<table border=\"1\">\n";
		$listing .= "<tr><th>Filename</th><th>Size</th><th>Last Modified</th><th>Access Time</th><th>INode</th><th>Owner</th></tr>\n";
		foreach ($dir as $file) {
			$listing .= "<tr>\n";
			$listing .= "\t<td><a href=\"$file\">" . $file . "</a></td>\n";

			$stat = stat($this->real_target . "/" . $file);
			$listing .= "\t<td>" . $stat['size'] . "</td>\n";
			$listing .= "\t<td>" . $stat['mtime'] . "</td>\n";
			$listing .= "\t<td>" . $stat['atime'] . "</td>\n";
			$listing .= "\t<td>" . @$stat['inode'] . "</td>\n";

			$user = posix_getpwuid($stat['uid']);
			$listing .= "\t<td>" . $user['name'] . "</td>\n";
			$listing .= "</tr>\n";
		}
		$listing .= "</table></body></html>";
		return $listing;
	}
	public function get_300($array_files, $is_406=false) 
	{
		global $currentRequest;
		debug(print_r($array_files),5);
		$listing = "<html>\n";
		$listing .= "<head><title>300 Multiple Choice</title></head><body>\n";
		$listing .= "<h1>300 Multiple Choice</h1>\n";
		foreach ($array_files as $value) 
		{
			if(
				(substr($value,-2)=='en')||(substr($value,-2)=='de')||(substr($value,-2)=='ja')||
				(substr($value,-2)=='es')||(substr($value,-2)=='ru')||(substr($value,-2)=='ko')
			  )
			{
				//do nothing
			}
			else
			{
				$listing .= "<ul>\n";
				//$listing .= "<li><a href=\"$value\">" . basename($value) . "</a> , type " . $currentRequest->response->MimeType($value) . "</li>\n";
				$listing .= "<li><a href=\"" . basename($value) . "\">" . basename($value)  . "</a> , type " . $currentRequest->response->MimeType($value) . "</li>\n";
				$listing .= "</ul>\n";
			}
		}
		$listing .= "</body></html>\n";
		return $listing;
	}
	public function length() {
		debug("Entity length is: " . strlen($this->data),5);
		return strlen($this->data);
	}
	public function if_modified_since($date) {
		global $currentRequest;

		if (!$date)
			return 1;
		
		$date = new DateTime(substr($date,strpos($date,",") +1)); //ignore the day of the week
		if (!$date)
			$currentRequest->giveResponseCodeAndClose(CODE_412,$this->conn,$currentRequest->getMethod());

		debug("if-modified-since date in question " . $date->format("U"),4);
		debug("Creation Date " . $this->creation_date,4);
		
		if ($this->creation_date > $date->format("U")) //if ours is newer
			$this->date_status["If-Modified-Since"] = 3; //ours is newer
		else
			$this->date_status["If-Modified-Since"] = 2; //theirs is up to date

		debug("if-modified-since value" . $this->date_status["If-Modified-Since"],5);
	}
	public function if_not_modified_since($date) {
		global $currentRequest;

		if (!$date) //if they didnt give us a date, ignore us
			return 1;
		
		$date = new DateTime(substr($date,strpos($date,",") +1)); //ignore the day of the week
		if (!$date)
			$currentRequest->giveResponseCodeAndClose(CODE_412,$this->conn,$currenRequest->getMethod());

		if ($this->creation_date <= $date->format("U")) //theirs is up to date
			$this->date_status["If-Unmodified-Since"] = 3; //
		else
			$this->date_status["If-Unmodified-Since"] = 2; //theirs is up to date

		debug("if-not-modified-since value" . $this->date_status["If-Modified-Since"],5);
	}
	public function if_match($tags) {
		global $currentRequest;
		
		if(!$tags) {
			$currentRequest->giveResponseCodeAndClose(CODE_412,$this->conn,$currentRequest->getMethod()); //the precondition has failed
			return $this->date_status["If-Match"]=1;
		}


		$test_etags=preg_split("/[\s,]+/",$tags);
		debug(print_r($test_etags),5);

		foreach($test_etags as $option)
		{
			if (substr_count($tags,"-") != 2) {
				$currentRequest->giveResponseCodeAndClose(CODE_412,$this->conn,$currentRequest->getMethod()); //the precondition has failed
			}

			if($this->etag == $option)
				return $this->date_status["If-Match"] = 2; //if there is one of them that is right, it will return the entity
									   //no matter the position
			else
				$this->date_status["If-Match"] = 3;  
		}
		return $this->data_status["If-Match"];
	}
	public function if_none_match($tags) {
		if(!$tags)
			return $this->date_status["If-None-Match"]=1;
		$test_etags=preg_split("/[\s,]+/",$tags);
		debug(var_export($test_etags),5);
		
		foreach($test_etags as $option)
		{
			if($this->etag == $option)
				return $this->date_status["If-None-Match"] = 3;
			else
				$this->date_status["If-None-Match"] = 2;
		}
		return $this->data_status["If-None-Match"]; //same logic as if-match but reversed.
	}
	public function accept($client_suggestions, $files)//checks the accept values
	{
		global $currentRequest;
		$test_suggestions=preg_split("/,+/",$client_suggestions);
		$match_files = array();

		if(strstr(trim($test_suggestions[0]), "q"))//if a "q" value is detected
		{
			debug("comes and check q values",5);
			foreach($test_suggestions as $option)
			{	
				$image_data=explode(";", $option);
				$suggest_mime_type=$image_data[0];
				
				$q_value=explode("=",$image_data[1]);
				$array_mime_q[trim($suggest_mime_type)]=$q_value[1];
			}
			arsort($array_mime_q);
			$max_q=max($array_mime_q);//finds the max q value
			do
			{
				foreach($files as $values)
				{
					$mime_type=$currentRequest->response->MimeType($values);
					debug($mime_type,5);
					foreach($array_mime_q as $key=>$value)
					{
						if($key=="image/*")
						{
							if((substr($mime_type,0,5)=="image") && ($max_q==$value) && ($value!=0.0))
							{
								$match_files[]=$values;
							}
						}
						elseif($key=="text/*")
						{
							debug("Here is the trim: " . substr($option,0,4),5);
							if((substr($mime_type,0,4)=="text") && ($max_q==$value) && ($value!=0.0))
							{
								$match_files[]=$values;
								debug(print_r($match_files),5);
							}
						}					
						elseif(($max_q==$value) && ($mime_type==$key) && ($value!=0.0))
						{
							$match_files[]=$values;
						}
						if($value==0.0)
						{
							debug("Value is not 0",5);
							debug(print_r($values),5);
							$not_match_value[]=$values;
						}
					}
				}

				if(!empty($array_mime_q) && empty($match_files))
				{
					array_shift($array_mime_q);
					$max_q=max($array_mime_q);//finds the max q value
					debug("Max: " . $max_q,5);
				}
			}
			while((!empty($array_mime_q)) && (empty($match_files)));

			if(!empty($match_files))
			{
				debug("Return: ",5);
				debug(print_r($match_files),5);
				//die();
				return $match_files;
			}
			else
			{
				$this->give_406($files);
			}

		}
		else//if "q" values are not mentioned
		{
			foreach($test_suggestions as $option)
			{
				debug("Option: " . $option,5);
				$option=trim($option);
				foreach($files as $values)
				{
					$mime_type=$currentRequest->response->MimeType($values);
					debug($mime_type,5);
					if($option=="image/*")
					{
						if(substr($mime_type,0,5)=="image")
						{
							$match_files[]=$values;
						}
					}
					elseif($option=="text/*")
					{
						debug("Here is the trim: " . substr($option,0,4),5);
						if(substr($mime_type,0,4)=="text")
						{
							$match_files[]=$values;
							debug(print_r($match_files),5);
						}
					}
					elseif($mime_type==$option)
					{
						$match_files[]=$values;
					}
				}
			}
			debug(var_export($match_files,true),5);
			if(!empty($match_files))
			{
				return $match_files;
			}
			else
			{
				debug(print_r($files),5);
				foreach($files as $value)
				{
					$file = file_get_contents($value);

					if(
						(substr($value,-2)=='en')||(substr($value,-2)=='de')||(substr($value,-2)=='ja')||
						(substr($value,-2)=='es')||(substr($value,-2)=='ru')||(substr($value,-2)=='ko')
					  )
					{
						//do nothing
					}
					else
					{
						$value_update[]='{"' . basename($value) . '"' . '{type ' . $currentRequest->response->MimeType($value) . 
						'}{' . '{length ' . strlen($file) . '}}';					
					}
				}
				$alternates=implode($value_update, ",");
				$currentRequest->response->addOption("Alternates",$alternates);
				$currentRequest->response->addOption("Content-Type","text/html; charset=iso-8859-1");
				$this->give_406($value_update);
			}

		}
		
	}
	public function accept_encoding($encoding_suggestions, $files)//checks the accept values
	{
		global $currentRequest;
		$test_suggestions=preg_split("/,+/",$encoding_suggestions);
		$match_files = array();

		if(strstr(trim($test_suggestions[0]), "q"))//if a "q" value is detected
		{
			debug("comes and check q values",5);
			foreach($test_suggestions as $option)
			{	
				$encoding_data=explode(";", $option);
				$suggest_encoding=$encoding_data[0];
				
				$q_value=explode("=",$encoding_data[1]);
				$array_encoding[trim($suggest_encoding)]=$q_value[1];
			}
			arsort($array_encoding);
			$max_q=max($array_encoding);//finds the max q value
			do
			{
				foreach($files as $values)
				{
					$encoding_type=$this->find_contentEncoding($values);//make an encoding find array
					debug($encoding_type,5);
					foreach($array_encoding as $key=>$value)
					{					
						if(($max_q==$value) && ($encoding_type==$key) && ($value!=0.0))
						{
							$match_files[]=$values;
						}
						if($value==0.0)
						{
							debug("Value is not 0",5);
							debug(print_r($values),5);
							$not_match_value[]=$values;
						}
					}
				}

				if(!empty($array_encoding) && empty($match_files))
				{
					array_shift($array_encoding);
					$max_q=max($array_encoding);//finds the max q value
					debug("Max: " . $max_q,5);
				}
			}
			while((!empty($array_encoding)) && (empty($match_files)));

			if(!empty($match_files))
			{
				debug("Return: ",5);
				debug(print_r($match_files),5);
				//die();
				return $match_files;
			}
			else
			{
				$this->give_406($files);
			}

		}
		else//if "q" values are not mentioned
		{
			foreach($test_suggestions as $option)
			{
				debug("Option: " . $option,5);
				$option=trim($option);
				foreach($files as $values)
				{
					$encoding_type=$this->find_contentEncoding($values);
					debug($encoding_type,5);
					if($encoding_type==$option)
					{
						$match_files[]=$values;
					}
				}
			}
			debug(var_export($match_files,true),5);
			if(!empty($match_files))
			{
				return $match_files;
			}
			else
			{
				debug(print_r($files),5);
				foreach($files as $value)
				{
					$file = file_get_contents($value);

					if(
						(substr($value,-2)=='en')||(substr($value,-2)=='de')||(substr($value,-2)=='ja')||
						(substr($value,-2)=='es')||(substr($value,-2)=='ru')||(substr($value,-2)=='ko')
					  )
					{
						//do nothing
					}
					else
					{
						$value_update[]='{"' . basename($value) . '"' . '{type ' . $currentRequest->response->MimeType($value) . 
						'}' . '{length ' . strlen($file) . '}}';					
					}
				}
				$alternates=implode($value_update, ",");
				$currentRequest->response->addOption("Alternates",$alternates);
				$currentRequest->response->addOption("Content-Type","text/html; charset=iso-8859-1");
				$this->give_406($value_update);
			}

		}
		
	}
	public function accept_charset($charset_suggestions, $files)//look at charset encoding
	{
		global $currentRequest;
		$test_charset=preg_split("/,+/",$charset_suggestions);
		debug(print_r($test_charset),5);
		
		if(strstr(trim($test_charset[0]), "q"))//if a "q" value is detected
		{
			debug("comes and check q values",5);
			foreach($test_charset as $option)
			{	
				$charset_data=explode(";", $option);
				$suggest_charset_type=$charset_data[0];
				
				$q_value=explode("=",$charset_data[1]);
				$array_char_q[trim($suggest_charset_type)]=$q_value[1];
			}
			debug(print_r($array_char_q),5);

			arsort($array_char_q);
			$max_q=max($array_char_q);//finds the max q value
			do
			{
				//$max_q=max($array_char_q);//finds the max q value
				foreach($files as $values)
				{
					$char_type=$currentRequest->response->charsetType($values);
					debug("Charset: " . $char_type,5);
					foreach($array_char_q as $key=>$value)
					{
						if(($max_q==$value) && ($char_type==$key) && ($value!=0.0))
						{
							$match_files[]=$values;
						}
						if($value==0.0)
						{
							debug("Value is not 0",5);
							debug(print_r($values),5);
							$not_match_value[]=$values;
						}
					}
				}
				if(!empty($array_char_q) && empty($match_files))
				{
					array_shift($array_char_q);
					$max_q=max($array_char_q);//finds the max q value
					debug("Max: " . $max_q,5);
				}
			}
			while((!empty($array_char_q)) && (empty($match_files)));

			if(!empty($match_files))
			{
				debug("Return: ",5);
				debug(print_r($match_files),5);
				return $match_files;
			}
			else
			{
				$currentRequest->response->addOption("Content-Type","text/html; charset=iso-8859-1");
				$this->give_406($files);
			}

		}
		else
		{
			foreach($test_charset as $option)
			{
				foreach($files as $value)
				{
					$charset_type=$this->get_charset($value);
					if($option==$charset_type)
					{
						$match_files[]=$value;
					}
				}			
			}
			if(!empty($match_files))
			{
				debug("Return: ",5);
				debug(print_r($match_files),5);
				return $match_files;
			}
			else
			{
				$currentRequest->response->addOption("Content-Type","text/html; charset=iso-8859-1");
				$this->give_406($files);
			}

		}
	}
	public function negotiate($value, $files)
	{
		global $currentRequest;
		debug(print_r($files),5);
		
		foreach($files as $value)
		{
			$file = file_get_contents($value);
			if(
				(substr($value,-2)=='en')||(substr($value,-2)=='de')||(substr($value,-2)=='ja')||
				(substr($value,-2)=='es')||(substr($value,-2)=='ru')||(substr($value,-2)=='ko')
			 )
			{
				//do nothing
			}
			else
			{
				$value_update[]='{"' . basename($value) . '" 1 {type ' . $currentRequest->response->MimeType($value) . 
				'}' . '{length ' . strlen($file) . '}}';
			}
		}
		$alternates=implode($value_update, ",");
		return $alternates;

	}
	public function accept_language($lang_suggestions, $files)
	{
		global $currentRequest;
		$test_languages=preg_split("/,+/",$lang_suggestions);
		//if the user asked for a certain language then add it to the que if we have it.
		debug(print_r($test_languages),5);
		if(strstr(trim($test_languages[0]), "q"))//if a "q" value is detected
		{
			debug("comes and check q values",5);
			foreach($test_languages as $option)
			{	
				$lang_data=explode(";", $option);
				$suggest_lang_type=$lang_data[0];

				$q_value=explode("=",$lang_data[1]);
				$array_lang_q[trim($suggest_lang_type)]=$q_value[1];
				debug(print_r($array_lang_q),5);
			}

			arsort($array_lang_q);
			$max_q=max($array_lang_q);//finds the max q value
			do
			{
			//$max_q=max($array_lang_q);//finds the max q value
				foreach($files as $values)
				{
					$lang_type=substr($this->get_language($values),-2);
					foreach($array_lang_q as $key=>$value)
					{
						if(($max_q==$value) && ($lang_type==$key) && ($value!=0.0))
						{
							$match_files[]=$values;
						}
						if($value==0.0)
						{
							debug("Value is not 0",5);
							debug(print_r($values),5);
							$not_match_value[]=$values;
						}
					}
				}
				if(!empty($array_lang_q) && empty($match_files))
				{
					array_shift($array_lang_q);
					$max_q=max($array_lang_q);//finds the max q value
					debug("Max: " . $max_q,5);
				}
			}
			while((!empty($array_lang_q)) && (empty($match_files)));


			if(!empty($match_files))
			{
				debug("Return: ",5);
				debug(print_r($match_files),5);
				return $match_files;
			}
			else
			{
				debug(print_r($files),5);
				foreach($files as $value)
				{	
					$file = file_get_contents($value);

					if(
						(substr($value,-2)=='en')||(substr($value,-2)=='de')||(substr($value,-2)=='ja')||
						(substr($value,-2)=='es')||(substr($value,-2)=='ru')||(substr($value,-2)=='ko')
					  )
					{
						//do nothing
					}
					else
					{
						$value_update[]='{"' . basename($value) . '"' . '{type ' . $currentRequest->response->MimeType($value) . 
						'}' . '{length ' . strlen($file) . '}}';					
					}
				}
				$alternates=implode($value_update, ",");
				debug("Directory: " . $directory_combined,5);
				$currentRequest->response->addOption("Alternates",$alternates);
				$currentRequest->response->addOption("Content-Type","text/html; charset=iso-8859-1");
				$currentRequest->response->addOption("Vary","negotiate,accept-language");
				$this->give_406($value_update);

			}

		}
		else//if "q" values are not mentioned
		{
			
			foreach($files as $value)
			{
				$extension=$this->get_language($value);
				debug("Extension: " . $extension,5);
				foreach($test_languages as $option)
				{
					$option="." . trim($option);
					debug("Option: " . $option,5);
					if($extension==$option)
					{
						$match_files[]=$value;
					}
				}
			}
			debug(print_r($match_files),5);
			if(!empty($match_files))
			{
				return $match_files;
			}
			else
			{
                        	
				debug(print_r($files),5);
				foreach($files as $value)
				{	
					$file = file_get_contents($value);

					if(
						(substr($value,-2)=='en')||(substr($value,-2)=='de')||(substr($value,-2)=='ja')||
						(substr($value,-2)=='es')||(substr($value,-2)=='ru')||(substr($value,-2)=='ko')
					  )
					{
						$value_update[]='{"' . basename($value) . '" 1 {type ' . $currentRequest->response->MimeType($value) . 
						'} {language ' . $this->get_language($value) . '} {length ' . strlen($file) . '}}';

					}
					else
					{
						//do nothing
					}
				}
				$alternates=implode($value_update, ",");
				debug("Directory: " . $directory_combined,5);
				$currentRequest->response->addOption("Alternates",$alternates);
				$currentRequest->response->addOption("Content-Type","text/html; charset=iso-8859-1");
				$currentRequest->response->addOption("Vary","negotiate,accept-language");
				$this->give_406($value_update);
			}


		}
	}
	public function give_406($files) {
		global $currentRequest;
		$currentRequest->response->setOption("Content-Type","text/html; charset=iso-8859-1");
		$this->data = $this->get_300($file);
		$currentRequest->giveResponseCodeAndClose(CODE_406,$this->conn,$currentRequest->getMethod());
	}
	public function remove_extension($file)
	{
		$ext = strstr($file, '.');

                if($ext !== false)
                {
                        $file = substr($file, 0, -strlen($ext));
                }
                return $file;
	}
	public function remove_extension_for_mime($file)
	{
		$ext = strrchr($file, '.');

                if($ext !== false)
                {
                        $file = substr($file, 0, -strlen($ext));
                }
                return $file;
	}
	public function get_language($file)
	{
		$language = strrchr($file, '.');
		return $language;
	}
	public function get_charset($file)
	{
		$charset = strchr($file, '.');
		return $charset;
	}
	public function return_file_suggestion($files_array)
	{
		$file_array = array();
		foreach($files_array as $value)
		{
			$file_array[$value]=filesize($value);
		}
		asort($file_array);
		return reset(array_keys($file_array));
	}
	public function get_file_alias($open_directory, $directory, $file_name)
	{
        	while(false !== ($filename = readdir($open_directory))) //puts directory listings in an array
        	{
       	        	$file= $directory . "/" . $filename;
			$test_target=$directory . "/" . $file_name;
			$test_target_length=strlen($test_target);
			debug("Test target: " . $test_target,5);
			debug("File: " . substr($file,0,$test_target_length),5);
                	if($test_target==substr($file,0,$test_target_length))
       	        	{
               	       		$files[]= $file;//puts it in array
                	}
       		}
		
		return $files;
    }
	public function put_file() {
        global $currentRequest;
        if ($currentRequest->getRequestOptionValueByName("Content-Length") > 2097152)
            $currentRequest->goveResponseCodeAndClose(CODE_413,$this->conn,$currentRequest->getMethod());
        if ( strlen($currentRequest->request_uri) > 2048) 
            $currentRequest->goveResponseCodeAndClose(CODE_414,$this->conn,$currentRequest->getMethod());

        if (file_exists($this->real_target)) {
            //we want to update
            if (file_put_contents($this->real_target,$currentRequest->getBody()) !== false) {
                $this->data2 = "Successfully Updated File<br /><br />";
                $currentRequest->response->addOption("Content-Type","text/html");
                $currentRequest->response->addOption("Transfer-Encoding","chunked");
                return true;
            } else {
			    $currentRequest->giveResponseCodeAndClose(CODE_500,$this->conn,$currentRequest->getMethod());
                return false;
            }
        } else {
            //we want to create
            if (file_put_contents($this->real_target,$currentRequest->getBody()) !== false) {
                $this->data2 = "Successfully Created File<br /><br />";
                $currentRequest->response->addOption("Content-Type","text/html");
                $currentRequest->response->addOption("Transfer-Encoding","chunked");
                $currentRequest->response->status = CODE_201;
                return true;
            } else {
			    $currentRequest->giveResponseCodeAndClose(CODE_500,$this->conn,$currentRequest->getMethod());
                return false;
            }
        }
        return false;
    }
    public function delete_file() {
        global $currentRequest;
        if ( strlen($currentRequest->request_uri) > 2048) 
            $currentRequest->goveResponseCodeAndClose(CODE_414,$this->conn,$currentRequest->getMethod());

        if (file_exists($this->real_target)) {
            if (unlink($this->real_target)) {
                $this->data2 = "Successfully Deleted File<br /><br />";
                $currentRequest->response->addOption("Transfer-Encoding","chunked");
                $currentRequest->response->addOption("Content-Type","text/html");
                return true;
            } else {
			    $currentRequest->giveResponseCodeAndClose(CODE_500,$this->conn,$currentRequest->getMethod());
                return false;
            }
        }
        return false;
    }
	public function get_extension($path)
        {
                // get base name of the filename provided by user
                $filename = basename($path);

                // take the last part of the file to get the file extension
                $file_ext = @substr($filename,strpos($filename,"."));

                return $file_ext;
        }
}
