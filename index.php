<?php
require_once __DIR__ . '/includes/bootstrap.php';
redirect(is_logged_in() ? 'dashboard.php' : 'login.php');
