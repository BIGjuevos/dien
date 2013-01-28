<?php
class Connection {
	public function send($conn,$message) {
		@socket_write($conn,$message);
		debug("OUTPUT: " . $message,4);
	}
	public function close($conn) {
		//@socket_send($conn,"\n",1,0); //add in that last carriage return to tell them we are done
		@socket_shutdown($conn,2);
		@socket_close($conn); //close our socket
	}
}
