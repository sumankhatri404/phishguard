<?php
require_once __DIR__ . '/../inc/functions.php';
session_unset(); session_destroy(); redirect_msg('../index.php','You have been logged out.');
?>