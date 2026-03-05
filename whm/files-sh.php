<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// -------- CONFIG: SHM PANEL ROOT --------
$panel_root = realpath(dirname(__DIR__));
if ($panel_root === false) {
    die("Could not resolve SHM panel root");
}
$panel_root = str_replace('\\', '/', $panel_root);


// -------------------- HELPER FUNCTIONS --------------------

/**
 * Simple local cleaner
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
        if (strpos($real_full, str_replace('\\', '/', $real_base)) !== 0) return false;
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
    if (!$zip->open($destination, ZipArchive::CREATE)) {
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
        return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-blue-400"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-slate-400"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14.5 2 14.5 7.5 20 7.5"/></svg>';
}

include 'layout/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; gap: 1rem;">
    <div style="display: flex; align-items: center; gap: 1rem;">
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); font-family: var(--font-heading);">File Manager</h2>
        <div style="font-size: 0.875rem; color: var(--text-secondary); font-family: monospace; background: var(--bg-surface); padding: 0.25rem 0.75rem; border-radius: 0.5rem; border: 1px solid var(--border-color);">
            <?= htmlspecialchars($current_path) ?>
        </div>
    </div>
</div>

<div class="glass-card animate-slide-up hover-glow" style="padding: 1.5rem; margin-bottom: 2rem; border-radius: 1.5rem;">
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
        <button onclick="togglePanel('upload-panel')" class="btn btn-primary" style="padding: 0.5rem 1rem; border-radius: 0.75rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="upload" style="width: 1rem; height: 1rem;"></i> Upload
        </button>
        <button onclick="togglePanel('folder-panel')" style="background: var(--bg-body); border: 1px solid var(--border-color); color: var(--text-primary); padding: 0.5rem 1rem; border-radius: 0.75rem; font-size: 0.875rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; cursor: pointer; transition: all var(--transition-normal);" onmouseover="this.style.background='var(--bg-surface)'" onmouseout="this.style.background='var(--bg-body)'">
            <i data-lucide="folder-plus" style="width: 1rem; height: 1rem;"></i> New Folder
        </button>
        <a href="?zip_project=1&path=<?= urlencode($current_path) ?>" style="background: var(--bg-body); border: 1px solid var(--border-color); color: var(--text-primary); padding: 0.5rem 1rem; border-radius: 0.75rem; font-size: 0.875rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; cursor: pointer; text-decoration: none; transition: all var(--transition-normal);" onmouseover="this.style.background='var(--bg-surface)'" onmouseout="this.style.background='var(--bg-body)'">
            <i data-lucide="download-cloud" style="width: 1rem; height: 1rem;"></i> Full ZIP
        </a>

        <div style="flex: 1; min-width: 200px; position: relative;">
            <form method="get" style="margin: 0;">
                <input type="hidden" name="path" value="<?= htmlspecialchars($current_path) ?>">
                <i data-lucide="search" style="width: 1rem; height: 1rem; position: absolute; left: 0.75rem; top: 0.625rem; color: var(--text-secondary);"></i>
                <input type="text" name="q" placeholder="Search files..." value="<?= htmlspecialchars($search_query) ?>" class="form-input" style="width: 100%; padding-left: 2.5rem; border-radius: 0.75rem;">
            </form>
        </div>
    </div>

    <div id="upload-panel" style="margin-top: 1.5rem; padding: 1.5rem; background: var(--bg-body); border-radius: 1rem; border: 1px solid var(--border-color); display: none;">
        <form method="post" enctype="multipart/form-data" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
            <input type="file" name="file" required style="font-size: 0.875rem; color: var(--text-secondary); cursor: pointer;">
            <button type="submit" name="upload_file" class="btn btn-primary" style="padding: 0.5rem 1rem; border-radius: 0.75rem; font-size: 0.75rem; text-transform: uppercase;">Start Upload</button>
        </form>
    </div>

    <div id="folder-panel" style="margin-top: 1.5rem; padding: 1.5rem; background: var(--bg-body); border-radius: 1rem; border: 1px solid var(--border-color); display: none;">
        <form method="post" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
            <input type="text" name="folder_name" placeholder="Folder Name" required class="form-input" style="flex: 1; border-radius: 0.75rem; min-width: 200px;">
            <button type="submit" name="create_folder" class="btn btn-primary" style="padding: 0.5rem 1rem; border-radius: 0.75rem; font-size: 0.75rem; text-transform: uppercase;">Create Folder</button>
        </form>
    </div>
</div>

<div class="glass-card animate-slide-up hover-glow" style="overflow: hidden; border-radius: 1.5rem; animation-delay: 0.1s;">
    <div style="overflow-x: auto;" class="custom-scrollbar">
        <table style="width: 100%; text-align: left; border-collapse: collapse;">
            <thead style="background: var(--bg-body); text-shadow: none; font-size: 0.625rem; font-weight: 700; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color);">
                <tr>
                    <th style="padding: 1rem;">Name</th>
                    <th style="padding: 1rem;">Size</th>
                    <th style="padding: 1rem;">Perms</th>
                    <th style="padding: 1rem;">Modified</th>
                    <th style="padding: 1rem; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody style="border-top: 1px solid var(--border-color);">
                <?php if ($current_path !== '/'): ?>
                    <tr style="border-bottom: 1px solid var(--border-color); transition: all var(--transition-normal);" onmouseover="this.style.background='var(--bg-body)'" onmouseout="this.style.background='transparent'">
                        <td style="padding: 1rem;" colspan="5">
                            <a href="?path=<?= urlencode(dirname($current_path)) ?>" style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.875rem; color: var(--primary); font-weight: 700; text-decoration: none;">
                                <i data-lucide="corner-left-up" style="width: 1rem; height: 1rem;"></i> ..
                            </a>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($files as $f): ?>
                    <tr style="border-bottom: 1px solid var(--border-color); transition: all var(--transition-normal);" onmouseover="this.style.background='var(--bg-body)'" onmouseout="this.style.background='transparent'">
                        <td style="padding: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <?= get_file_icon($f['is_dir'], $f['extension']) ?>
                                <?php if ($f['is_dir']): ?>
                                    <a href="?path=<?= urlencode($f['relative']) ?>" style="font-size: 0.875rem; font-weight: 700; color: var(--text-primary); text-decoration: none; transition: color var(--transition-normal);" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-primary)'">
                                        <?= htmlspecialchars($f['name']) ?>
                                    </a>
                                <?php else: ?>
                                    <span style="font-size: 0.875rem; font-weight: 500; color: var(--text-secondary);"><?= htmlspecialchars($f['name']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="padding: 1rem; font-size: 0.75rem; color: var(--text-secondary);"><?= $f['is_dir'] ? '-' : format_file_size($f['size']) ?></td>
                        <td style="padding: 1rem; font-size: 0.75rem; font-family: monospace; color: var(--text-secondary);"><?= $f['permissions'] ?></td>
                        <td style="padding: 1rem; font-size: 0.75rem; color: var(--text-secondary);"><?= $f['modified'] ?></td>
                        <td style="padding: 1rem; text-align: right;">
                            <div style="display: flex; justify-content: flex-end; gap: 0.25rem;">
                                <?php if (!$f['is_dir']): ?>
                                    <a href="?download=<?= urlencode($f['relative']) ?>" title="Download" style="padding: 0.5rem; color: var(--text-secondary); cursor: pointer; transition: color var(--transition-normal);" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-secondary)'"><i data-lucide="download" style="width: 1rem; height: 1rem;"></i></a>
                                <?php endif; ?>
                                <button onclick="renameItem('<?= addslashes($f['relative']) ?>', '<?= addslashes($f['name']) ?>')" title="Rename" style="padding: 0.5rem; color: var(--text-secondary); cursor: pointer; border: none; background: transparent; transition: color var(--transition-normal);" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-secondary)'"><i data-lucide="edit-3" style="width: 1rem; height: 1rem;"></i></button>
                                <button onclick="deleteItem('<?= addslashes($f['relative']) ?>', '<?= addslashes($f['name']) ?>')" title="Delete" style="padding: 0.5rem; color: var(--text-secondary); cursor: pointer; border: none; background: transparent; transition: color var(--transition-normal);" onmouseover="this.style.color='var(--accent-red)'" onmouseout="this.style.color='var(--text-secondary)'"><i data-lucide="trash-2" style="width: 1rem; height: 1rem;"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($files)): ?>
                    <tr><td colspan="5" style="padding: 2rem; text-align: center; color: var(--text-secondary); font-size: 0.875rem;">No files found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<form id="js-form" method="post" style="display:none;">
    <input type="hidden" name="file_path" id="js-path">
    <input type="hidden" name="new_name" id="js-name">
    <input type="hidden" name="rename_path" id="js-rename" value="1">
    <input type="hidden" name="delete_path" id="js-delete" value="1">
</form>

<script>
    function togglePanel(id) {
        const el = document.getElementById(id);
        el.style.display = (el.style.display === 'none' || !el.style.display) ? 'block' : 'none';
    }

    function deleteItem(path, name) {
        if (!confirm(`Delete ${name}?`)) return;
        const f = document.getElementById('js-form');
        document.getElementById('js-path').value = path;
        document.getElementById('js-delete').disabled = false;
        document.getElementById('js-rename').disabled = true;
        f.submit();
    }

    function renameItem(path, oldName) {
        const newName = prompt("Enter new name:", oldName);
        if (!newName || newName === oldName) return;
        const f = document.getElementById('js-form');
        document.getElementById('js-path').value = path;
        document.getElementById('js-name').value = newName;
        document.getElementById('js-delete').disabled = true;
        document.getElementById('js-rename').disabled = false;
        f.submit();
    }
</script>

<?php include 'layout/footer.php'; ?>
