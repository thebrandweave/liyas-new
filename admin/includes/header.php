<?php
// Common Admin Header Component
if (!defined('BASE_URL')) {
    require_once dirname(__DIR__, 2) . '/config/config.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Liyas Admin') ?> - Liyas International</title>
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/images/logo/logo-bg.jpg">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="<?= BASE_URL ?>/admin/assets/css/prody-admin.css">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content">
