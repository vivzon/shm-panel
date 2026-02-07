<?php
//require_once '../includes/config.php';
//require_once '../includes/auth.php';
//require_login();
//check_permission('file_management');

// TEMP: show any PHP errors directly on the page while debugging.
// Remove or comment these three lines after it works.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// -------- CONFIG: SHM PANEL ROOT --------
$panel_root = realpath(dirname(__DIR__));
if ($panel_root === false) {
    die("Could not resolve SHM panel root");
}
$panel_root = str_replace('\\', '/', $panel_root);


// -------------------- HELPER FUNCTIONS --------------------

/**
 * Simple local cleaner (so we don't depend on sanitize_input())
 */
function shm_clean($value) {
    if (is_array($value)) return $value;
    return trim(strip_tags($value));
}

/**
 * Format file size nicely
 */
if (!function_exists('format_file_size')) {
    function format_file_size($bytes) {
        if (!is_numeric($bytes) || $bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min(floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }
}

/**
 * Change file permissions safely
 */
if (!function_exists('change_file_permissions')) {
    function change_file_permissions($path, $permissions) {
        $permissions = trim($permissions);
        if (!preg_match('/^[0-7]{3,4}$/', $permissions)) return false;
        $mode = octdec(ltrim($permissions, '0'));
        return @chmod($path, $mode);
    }
}

/**
 * Recursive delete (file or directory)
 */
function shm_rrmdir($path) {
    if (!file_exists($path)) return true;
    if (!is_dir($path)) return @unlink($path);

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
    }
    return @rmdir($path);
}

/**
 * Normalize a relative path (removes .. and .)
 */
function shm_normalize_relative($path) {
    $path = str_replace('\\', '/', $path);
    $path = '/' . ltrim($path, '/');
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        ($part === '..') ? array_pop($parts) : $parts[] = $part;
    }
    return '/' . implode('/', $parts);
}

/**
 * Build a safe absolute path inside base
 */
function shm_build_path($base, $relative) {
    $base = rtrim(str_replace('\\', '/', $base), '/');
    $relative = shm_normalize_relative($relative);
    $full = $base . $relative;

    // Check if the path tries to escape the base directory
    $real_base = realpath($base);
    $real_full = realpath($full);
    if ($real_full !== false) {
        if (strpos($real_full, $real_base) !== 0) return false;
        return str_replace('\\', '/', $real_full);
    }
    
    // For non-existent paths (like new folders), check normalized string
    if (strpos($full, $base) !== 0) return false;
    return $full;
}

/**
 * Recursively zip a directory
 */
function shm_zip_dir($source, $destination) {
    if (!extension_loaded('zip') || !file_exists($source)) {
        return false;
    }
    $zip = new ZipArchive();
    if (!$zip->open($destination, ZIPARCHIVE::CREATE)) {
        return false;
    }
    $source = str_replace('\\', '/', realpath($source));
    if (is_dir($source)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($files as $file) {
            $file = str_replace('\\', '/', $file);
            if (in_array(substr($file, strrpos($file, '/') + 1), ['.', '..'])) continue;

            $file = realpath($file);
            $file = str_replace('\\', '/', $file);

            if (is_dir($file)) {
                $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
            } else if (is_file($file)) {
                $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
            }
        }
    } else if (is_file($source)) {
        $zip->addFromString(basename($source), file_get_contents($source));
    }
    return $zip->close();
}


// -------- INPUTS --------
$current_path = isset($_GET['path']) ? $_GET['path'] : '/';
$current_path = shm_normalize_relative($current_path);

$search_query = isset($_GET['q']) ? shm_clean($_GET['q']) : '';
$sort = isset($_GET['sort']) ? shm_clean($_GET['sort']) : 'name';

// Full path of current folder
$full_path = shm_build_path($panel_root, $current_path . '/');
if ($full_path === false || !is_dir($full_path)) {
    die("Invalid path or path is not a directory.");
}

