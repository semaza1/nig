<?php
// pages/logout.php - handles logout and session destruction
session_start();

// Destroy session
session_destroy();

// Redirect to login
header('Location: ./login.php');
exit;
?>
