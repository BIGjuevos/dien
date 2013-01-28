<?php
require_once('settings.php');
require_once('tools.php');

//include our classes
require_once('classes/request.class.php');
require_once('classes/request_option.class.php');
require_once('classes/response.class.php');
require_once('classes/response_option.class.php');
require_once('classes/connection.class.php');
require_once('classes/cgi.class.php');
require_once('classes/entity.class.php');

$socket = socket_create(AF_INET,SOCK_STREAM,SOL_TCP);

$port = trim(file_get_contents("port"));
debug("port: '$port'", 1);
	
while (!socket_bind($socket, "0.0.0.0", $port) ) {
	debug("Could not bind, socket, waiting and retrying",4);
	sleep(5);
}
debug("Successfully bound socket",4);
$succ = socket_listen($socket);
if ( !$succ ) {
  deug("FAILURE: " . socket_last_error($socket), 1);
  die();
}

$keepAlive = true;
$request_queue = array(); //this will hold all of our requests

debug("READY",1);

$count = 0;

while ($socket && $keepAlive) {
  debug('Waiting',1);
	$conn = socket_accept($socket); 
	if ($conn) {//someone is talking to us
		$count++; //we want a new log next time
		$pid = pcntl_fork();
		if ($pid == -1) {
			//we failed
			exit(1);
		} else if ($pid) {
			//we are the parent
			debug("I am the parent",5);
			//we must break out of this and continue execution and continue to listen for new people
			continue;
		} else {
			//we are the child
			//execute the request
			$fp = fopen("logs/{$count}.log","a");
			debug("Server Forked to handle new connection",5);
			handleConnection($socket,$conn);
			fclose($fp);
			exit(0);
		}
	} else {
		//just loop again
		//give to processor some rest
		usleep(100);
		//do it again
		continue;
	}
}
debug("we ae over",5);

function handleConnection($socket,$conn) {
	global $currentRequest, $fp; //this will always hold the request we are currently working on
	global $request_queue;
	$request = "";
	socket_set_nonblock($conn); //we do not want to wait for data to be sent, execute anyways
	$max_wait = 1000;
	$timeout = 0;
	while (1) {
		$buffer = @socket_read($conn,1);//the buffer has to be larger than 1 because then it doesnt recognize it as a string

		//socket_clear_error(); //get rid of any possible errors

		if (!$conn) {
			debug("Our socket is gone, they closed on us",1);
			die();
			return; //we have died
		}

		if ($buffer==null && $buffer!=='0') { //they aren't sending anything
			//we are reading air
			usleep(100);
			$timeout++;
			if ($timeout > $max_wait) {
				debug("timeout: " . $timeout . " max wait: ". $max_wait,5);
				//we have waited 15 seconds from a partial entr, or about .5 secs for a complete entry
				//consider it over
				$timeout = 0;
				$max_wait = 1000;
				if (count($request_queue)) {
					$res = handleResponses($conn);
					if ($res) {
						Connection::close($conn);
						debug("We wre told to close",3);
					} else {
						debug("Hanfled Requests",4);
						$request_queue = array();
						$request = "";
						$timeout = 0;
						continue; //start over
					}
				} else {
					//there are noo requests and time is up
					Connection::close($conn);
					debug("The connection has timed out",2);
					die();
				}
			} else {
				//we have not timed out, or thery are still entering lines
				continue; //start the while loop again
			}
		} else {
			//they sent something, rest the timeout and continue to work with the data
			$max_wait = 1000; //wait a little bit longer before timing out if they are talking to us
			$timeout = 0;
		}
		//debug("Buffer: " . $buffer,5);

		$request .= $buffer;
		$pre_buffer = "";

		if ( (substr($request,-4) == "\r\n\r\n" || substr($request,-2) == "\n\n") && strlen($request) > 8) { //wait until they give us a blank line at the end of the request
			//they are done with this request parse it
			debug("Done recieving request, begin parsing",5);
			$raw = $request;
			debug("Request Head: " . $request,5);
			$request = new Request($request,$conn);
            //try to find unsafe methods, and if so, read in content body
    			if (substr_count($raw,"PUT") > 0 || substr_count($raw,"POST") > 0) {
    				if ( preg_match("@Content-Length: (.*)[\r\n]+@iU",$raw,$match) ) {
    					$body_length = $match[1];
                        debug("They told use body length of: " . $body_length,5);
    					$buffer2 = @socket_read($conn,$body_length);
    					$request->addBody($buffer2);
    				} else {
    					$request->response->giveResponseCodeAndClose(CODE_411,$conn);
    				}
    			}
			debug("Request Over",2);
			debug("===========================================\n\n\n",2);
			array_push($request_queue,$request);
			$request = ""; //erase it
			$buffer = "";
			$max_wait = 300; //dont wait very long for a follow up request
		} else {
		}
	}
}
function handleResponses($conn) {
	global $request_queue;
	global $currentRequest;
	foreach ($request_queue as $id=>$request) {
		$currentRequest = $request; //we need to be able to access our current request globally
		unset($request_queue[$id]);

		//answer their cry for a file
		$request->validate();
		$request->respond();

		//did they issue a connection close?
		if ($request->getRequestOptionValueByName("Connection") == "close" || $request->getRawRequest() == "") { //do they say they are done
			debug("They sent the close command we are going to close after this",4);
			return 1; //leagve this place
		}
	}
	$errorcode = socket_last_error();
	$errormsg = socket_strerror($errorcode);
	//debug("handlResponse finished with status code of [$errorcode] $errormsg",5);
	switch ($errorcode) {
		case 11 : socket_clear_error();
		case 107: $crap = @socket_read($conn,100000); //eat up all that is left over in the socket
				  debug("stream is now clean",5);
				  break;
		case 0  : return 1;//we are done

	}
	//debug("=====================================================\n\n\n",1);
	return 0;
}
