<?php
require_once __DIR__ . '/includes/bootstrap.php';
log_action('Signed out');
$_SESSION = array();
session_destroy();
redirect('login.php');