// -------------------- ACTIONS (GET) --------------------

// DOWNLOAD FILE
if (isset($_GET['download'])) {
    $file_rel = shm_clean($_GET['download']);
    $target_abs = shm_build_path($panel_root, $file_rel);

    if ($target_abs !== false && is_file($target_abs)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($target_abs) . '"');
        header('Content-Length: ' . filesize($target_abs));
        header('Pragma: public');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        readfile($target_abs);
        exit;
    } else {
        header('Location: files-sh.php?path=' . urlencode($current_path) . '&error=' . urlencode('Invalid file for download'));
        exit;
    }
}

// ZIP ENTIRE PROJECT
if (isset($_GET['zip_project'])) {
    $zip_filename = 'shm_panel_backup_' . date('Y-m-d') . '.zip';
    $temp_zip_path = sys_get_temp_dir() . '/' . $zip_filename;

    if (shm_zip_dir($panel_root, $temp_zip_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($temp_zip_path));
        header('Pragma: public');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        readfile($temp_zip_path);
        @unlink($temp_zip_path); // Clean up temp file
        exit;
    } else {
        header('Location: files-sh.php?path=' . urlencode($current_path) . '&error=' . urlencode('Failed to create project ZIP. Check permissions or PHP ZipArchive extension.'));
        exit;
    }
}

// -------------------- ACTIONS (POST) --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_taken = false;
    $success_msg = '';
    $error_msg = '';

    // UPLOAD FILE
    if (isset($_POST['upload_file']) && isset($_FILES['file'])) {
        $target_dir = rtrim($full_path, '/') . '/';
        $name = basename($_FILES['file']['name']);
        if (is_uploaded_file($_FILES['file']['tmp_name']) && move_uploaded_file($_FILES['file']['tmp_name'], $target_dir . $name)) {
            $success_msg = 'File uploaded successfully';
        } else {
            $error_msg = 'File upload failed';
        }
        $action_taken = true;
    }

    // CREATE FOLDER
    if (isset($_POST['create_folder'])) {
        $folder_name = shm_clean($_POST['folder_name'] ?? '');
        $folder_name = trim(str_replace(['/', '\\', '..'], '', $folder_name));
        if ($folder_name !== '') {
            $new_abs = shm_build_path($full_path, $folder_name);
            if ($new_abs !== false && !file_exists($new_abs)) {
                @mkdir($new_abs, 0755, true) ? ($success_msg = 'Folder created') : ($error_msg = 'Failed to create folder');
            } else {
                $error_msg = 'Folder exists or path is invalid';
            }
        }
        $action_taken = true;
    }

    // CHANGE PERMISSIONS, DELETE, RENAME
    if (isset($_POST['file_path'])) {
        $file_path_rel = shm_clean($_POST['file_path']);
        $target_abs = shm_build_path($panel_root, $file_path_rel);

        if ($target_abs !== false && file_exists($target_abs)) {
            // Change Permissions
            if (isset($_POST['change_permissions'])) {
                $permissions = shm_clean($_POST['permissions'] ?? '');
                change_file_permissions($target_abs, $permissions) ? ($success_msg = 'Permissions changed') : ($error_msg = 'Failed to change permissions');
            }
            // Delete
            elseif (isset($_POST['delete_path'])) {
                shm_rrmdir($target_abs) ? ($success_msg = 'Item deleted') : ($error_msg = 'Failed to delete item');
            }
            // Rename
            elseif (isset($_POST['rename_path'])) {
                $new_name = shm_clean($_POST['new_name'] ?? '');
                $new_name = trim(str_replace(['/', '\\', '..'], '', $new_name));
                if ($new_name !== '') {
                    $new_abs = dirname($target_abs) . '/' . $new_name;
                    if (!file_exists($new_abs)) {
                        @rename($target_abs, $new_abs) ? ($success_msg = 'Item renamed') : ($error_msg = 'Failed to rename item');
                    } else {
                        $error_msg = 'An item with that name already exists';
                    }
                }
            }
        } else {
            $error_msg = 'Invalid file path specified';
        }
        $action_taken = true;
    }

    if ($action_taken) {
        $redirect_url = 'files-sh.php?path=' . urlencode($current_path);
        if ($success_msg) $redirect_url .= '&success=' . urlencode($success_msg);
        if ($error_msg) $redirect_url .= '&error=' . urlencode($error_msg);
        header('Location: ' . $redirect_url);
        exit;
    }
}


