<?php
// File: app/views/servers/index.php
$config = require __DIR__ . '/../../../config/config.php';
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Servers</h3>
        <a href="<?= $config['base_url'] ?>/servers/create" class="btn btn-primary btn-sm">Add Server</a>
    </div>
    <table class="table table-bordered table-striped">
        <thead class="thead-light">
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Hostname</th>
                <th>IP</th>
                <th>SSH Port</th>
                <th>Status</th>
                <th width="120">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($servers as $server): ?>
                <tr>
                    <td><?= (int)$server['id'] ?></td>
                    <td><?= htmlspecialchars($server['name']) ?></td>
                    <td><?= htmlspecialchars($server['hostname']) ?></td>
                    <td><?= htmlspecialchars($server['ip_address']) ?></td>
                    <td><?= htmlspecialchars($server['ssh_port']) ?></td>
                    <td><?= htmlspecialchars($server['status']) ?></td>
                    <td>
                        <a href="<?= $config['base_url'] ?>/servers/edit/<?= (int)$server['id'] ?>" class="btn btn-sm btn-info">Edit</a>
                        <a href="<?= $config['base_url'] ?>/servers/delete/<?= (int)$server['id'] ?>"
                           class="btn btn-sm btn-danger"
                           onclick="return confirm('Delete this server?')">
                           Delete
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($servers)): ?>
                <tr><td colspan="7" class="text-center">No servers found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
