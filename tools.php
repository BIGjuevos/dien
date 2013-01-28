<?php
require_once('settings.php');

function debug($message,$debug_level) {
	global $pid, $fp;
	if ($debug_level && $debug_level <= DEBUG_LEVEL) {
		echo "DEBUG: " . $message . "\n";
		if ($pid >= 0 )  {
			@fwrite($fp,time() . ": {$message}\n");
		}

	}
}
