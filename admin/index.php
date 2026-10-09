<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth_check.php';

header("Location: dashboard/index.php");
exit;