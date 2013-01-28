<?php
//LOCAL SERVER DEFENITIONS
define("HTTP_VERSION","HTTP/1.1");
define("SERVER_NAME","dien");
define("SERVER_VERSION","0.1");
define("HTTP_VER","HTTP/1.1");
define("DEBUG_LEVEL",5);
define("REQUEST_TIMEOUT",15);
define("SERVER_ROOT",dirname(__FILE__));
define("DOCUMENT_ROOT", "/var/www/html/web_course/");
//define("DOCUMENT_ROOT","/home/nicole/dien/trunk/classes");
define("SERVER_DOC_ROOT",getcwd() . "/docs/");
//define("DOCUMENT_ROOT","/var/www/html/archive");
define("SOCKET_TIMEOUT",60); //stay connected for no more than 60 seconds

//DEFINE RESPONSE CODES
define("CODE_200","200 OK");
define("CODE_201","201 Created");
define("CODE_204","204 No Content");
define("CODE_300","300 Multiple Choice");
define("CODE_301","301 Moved Permanently");
define("CODE_302","302 Found");
define("CODE_304","304 Not Modified");
define("CODE_400","400 Bad Request");
define("CODE_401","401 Authorization Required");
define("CODE_403","403 Forbidden");
define("CODE_404","404 Not Found");
define("CODE_405","405 Method Not Allowed");
define("CODE_406","406 Not Acceptable");
define("CODE_411","411 Length Required");
define("CODE_412","412 Precondition Failed");
define("CODE_413","413 Request-Entity Too Long");
define("CODE_414","414 Request-URI Too Long");
define("CODE_500","500 Internal Server Error");
define("CODE_501","501 Not Implemented");
define("CODE_505","505 HTTP Version Not Supported");
