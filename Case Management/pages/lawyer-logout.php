<?php
session_start();

// Clear all lawyer session variables
unset($_SESSION['lawyer_id']);
unset($_SESSION['lawyer_user_id']);
unset($_SESSION['lawyer_name']);
unset($_SESSION['lawyer_username']);

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: lawyer-login.php');
exit;
?>
