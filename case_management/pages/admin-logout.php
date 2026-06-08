<?php
session_start();

// Clear all admin session variables
unset($_SESSION['admin_id']);
unset($_SESSION['admin_username']);
unset($_SESSION['admin_role']);
unset($_SESSION['admin_name']);

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: admin-login.php');
exit;
?>
