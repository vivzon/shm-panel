<?php
// File: app/views/layouts/admin_sidebar.php
$config = require __DIR__ . '/../../../config/config.php';
?>
<div class="bg-dark text-white p-3" style="width: 220px; min-height: 100vh;">
    <h5 class="mb-4">Admin Panel</h5>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a class="nav-link text-white" href="<?= $config['base_url'] ?>/admin/dashboard">Dashboard</a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-white" href="<?= $config['base_url'] ?>/servers">Servers</a>
        </li>
        <!-- Later: Resellers, Clients, Plans, Settings, Logs -->
    </ul>
</div>
