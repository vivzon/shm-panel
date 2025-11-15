<?php
// File: app/views/layouts/header.php
$config = require __DIR__ . '/../../../config/config.php';
Auth::startSession();
$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($config['app_name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link
      rel="stylesheet"
      href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"
    >
    <link rel="stylesheet" href="<?= $config['base_url'] ?>/assets/css/style.css">
</head>
<body>
<div class="d-flex">
    <?php if ($user && in_array($user['role'], ['super_admin', 'reseller'])): ?>
        <?php require __DIR__ . '/admin_sidebar.php'; ?>
    <?php endif; ?>
    <div class="flex-fill p-3">
        <nav class="navbar navbar-light bg-light mb-3">
            <span class="navbar-brand mb-0 h4"><?= htmlspecialchars($config['app_name']) ?></span>
            <div>
                <?php if ($user): ?>
                    <span class="mr-3">Hello, <?= htmlspecialchars($user['name']) ?></span>
                    <a href="<?= $config['base_url'] ?>/auth/logout" class="btn btn-outline-danger btn-sm">Logout</a>
                <?php endif; ?>
            </div>
        </nav>
