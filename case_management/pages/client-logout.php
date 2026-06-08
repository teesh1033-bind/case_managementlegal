<?php
session_start();

// Clear all client session variables
unset($_SESSION['client_id']);
unset($_SESSION['client_user_id']);
unset($_SESSION['client_name']);
unset($_SESSION['client_username']);

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>
