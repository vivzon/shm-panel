<?php
// File: install/index.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host   = trim($_POST['db_host']);
    $db_name   = trim($_POST['db_name']);
    $db_user   = trim($_POST['db_user']);
    $db_pass   = trim($_POST['db_pass']);
    $base_url  = rtrim(trim($_POST['base_url']), '/');
    $admin_name= trim($_POST['admin_name']);
    $admin_email = trim($_POST['admin_email']);
    $admin_user  = trim($_POST['admin_user']);
    $admin_pass  = $_POST['admin_pass'];

    $errors = [];

    if (!$db_host || !$db_name || !$db_user || !$base_url || !$admin_email || !$admin_user || !$admin_pass) {
        $errors[] = "Please fill all required fields.";
    }

    if (empty($errors)) {
        $mysqli = @new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($mysqli->connect_error) {
            $errors[] = "Database connection failed: " . $mysqli->connect_error;
        } else {
            $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
            if (!$mysqli->multi_query($schemaSql)) {
                $errors[] = "Error running schema: " . $mysqli->error;
            } else {
                // flush multi_query results
                while ($mysqli->more_results() && $mysqli->next_result()) {;}

                // insert admin user
                $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("INSERT INTO users (role, name, email, username, password, status, created_at)
                                          VALUES ('super_admin',?,?,?,?,1,NOW())");
                $stmt->bind_param('sss', $admin_name, $admin_email, $admin_user);
                $stmt->execute();

                // write config.php
                $configArray = [
                    'db_host'  => $db_host,
                    'db_name'  => $db_name,
                    'db_user'  => $db_user,
                    'db_pass'  => $db_pass,
                    'base_url' => $base_url,
                    'app_name' => 'SHM Panel',
                    'timezone' => 'Asia/Kolkata',
                ];

                $configExport = "<?php\nreturn " . var_export($configArray, true) . ";\n";
                $configPath = __DIR__ . '/../config/config.php';

                if (!is_dir(__DIR__ . '/../config')) {
                    mkdir(__DIR__ . '/../config', 0755, true);
                }

                if (file_put_contents($configPath, $configExport) === false) {
                    $errors[] = "Unable to write config.php. Check folder permissions.";
                } else {
                    // create installed flag
                    file_put_contents(__DIR__ . '/.installed', date('c'));
                    header("Location: {$base_url}/auth/login");
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SHM Installer</title>
    <link
      rel="stylesheet"
      href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"
    >
</head>
<body class="bg-light">
<div class="container" style="max-width: 700px; margin-top: 40px;">
    <h3 class="mb-4">SHM Panel Installer</h3>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <form method="post">
        <h5>Database Settings</h5>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>DB Host</label>
                <input type="text" name="db_host" class="form-control" required value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
            </div>
            <div class="form-group col-md-6">
                <label>DB Name</label>
                <input type="text" name="db_name" class="form-control" required value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>DB User</label>
                <input type="text" name="db_user" class="form-control" required value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>">
            </div>
            <div class="form-group col-md-6">
                <label>DB Password</label>
                <input type="password" name="db_pass" class="form-control" value="">
            </div>
        </div>

        <h5 class="mt-4">Application Settings</h5>
        <div class="form-group">
            <label>Base URL (e.g. http://localhost/shm-panel/public)</label>
            <input type="text" name="base_url" class="form-control" required value="<?= htmlspecialchars($_POST['base_url'] ?? '') ?>">
        </div>

        <h5 class="mt-4">Admin Account</h5>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Admin Name</label>
                <input type="text" name="admin_name" class="form-control" required value="<?= htmlspecialchars($_POST['admin_name'] ?? 'Super Admin') ?>">
            </div>
            <div class="form-group col-md-6">
                <label>Admin Email</label>
                <input type="email" name="admin_email" class="form-control" required value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Admin Username</label>
                <input type="text" name="admin_user" class="form-control" required value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>">
            </div>
            <div class="form-group col-md-6">
                <label>Admin Password</label>
                <input type="password" name="admin_pass" class="form-control" required>
            </div>
        </div>

        <button class="btn btn-primary mt-3" type="submit">Install</button>
    </form>
</div>
</body>
</html>
