<?php
// MUST be first — no spaces, no HTML above
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $page_title ?? 'LIYAS Mineral Water' ?></title>

<!-- LIYAS Brand Assets -->
<link rel="icon" href="/assets/images/logo/logo-bg.jpg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">

<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Main Site CSS -->
<link rel="stylesheet" href="/assets/css/style.css">

<style>
/* 🌊 LIYAS SEA BLUE THEME */
:root {
    --liyas-blue: #3b8ebf;
    --liyas-blue-dark: #27b6c7;
    --bg-soft: #f5feff;
    --border-soft: #e0f4f7;
}

body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg-soft);
}
</style>
</head>
<body>
