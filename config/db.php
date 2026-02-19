<?php
// Database configuration - update credentials if needed
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'nig';

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    // Return false to allow callers (APIs) to return proper JSON error responses
    return false;
}

// set charset
$mysqli->set_charset('utf8mb4');

return $mysqli;

?>
