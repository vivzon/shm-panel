<?php
// File: app/views/auth/login.php
$config = require __DIR__ . '/../../../config/config.php';
?>
<div class="container" style="max-width: 400px; margin-top: 50px;">
    <h3 class="mb-4 text-center">Login</h3>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="form-group">
            <label>Email or Username</label>
            <input type="text" name="login" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Login</button>
    </form>
</div>
