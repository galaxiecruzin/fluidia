<?php
session_start();

/*
 * The use of magic quotes is depretiated and will be removed in 
 * PHP version 6.
 * 
 * This will attempt to disable it via ini_set, it that works the 
 * following function will be skipped, otherwise it will manually
 * go through the get,post,cookie,and requests globals to remove
 * the slashes that magic quotes is automatically injecting
 * 
 * Another option is to add the following line to the .htaccess file
 * but that is not compatible with all hosting environments
 * 
 * # .htaccess
 * php_flag magic_quotes_gpc Off
 */
ini_set('magic_quotes_gpc',false);
ini_set('magic_quotes_runtime',false);
if (get_magic_quotes_gpc()) {
   function stripslashes_deep($value)
   {
       $value = is_array($value) ?
                   array_map('stripslashes_deep', $value) :
                   stripslashes($value);

       return $value;
   }

   $_POST = array_map('stripslashes_deep', $_POST);
   $_GET = array_map('stripslashes_deep', $_GET);
   $_COOKIE = array_map('stripslashes_deep', $_COOKIE);
   $_REQUEST = array_map('stripslashes_deep', $_REQUEST);
}

require_once('restapi.php');

$restapi =& new restapi();
$restapi->exec();

/*
var_dump($restapi->output);
*/

?>
