<?php
ini_set('magic_quotes_gpc',false);
ini_set('magic_quotes_runtime',false);
session_start();

require_once('restapi.php');

$restapi =& new restapi();
$restapi->exec();

/*
var_dump($restapi->output);
*/

?>