// -------- BUILD FILE LIST --------
$files = [];
$items = @scandir($full_path);
if ($items !== false) {
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $abs = $full_path . '/' . $item;
        $is_dir = is_dir($abs);
        $rel = shm_normalize_relative(($current_path === '/' ? '' : $current_path) . '/' . $item);
        $perm = @fileperms($abs);
        $files[] = [
            'name'        => $item,
            'relative'    => $rel,
            'is_dir'      => $is_dir,
            'size'        => $is_dir ? 0 : @filesize($abs),
            'permissions' => $perm ? substr(sprintf('%o', $perm), -4) : '----',
            'modified'    => date('Y-m-d H:i:s', @filemtime($abs)),
            'extension'   => $is_dir ? '' : strtolower(pathinfo($item, PATHINFO_EXTENSION)),
        ];
    }
}

// Sort: dirs first, then by field
usort($files, function ($a, $b) use ($sort) {
    if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
    switch ($sort) {
        case 'size': return $a['size'] <=> $b['size'];
        case 'modified': return strcmp($b['modified'], $a['modified']); // Newest first
        case 'type': return strcmp($a['extension'], $b['extension']);
        case 'name': default: return strcasecmp($a['name'], $b['name']);
    }
});

// Filter by search
if ($search_query !== '') {
    $q = strtolower($search_query);
    $files = array_filter($files, fn($f) => strpos(strtolower($f['name']), $q) !== false);
}

