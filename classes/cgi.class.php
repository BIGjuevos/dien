<?php
class Cgi{
	
	private $target;
	private $target_dir;
	private $filename;
	private $full_dir;
	private $conn;
	private $output;
	
	
	public function __construct($target, $conn, $body = false)
	{
		$this->target= $target;
		$this->target_dir= dirname($target);
		$this->filename = basename($target);
		$this->full_dir= DOCUMENT_ROOT . "/" . $this->target_dir . "/" . $this->filename;
		$this->conn = $conn;
		global $currentRequest;

		debug("target: " . $this->target,5);
		debug("target directory: " . $this->target_dir,5);
		debug("filename: " . $this->filename, 5);

		//lets look at the executable file.
		$descriptor=array(
			0=> array("pipe","r"), //stdin is a pipe that the child will read from
			1=> array("pipe","w"), //stdout is a pipe that the child will write to
		);
		//i dont understand the environment part...but from my understanding
		//all i want is the content information of the output!
		//where are the files stored

		socket_getpeername ($conn , $address, $port );

		$env= array(
		"SCRIPT_NAME"=>$this->target,
		"SCRIPT_URI"=>$currentRequest->request_uri, //uri
		"SCRIPT_FILENAME"=>$this->full_dir,
		"HTTP_REFERER"=>$currentRequest->getRequestOptionValueByName("Referer"),
		"HTTP_USER_AGENT"=>$currentRequest->getRequestOptionValueByName("User-Agent"),
		"REQUEST_METHOD"=>$currentRequest->getMethod(),
		"REMOTE_ADDR"=>$address,
		"QUERY_STRING"=>$currentRequest->getQueryString(),
		"REMOTE_USER"=>$currentRequest->user,
		"AUTH_TYPE"=>$currentRequest->auth_type,
		"SERVER_NAME"=>'mln-web.cs.odu.edu',
		"SERVER_SOFTWARE"=>'Dien 0.9',
		"SERVER_PORT"=>'7201',
		"SERVER_ADDR"=>'128.62.4.1',
		"SERVER_PROTOCOL"=>'HTTP/1.1'
		);

		$absolute_dir_path=DOCUMENT_ROOT . $this->target_dir; //full path to command
		$cwd=chdir($absolute_dir_path); //changing directory to the full path for the command
		debug("The command dir: " . $cwd,5);
		
		$process=proc_open("./" . $this->filename, $descriptor, $pipes, $absolute_dir_path, $env); //creating the process to read the file
		$root=chdir(DOCUMENT_ROOT); //change dir back to root
		debug("Root dir: " . $root,5);

		if(is_resource($process))
		{
			//if body exists, write it
			if ($body) {
				fwrite($pipes[0],$body);
			}
			fclose($pipes[0]);
			debug("This is a resource ",5);
			$this->output=stream_get_contents($pipes[1]);
    			debug("Output " . $this->output,5);
			fclose($pipes[1]);//closing process
			debug("We closed the STDOUT pipe",5);

			//proc_terminate($process);
			usleep(100);

			$return_value = proc_close($process); //returned value from closing process
    			debug("command returned " . $return_value,5);
		}
    			
		

	}
	public function get_output() //returning the output of the program
	{
		return $this->output;
	}


}
