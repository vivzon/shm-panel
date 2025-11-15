<?php
// File: app/views/servers/form.php
$config = require __DIR__ . '/../../../config/config.php';
$isEdit = !empty($server['id']);
$actionUrl = $isEdit
    ? $config['base_url'] . '/servers/edit/' . (int)$server['id']
    : $config['base_url'] . '/servers/create';
?>
<div class="container">
    <h3 class="mb-3"><?= $isEdit ? 'Edit Server' : 'Add Server' ?></h3>
    <form method="post">
        <div class="form-group">
            <label>Server Name</label>
            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($server['name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Hostname</label>
            <input type="text" name="hostname" class="form-control" required value="<?= htmlspecialchars($server['hostname'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>IP Address</label>
            <input type="text" name="ip_address" class="form-control" required value="<?= htmlspecialchars($server['ip_address'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>SSH Port</label>
            <input type="text" name="ssh_port" class="form-control" value="<?= htmlspecialchars($server['ssh_port'] ?? '22') ?>">
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" class="form-control"><?= htmlspecialchars($server['notes'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="status" class="form-control">
                <option value="active" <?= (isset($server['status']) && $server['status'] == 'active') ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= (isset($server['status']) && $server['status'] == 'inactive') ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <button class="btn btn-success" type="submit">Save</button>
        <a href="<?= $config['base_url'] ?>/servers" class="btn btn-secondary">Cancel</a>
    </form>
</div>