// -------- SVG ICONS --------
function get_file_icon($is_dir, $ext) {
    if ($is_dir) {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="icon"><path d="M4 5a2 2 0 0 1 2-2h6.172a2 2 0 0 1 1.414.586l3.828 3.828A2 2 0 0 1 18 8.828V19a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5zm2-1v14h12V9.414l-3.414-3.414H6z"></path></svg>';
    }
    $icons = [
        'php' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="icon"><path d="M12 2c5.523 0 10 4.477 10 10s-4.477 10-10 10S2 17.523 2 12 6.477 2 12 2zm-1.031 4.31a.75.75 0 0 0-1.438.438l-1.5 5.25a.75.75 0 0 0 1.438.438l.605-2.116h2.852l.605 2.116a.75.75 0 1 0 1.438-.437l-1.5-5.25a.75.75 0 0 0-1.438-.438l-.531 1.857h-1.44l-.53-1.857zm.175 3.328l.473-1.657.473 1.657h-.946zm4.856-3.328a.75.75 0 0 0-1.5 0v5.25a.75.75 0 0 0 1.5 0v-5.25zm-2.25 0a.75.75 0 0 0-1.5 0v5.25a.75.75 0 0 0 1.5 0v-5.25z"></path></svg>',
        'js' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="icon"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm2 4v2h2v6H7v2h4v-2h-2V9h4v8h2V9h-2V7H7z"></path></svg>',
        'html' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="icon"><path d="M15 4h-2.5l-1-1h-3l-1 1H5v2h14V4h-4zm-2.5 13.5V10h-1v7.5l-2-2-1.5 1.5L12 23l4-4-1.5-1.5-2 2z"></path></svg>',
        'css' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="icon"><path d="M12 2c5.523 0 10 4.477 10 10s-4.477 10-10 10S2 17.523 2 12 6.477 2 12 2zm-1 6a1 1 0 0 0-1 1v6a1 1 0 1 0 2 0V9a1 1 0 0 0-1-1zm3 0a1 1 0 0 0-1 1v6a1 1 0 1 0 2 0V9a1 1 0 0 0-1-1z"></path></svg>',
        'jpg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="icon"><path d="M2 5a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v14a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V5zm16 2a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM5 17.5l4.5-4.5 2.5 2.5 5.5-5.5L21 13.5V5a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v12.5z"></path></svg>',
        'png' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="icon"><path d="M2 5a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v14a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V5zm16 2a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM5 17.5l4.5-4.5 2.5 2.5 5.5-5.5L21 13.5V5a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v12.5z"></path></svg>',
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="icon"><path d="M2 5a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v14a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V5zm16 2a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM5 17.5l4.5-4.5 2.5 2.5 5.5-5.5L21 13.5V5a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v12.5z"></path></svg>',
        'zip' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="icon"><path d="M11 2h2v5h-2V2zm0 5h2v2h-2V7zm0 2h2v2h-2V9zm0 2h2v2h-2v-2zM9 2H7v12h2V2zm10 0h-2v12h2V2zM6 4H4v10h2V4zm12 0h-2v10h2V4zM2 16v5a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1v-5H2z"></path></svg>',
    ];
    $ext = strtolower($ext);
    if (in_array($ext, ['jpeg', 'gif', 'bmp', 'webp'])) $ext = 'jpg';
    if (in_array($ext, ['rar', '7z', 'tar', 'gz'])) $ext = 'zip';

    return $icons[$ext] ?? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="icon"><path d="M6 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6H6zM5 20V4a1 1 0 0 1 1-1h7v5h5v11a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1zm8-13V4.5L17.5 9H13z"></path></svg>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SHM Panel File Manager</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root {
            --color-bg: #f8f9fa;
            --color-text: #212529;
            --color-text-muted: #6c757d;
            --color-border: #dee2e6;
            --color-surface: #ffffff;
            --color-primary: #0d6efd;
            --color-primary-hover: #0b5ed7;
            --color-danger: #dc3545;
            --color-danger-hover: #bb2d3b;
            --color-success: #198754;
            --color-info: #0dcaf0;
            --border-radius: 6px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: var(--color-bg); color: var(--color-text); line-height: 1.5; font-size: 16px; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--color-border); }
        .header h1 { font-size: 24px; margin: 0; }
        .header .user-info { font-size: 14px; color: var(--color-text-muted); }
        .header a { color: var(--color-primary); text-decoration: none; } .header a:hover { text-decoration: underline; }
        .alert { padding: 12px 16px; margin-bottom: 20px; border-radius: var(--border-radius); font-size: 14px; border: 1px solid transparent; }
        .alert-success { background: #d1e7dd; color: #0f5132; border-color: #badbcc; }
        .alert-error { background: #f8d7da; color: #842029; border-color: #f5c2c7; }
        .card { background: var(--color-surface); border-radius: var(--border-radius); border: 1px solid var(--color-border); box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-body { padding: 20px; }
        .breadcrumb { font-size: 14px; margin-bottom: 15px; color: var(--color-text-muted); }
        .breadcrumb a { color: var(--color-primary); text-decoration: none; } .breadcrumb a:hover { text-decoration: underline; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px; align-items: center; }
        .btn { padding: 8px 14px; border-radius: var(--border-radius); border: 1px solid transparent; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; transition: background-color 0.2s; }
        .btn-primary { background: var(--color-primary); color: #fff; border-color: var(--color-primary); } .btn-primary:hover { background: var(--color-primary-hover); border-color: var(--color-primary-hover); }
        .btn-secondary { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); } .btn-secondary:hover { background: #f1f3f5; }
        .btn-danger { background: var(--color-danger); color: #fff; border-color: var(--color-danger); } .btn-danger:hover { background: var(--color-danger-hover); border-color: var(--color-danger-hover); }
        .btn-sm { padding: 5px 10px; font-size: 13px; }
        .form-control { padding: 8px 12px; border-radius: var(--border-radius); border: 1px solid var(--color-border); font-size: 14px; }
        .inline-panel { margin-top: 15px; padding: 15px; border-radius: var(--border-radius); border: 1px solid var(--color-border); background: #f8f9fa; }
        .inline-panel form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 15px; border-bottom: 1px solid var(--color-border); text-align: left; vertical-align: middle; }
        th { background: #f8f9fa; color: var(--color-text-muted); font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f1f3f5; }
        .file-name a { color: var(--color-text); text-decoration: none; font-weight: 500; display: flex; align-items: center; } .file-name a:hover { color: var(--color-primary); }
        .icon { width: 1.2em; height: 1.2em; margin-right: 10px; color: #495057; }
        a .icon { color: var(--color-primary); }
        .badge-perms { font-family: monospace; display: inline-block; padding: 3px 6px; border-radius: 4px; background: #e9ecef; color: #495057; font-size: 12px; }
        .actions { display: flex; gap: 6px; justify-content: flex-end; }
        .text-right { text-align: right; }
        .text-muted { color: var(--color-text-muted); }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>File Manager</h1>
        <div class="user-info">
            Logged in as <strong>Admin</strong> | <a href="../logout.php">Logout</a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <div class="breadcrumb">
                <a href="files-sh.php?path=/">SHM Root</a>
                <?php
                $parts = explode('/', trim($current_path, '/'));
                $crumb = '/';
                foreach ($parts as $part) {
                    if ($part === '') continue;
                    $crumb .= $part . '/';
                    echo ' / <a href="files-sh.php?path=' . urlencode($crumb) . '">' . htmlspecialchars($part) . '</a>';
                }
                ?>
            </div>

            <div class="toolbar">
                <button type="button" class="btn btn-primary" onclick="togglePanel('upload-panel')">Upload File</button>
                <button type="button" class="btn btn-secondary" onclick="togglePanel('folder-panel')">New Folder</button>
                <a href="files-sh.php?zip_project=1&path=<?php echo urlencode($current_path); ?>" class="btn btn-secondary">Download Project ZIP</a>
                <form method="get" style="display:flex; gap:10px; align-items:center; margin-left: auto;">
                    <input type="hidden" name="path" value="<?php echo htmlspecialchars($current_path); ?>">
                    <input type="text" name="q" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($search_query); ?>">
                    <select name="sort" class="form-control" onchange="this.form.submit()">
                        <option value="name" <?php if ($sort === 'name') echo 'selected'; ?>>Sort by Name</option>
                        <option value="size" <?php if ($sort === 'size') echo 'selected'; ?>>Sort by Size</option>
                        <option value="modified" <?php if ($sort === 'modified') echo 'selected'; ?>>Sort by Modified</option>
                        <option value="type" <?php if ($sort === 'type') echo 'selected'; ?>>Sort by Type</option>
                    </select>
                    <button type="submit" class="btn btn-secondary">Go</button>
                </form>
            </div>

            <div id="upload-panel" class="inline-panel" style="display:none;">
                <form method="post" enctype="multipart/form-data">
                    <input type="file" name="file" required class="form-control">
                    <button type="submit" name="upload_file" class="btn btn-primary">Upload</button>
                    <button type="button" class="btn btn-secondary" onclick="togglePanel('upload-panel')">Cancel</button>
                </form>
            </div>
            <div id="folder-panel" class="inline-panel" style="display:none;">
                <form method="post">
                    <input type="text" name="folder_name" placeholder="Folder name" required class="form-control">
                    <button type="submit" name="create_folder" class="btn btn-primary">Create</button>
                    <button type="button" class="btn btn-secondary" onclick="togglePanel('folder-panel')">Cancel</button>
                </form>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th class="text-right">Size</th>
                        <th>Permissions</th>
                        <th>Last Modified</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($files)): ?>
                    <tr>
                        <td colspan="5" class="text-muted" style="text-align: center; padding: 40px 0;">This folder is empty.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($files as $f): ?>
                        <tr>
                            <td class="file-name">
                                <?php if ($f['is_dir']): ?>
                                    <a href="files-sh.php?path=<?php echo urlencode($f['relative']); ?>">
                                        <?php echo get_file_icon(true, ''); ?>
                                        <?php echo htmlspecialchars($f['name']); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="display: flex; align-items: center;">
                                        <?php echo get_file_icon(false, $f['extension']); ?>
                                        <?php echo htmlspecialchars($f['name']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><?php echo $f['is_dir'] ? '-' : format_file_size($f['size']); ?></td>
                            <td><span class="badge-perms"><?php echo htmlspecialchars($f['permissions']); ?></span></td>
                            <td><?php echo htmlspecialchars($f['modified']); ?></td>
                            <td class="text-right">
                                <div class="actions">
                                    <?php if (!$f['is_dir']): ?>
                                        <a href="editor.php?file=<?php echo urlencode($f['relative']); ?>" class="btn btn-secondary btn-sm" title="Edit">Edit</a>
                                        <a href="files-sh.php?path=<?php echo urlencode($current_path); ?>&download=<?php echo urlencode($f['relative']); ?>" class="btn btn-secondary btn-sm" title="Download">Download</a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="changePerms('<?php echo htmlspecialchars($f['relative']); ?>', '<?php echo htmlspecialchars($f['permissions']); ?>')" title="Permissions">Perms</button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="renameItem('<?php echo htmlspecialchars($f['relative']); ?>', '<?php echo htmlspecialchars($f['name']); ?>')" title="Rename">Rename</button>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteItem('<?php echo htmlspecialchars($f['relative']); ?>', '<?php echo htmlspecialchars($f['name']); ?>')" title="Delete">Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Hidden forms for JS actions -->
            <form id="permForm" method="post" style="display:none;"><input type="hidden" name="file_path"><input type="hidden" name="permissions"><input type="hidden" name="change_permissions" value="1"></form>
            <form id="delForm" method="post" style="display:none;"><input type="hidden" name="file_path"><input type="hidden" name="delete_path" value="1"></form>
            <form id="renameForm" method="post" style="display:none;"><input type="hidden" name="file_path"><input type="hidden" name="new_name"><input type="hidden" name="rename_path" value="1"></form>
        </div>
    </div>
</div>

<script>
    function togglePanel(id) {
        const el = document.getElementById(id);
        const isHidden = el.style.display === 'none' || el.style.display === '';
        document.getElementById('upload-panel').style.display = 'none';
        document.getElementById('folder-panel').style.display = 'none';
        if (isHidden) el.style.display = 'block';
    }

    function changePerms(filePath, currentPerms) {
        const newPerms = prompt(`Enter new permissions for "${filePath}" (e.g., 755 or 644):`, currentPerms);
        if (newPerms && /^[0-7]{3,4}$/.test(newPerms.trim())) {
            const form = document.getElementById('permForm');
            form.file_path.value = filePath;
            form.permissions.value = newPerms.trim();
            form.submit();
        } else if (newPerms !== null) {
            alert('Invalid permissions format. Please use a 3 or 4-digit octal number (e.g., 755).');
        }
    }

    function deleteItem(filePath, name) {
        if (confirm(`Are you sure you want to permanently delete "${name}"? This action cannot be undone.`)) {
            const form = document.getElementById('delForm');
            form.file_path.value = filePath;
            form.submit();
        }
    }

    function renameItem(filePath, currentName) {
        const newName = prompt(`Rename "${currentName}" to:`, currentName);
        if (newName && newName.trim() !== '' && newName.trim() !== currentName) {
            if (newName.includes('/') || newName.includes('\\')) {
                alert('File names cannot contain slashes.');
                return;
            }
            const form = document.getElementById('renameForm');
            form.file_path.value = filePath;
            form.new_name.value = newName.trim();
            form.submit();
        }
    }
</script>
</body>

</html>

