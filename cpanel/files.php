<?php
/**
 * VIVZON FILE MANAGER - Enterprise v5.1 (FIXED)
 * Optimized for CPanel Integration
 * Fixes: File permission update and upload issues
 */
// Config Path
require_once __DIR__ . '/../shared/config.php';

// Authentication Check
if (!isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['cid'];

// Increase execution limits for large uploads/zips with validation
ini_set('upload_max_filesize', '2048M');
ini_set('post_max_size', '2048M');
ini_set('memory_limit', '2048M');
ini_set('max_execution_time', '3600');
set_time_limit(3600);

// Security Note: `disable_functions` should be enforced in php.ini, as runtime 
// ini_set() changes for this directive have no effect in modern PHP versions.
// Ensure your server is safely configured.

/**
 * PATH HELPERS
 */
function shm_normalize_relative($path)
{
    // Security: Prevent null byte injection
    $path = str_replace(chr(0), '', $path);
    $path = str_replace(['\\', '//'], '/', $path);
    $path = '/' . ltrim($path, '/');
    $parts = array_filter(explode('/', $path), 'strlen');
    $safe = [];
    foreach ($parts as $part) {
        if ($part === '.')
            continue;
        if ($part === '..') {
            if (!empty($safe)) {
                array_pop($safe);
            }
        } else {
            $safe[] = $part;
        }
    }
    return '/' . implode('/', $safe);
}

function shm_build_path($base, $relative)
{
    $base = rtrim(str_replace('\\', '/', $base), '/');
    $relative = shm_normalize_relative($relative);
    $full = $base . $relative;

    // Enhanced security check: ensure final path is within base directory
    $real_base = realpath($base);
    $real_full = realpath($full);

    if ($real_base === false || $real_full === false) {
        return false;
    }

    // Check if the resolved path is within the base directory
    if (strpos($real_full, $real_base) !== 0) {
        return false;
    }

    return $real_full;
}

/**
 * RECURSIVE DELETE
 */
function shm_rrmdir($path)
{
    if (!file_exists($path))
        return true;
    if (!is_dir($path))
        return @unlink($path);

    $items = scandir($path);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..')
            continue;
        if (!shm_rrmdir($path . DIRECTORY_SEPARATOR . $item))
            return false;
    }
    return @rmdir($path);
}

function shm_rcopy($src, $dst)
{
    if (!file_exists($src)) {
        return false;
    }

    if (file_exists($dst)) {
        shm_rrmdir($dst);
    }

    if (is_dir($src)) {
        if (!mkdir($dst, 0775, true)) {
            return false;
        }
        $files = scandir($src);
        if ($files === false) {
            return false;
        }
        foreach ($files as $file) {
            if ($file != "." && $file != "..")
                if (!shm_rcopy("$src/$file", "$dst/$file"))
                    return false;
        }
    } else if (file_exists($src)) {
        if (!copy($src, $dst)) {
            return false;
        }
    }
    return true;
}

// ------------- INPUTS -------------
$domain_id = isset($_REQUEST['domain_id']) ? (int) $_REQUEST['domain_id'] : 0;
$current_path = isset($_REQUEST['path']) ? shm_normalize_relative($_REQUEST['path']) : '/';

// Security: Validate domain_id
if ($domain_id < 0) {
    die("Invalid domain ID");
}

// Verify Domain ownership & Get Root
$stmt = $pdo->prepare("SELECT * FROM domains WHERE id = ? AND client_id = ?");
$stmt->execute([$domain_id, $user_id]);
$domain = $stmt->fetch();

if (!$domain) {
    // If no domain selected, pick the first available
    $first = $pdo->prepare("SELECT id FROM domains WHERE client_id = ? LIMIT 1");
    $first->execute([$user_id]);
    $fid = $first->fetchColumn();
    if ($fid) {
        header("Location: ?domain_id=$fid&path=/");
        exit;
    }
    die("No domains found. Please add a domain first.");
}

// Fix for local Windows development if DB path is missing or unix-style
$default_root = "/var/www/clients/" . ($_SESSION['client'] ?? 'default') . "/public_html";
$base_path = rtrim($domain['document_root'] ?? $default_root, '/');

// Security: Sanitize base path
$base_path = realpath($base_path) ?: $base_path;

// On Windows local dev, map /var/www to a local folder
if (DIRECTORY_SEPARATOR === '\\') {
    // If path starts with /var, re-map it to a local 'storage' folder for testing
    if (strpos($base_path, '/var') === 0 || strpos($base_path, '/') === 0) {
        $base_path = __DIR__ . '/../../storage/' . ($_SESSION['client'] ?? 'guest');
        $base_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base_path);
    }
}

// FIX 1: Enhanced Directory Creation and Permission Handling
$setup_error = null;
if (!file_exists($base_path)) {
    // Create with proper permissions
    $old_umask = umask(0);
    $created = mkdir($base_path, 0775, true);
    umask($old_umask);

    if (!$created) {
        $error = error_get_last();
        // Fallback for Windows Local Dev if not already handled
        if (DIRECTORY_SEPARATOR === '\\') {
            $base_path = __DIR__ . '/../../storage/default';
            mkdir($base_path, 0775, true);
        } else {
            $setup_error = "Failed to create base directory: " . ($error['message'] ?? 'Unknown error');
            error_log("SHM-FM Critical: $setup_error");
        }
    }
}

// Set proper permissions for the base path
if (is_dir($base_path)) {
    // Try to make it writable by the web server
    if (!is_writable($base_path)) {
        // First try to change owner to web server user if possible
        $web_user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : null;
        if ($web_user && function_exists('chown')) {
            @chown($base_path, $web_user['name']);
            @chgrp($base_path, $web_user['name']);
        }
        // Then set permissions
        @chmod($base_path, 0775);
    }
}

$full_path = shm_build_path($base_path, $current_path);

// Validate full path
if ($full_path === false) {
    die("Invalid path detected. Security violation prevented.");
}

// Auto-create subfolders if missing
if (!file_exists($full_path)) {
    $old_umask = umask(0);
    $created = mkdir($full_path, 0775, true);
    umask($old_umask);
    clearstatcache(true, $full_path);

    if (!$created) {
        $err = error_get_last();
        if (!$setup_error)
            $setup_error = "Failed to create subfolder: " . ($err['message'] ?? 'Unknown');
    }
}

// Enhanced writability check
clearstatcache(true, $full_path);
$is_writable = is_writable($full_path);

// FIX 2: More robust writability test
if ($is_writable) {
    $test_file = $full_path . '/.writetest_' . time() . '_' . mt_rand(1000, 9999);
    $test_content = 'test';
    $bytes_written = @file_put_contents($test_file, $test_content);
    if ($bytes_written === false || $bytes_written !== strlen($test_content)) {
        $is_writable = false;
        error_log("SHM-FM: Directory not actually writable - test file creation failed");
    } else {
        // Verify we can read it back
        $read_content = @file_get_contents($test_file);
        if ($read_content !== $test_content) {
            $is_writable = false;
            error_log("SHM-FM: Directory not actually writable - test file readback failed");
        }
        @unlink($test_file);
    }
}

// Get user info for permission debugging
$process_user = (function_exists('posix_getpwuid') && function_exists('posix_geteuid'))
    ? posix_getpwuid(posix_geteuid())['name']
    : get_current_user();
$process_uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

// Helper to return
function fm_return($status, $msg = '', $data = [])
{
    global $domain_id, $current_path;

    // Add helpful tip for permission errors (only when $msg is a string)
    if ($status === 'error' && is_string($msg) && (stripos($msg, 'permission') !== false || stripos($msg, 'writable') !== false || stripos($msg, 'denied') !== false)) {
        $msg .= "<br><br><strong>💡 Tip:</strong> Go to <b>Tools &gt; Troubleshoot</b> and run <b>Fix Permissions</b>.";
    }

    $is_ajax = isset($_POST['ajax']) || isset($_POST['ajax_action']);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['status' => $status, 'msg' => $msg], $data));
    } else {
        header("Location: ?domain_id=$domain_id&path=$current_path");
    }
    exit;
}

// Helper function to format bytes
function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// -------- POST ACTIONS --------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $is_ajax = isset($_POST['ajax']) || isset($_POST['ajax_action']);

    // ============================================
    // FIX 3: ENHANCED CHMOD - File Permission Update
    // ============================================
    if (isset($_POST['chmod_item'])) {
        $target = shm_build_path($base_path, $_POST['item']);

        if (!$target || !file_exists($target)) {
            error_log("SHM-FM CHMOD Error: Target not found - " . ($_POST['item'] ?? 'null'));
            fm_return('error', 'Target file/folder not found');
        }

        // Validate mode input - must be 3-4 digit octal
        $mode_input = $_POST['mode'] ?? '';
        if (!preg_match('/^[0-7]{3,4}$/', $mode_input)) {
            error_log("SHM-FM CHMOD Error: Invalid mode - $mode_input");
            fm_return('error', 'Invalid permission mode. Must be 3-4 digit octal (e.g., 755, 0775)');
        }

        // Convert to octal integer
        $mode = octdec($mode_input);

        // Get current permissions
        $old_perms = substr(sprintf('%o', fileperms($target)), -4);

        // Attempt to change permissions
        $result = @chmod($target, $mode);

        if ($result) {
            // Verify the change took effect
            clearstatcache(true, $target);
            $new_perms = substr(sprintf('%o', fileperms($target)), -4);

            // Some systems may not support chmod (e.g., Windows)
            if ($old_perms === $new_perms && DIRECTORY_SEPARATOR !== '\\') {
                error_log("SHM-FM CHMOD Warning: Permissions unchanged after chmod. Old: $old_perms, New: $new_perms");
            }

            fm_return('success', "Permissions updated from $old_perms to $new_perms");
        } else {
            $error = error_get_last();
            $error_msg = $error['message'] ?? 'Unknown error';
            error_log("SHM-FM CHMOD Error: Failed to chmod $target to $mode_input - $error_msg");

            // Provide helpful error with solutions
            $solutions = [];

            // Try alternative methods
            if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
                $escaped_target = escapeshellarg($target);
                $exec_result = exec("chmod $mode_input $escaped_target 2>&1", $output, $return_var);
                if ($return_var === 0) {
                    clearstatcache(true, $target);
                    $new_perms = substr(sprintf('%o', fileperms($target)), -4);
                    fm_return('success', "Permissions updated from $old_perms to $new_perms (via exec)");
                }
            }

            $solutions[] = "Contact your hosting provider to change file ownership";
            $solutions[] = "Use your hosting control panel's file manager";
            $solutions[] = "Upload files via FTP which preserves your ownership";

            fm_return('error', [
                'msg' => "Cannot change permissions: Permission denied.",
                'details' => "Process user: $process_user (UID: $process_uid), Error: $error_msg",
                'solutions' => $solutions,
                'current_perms' => $old_perms
            ]);
        }
    }

    // ============================================
    // FIX 4: ENHANCED FILE UPLOAD
    // ============================================
    if (isset($_POST['upload_files'])) {
        // Enhanced writability check
        clearstatcache(true, $full_path);
        $actual_writable = is_writable($full_path);

        // Double-check with test file
        if ($actual_writable) {
            $test_file = $full_path . '/.writetest_' . time();
            if (@file_put_contents($test_file, 'test') === false) {
                $actual_writable = false;
                error_log("SHM-FM Upload Error: Directory not writable - test file creation failed");
            } else {
                @unlink($test_file);
            }
        }

        if (!$actual_writable) {
            // Try to fix permissions automatically
            $old_umask = umask(0);
            @chmod($full_path, 0775);
            umask($old_umask);

            // Re-check after chmod attempt
            clearstatcache(true, $full_path);
            $test_file = $full_path . '/.writetest_' . time();
            if (@file_put_contents($test_file, 'test') !== false) {
                @unlink($test_file);
                $actual_writable = true;
            }

            if (!$actual_writable) {
                error_log("SHM-FM Upload Error: Directory not writable. Path: $full_path, Process: $process_user");
                fm_return('error', 'Upload failed: Directory is not writable. Check permissions for path: ' . $current_path);
            }
        }

        // Check if files were received
        if (!isset($_FILES['files']) || empty($_FILES['files']['name'])) {
            error_log("SHM-FM Upload Error: No files received in \$_FILES");
            fm_return('error', 'No files received. Please select files to upload.');
        }

        $count = 0;
        $errors = [];
        $max_file_size = 2048 * 1024 * 1024; // 2048MB
        $allowed_extensions = [
            // Images
            'jpg',
            'jpeg',
            'png',
            'gif',
            'bmp',
            'svg',
            'webp',
            // Documents
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'txt',
            'csv',
            // Archives
            'zip',
            'rar',
            '7z',
            'tar',
            'gz',
            // Code
            'php',
            'html',
            'htm',
            'css',
            'js',
            'json',
            'xml',
            'sql',
            // Media
            'mp3',
            'mp4',
            'avi',
            'mov',
            'wmv',
            'flv',
            'webm'
        ];

        // Handle both single file and multiple files upload
        $file_names = is_array($_FILES['files']['name']) ? $_FILES['files']['name'] : [$_FILES['files']['name']];
        $file_tmps = is_array($_FILES['files']['tmp_name']) ? $_FILES['files']['tmp_name'] : [$_FILES['files']['tmp_name']];
        $file_errors = is_array($_FILES['files']['error']) ? $_FILES['files']['error'] : [$_FILES['files']['error']];
        $file_sizes = is_array($_FILES['files']['size']) ? $_FILES['files']['size'] : [$_FILES['files']['size']];
        $file_types = is_array($_FILES['files']['type']) ? $_FILES['files']['type'] : [$_FILES['files']['type']];

        foreach ($file_names as $key => $name) {
            // Sanitize filename
            $name = basename($name);
            $name = preg_replace('/[^\w\.\-]/', '_', $name);

            if (empty($name)) {
                $errors[] = "File #$key: Invalid filename";
                continue;
            }

            // Check file size
            $file_size = $file_sizes[$key] ?? 0;
            if ($file_size > $max_file_size) {
                $errors[] = "$name: File too large (max: 2GB)";
                continue;
            }

            // Check file extension
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_extensions)) {
                $errors[] = "$name: File type not allowed";
                continue;
            }

            $target = $full_path . '/' . $name;

            // Check for upload errors
            $upload_error = $file_errors[$key] ?? UPLOAD_ERR_NO_FILE;

            if ($upload_error !== UPLOAD_ERR_OK) {
                $error_msg = shm_get_upload_error_message($upload_error);
                $errors[] = "$name: $error_msg";
                error_log("SHM-FM Upload Error: $name - $error_msg (Code: $upload_error)");
                continue;
            }

            $tmp_name = $file_tmps[$key] ?? '';

            // Verify the uploaded file exists
            if (!file_exists($tmp_name) || !is_uploaded_file($tmp_name)) {
                $errors[] = "$name: Invalid upload file";
                error_log("SHM-FM Upload Error: Invalid upload file - $tmp_name");
                continue;
            }

            // Check if target already exists
            $counter = 1;
            $original_name = $name;
            while (file_exists($target)) {
                $pathinfo = pathinfo($original_name);
                $name = $pathinfo['filename'] . '_' . $counter . '.' . ($pathinfo['extension'] ?? '');
                $target = $full_path . '/' . $name;
                $counter++;
            }

            // Move the uploaded file
            if (@move_uploaded_file($tmp_name, $target)) {
                $count++;
                // Set appropriate permissions for the uploaded file
                $old_umask = umask(0);
                @chmod($target, 0644);
                umask($old_umask);
            } else {
                $move_error = error_get_last();
                $error_msg = $move_error['message'] ?? 'Unknown error';
                $errors[] = "$name: Failed to save file - $error_msg";
                error_log("SHM-FM Upload Error: Could not move $tmp_name to $target - $error_msg");
            }
        }

        if ($count > 0) {
            $msg = $count . " file" . ($count > 1 ? "s" : "") . " uploaded successfully";
            if (!empty($errors)) {
                $msg .= " (" . count($errors) . " failed)";
            }
            fm_return('success', $msg);
        } else {
            fm_return('error', 'Upload failed: ' . implode(', ', $errors));
        }
    }

    // Permission check: only LOG the warning — do NOT block operations.
    // Each individual operation handles its own failure with specific errors.
    if (!$is_writable && !isset($_POST['chmod_item']) && !isset($_POST['upload_files']) && !isset($_POST['download_items']) && !isset($_POST['preview_item'])) {
        $process_user_tmp = (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
        error_log("SHM-FM Warning: Directory may not be writable. Path: $full_path | Process: $process_user_tmp");
    }

    // Safe path helper: validates path is inside base WITHOUT requiring physical existence (for copy/move dest)
    function shm_safe_dest_path($base, $relative) {
        $real_base = realpath($base);
        if ($real_base === false) return false;
        $real_base = rtrim(str_replace('\\', '/', $real_base), '/');
        $relative = str_replace(chr(0), '', $relative);
        $relative = str_replace(['\\', '//'], '/', $relative);
        $parts = array_filter(explode('/', ltrim($relative, '/')));
        $safe = [];
        foreach ($parts as $part) {
            if ($part === '.') continue;
            if ($part === '..') { if (!empty($safe)) array_pop($safe); }
            else $safe[] = $part;
        }
        $full = $real_base . '/' . implode('/', $safe);
        if (strpos($full, $real_base) !== 0) return false;
        return $full;
    }

    // 2. CREATE
    if (isset($_POST['create_item'])) {
        $name = preg_replace('/[^\w\.\-]/', '', $_POST['name'] ?? '');
        if (empty($name)) fm_return('error', 'Invalid name — only letters, numbers, dots and dashes allowed');
        $target = $full_path . '/' . $name;
        if (file_exists($target)) fm_return('error', 'An item with that name already exists');

        if (($_POST['type'] ?? 'file') === 'folder') {
            $old_umask = umask(0);
            $ok = @mkdir($target, 0775, true);
            umask($old_umask);
            if ($ok) fm_return('success', 'Folder created');
            $err = error_get_last();
            fm_return('error', 'Folder creation failed: ' . ($err['message'] ?? 'Permission denied'));
        } else {
            $old_umask = umask(0);
            $bytes = @file_put_contents($target, '');
            umask($old_umask);
            if ($bytes !== false) { @chmod($target, 0644); fm_return('success', 'File created'); }
            $err = error_get_last();
            fm_return('error', 'File creation failed: ' . ($err['message'] ?? 'Permission denied'));
        }
    }

    // 3. DELETE
    if (isset($_POST['delete_paths'])) {
        $count = 0;
        $del_errors = [];
        $del_paths = is_array($_POST['paths'] ?? null) ? $_POST['paths'] : [];
        foreach ($del_paths as $p) {
            $abs = shm_build_path($base_path, $p);
            if (!$abs || $abs === $base_path || strpos($abs, $base_path) !== 0) continue;
            if (shm_rrmdir($abs)) { $count++; }
            else { $del_errors[] = basename($abs) . ': delete failed'; }
        }
        $dmsg = "$count item" . ($count !== 1 ? 's' : '') . " deleted";
        if (!empty($del_errors)) $dmsg .= ' (' . implode(', ', $del_errors) . ')';
        fm_return('success', $dmsg);
    }

    // 4. ZIP / ARCHIVE
    if (isset($_POST['zip_paths'])) {
        if (!class_exists('ZipArchive')) fm_return('error', 'ZipArchive PHP extension not available on this server');
        $zip_paths = is_array($_POST['paths'] ?? null) ? $_POST['paths'] : [];
        if (empty($zip_paths)) fm_return('error', 'No files selected to archive');
        $zip = new ZipArchive();
        $zip_name = $full_path . '/' . (count($zip_paths) > 1 ? 'archive_' . date('Ymd_His') . '.zip' : basename($zip_paths[0]) . '.zip');
        $open_res = $zip->open($zip_name, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($open_res !== TRUE) fm_return('error', 'Cannot create archive — check write permissions (code: ' . $open_res . ')');
        foreach ($zip_paths as $p) {
            $abs = shm_build_path($base_path, $p);
            if (!$abs) continue;
            if (is_file($abs)) {
                $zip->addFile($abs, basename($abs));
            } elseif (is_dir($abs)) {
                $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($iter as $fobj) {
                    if (!$fobj->isDir()) { $zip->addFile($fobj->getRealPath(), basename($abs) . '/' . substr($fobj->getRealPath(), strlen($abs) + 1)); }
                }
            }
        }
        $zip->close();
        @chmod($zip_name, 0644);
        fm_return('success', 'Archive created: ' . basename($zip_name));
    }

    // 5. RENAME
    if (isset($_POST['rename_item'])) {
        $old = shm_build_path($base_path, $_POST['old'] ?? '');
        $new_name = preg_replace('/[^\w\.\-]/', '_', basename($_POST['new_name'] ?? ''));
        if (!$old || !file_exists($old)) fm_return('error', 'Source file not found');
        if (empty($new_name)) fm_return('error', 'Invalid new name');
        $new = shm_build_path($base_path, dirname($_POST['old']) . '/' . $new_name);
        if (!$new) fm_return('error', 'Invalid destination path');
        if (file_exists($new) && realpath($new) !== realpath($old))
            fm_return('error', 'A file or folder with that name already exists');
        if (@rename($old, $new)) fm_return('success', 'Renamed to ' . $new_name);
        $err = error_get_last();
        fm_return('error', 'Rename failed: ' . ($err['message'] ?? 'Permission denied'));
    }

    // 6. COPY / MOVE
    if (isset($_POST['copy_move_items'])) {
        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['copy', 'move'])) fm_return('error', 'Invalid action');
        $cm_paths = is_array($_POST['paths'] ?? null) ? $_POST['paths'] : [];
        if (empty($cm_paths)) fm_return('error', 'No files selected');
        // Use safe path (doesn't require physical existence)
        $dest_folder = shm_safe_dest_path($base_path, $_POST['destination'] ?? '');
        if (!$dest_folder) fm_return('error', 'Invalid or unsafe destination path');
        // Auto-create destination folder if it doesn't exist
        if (!is_dir($dest_folder)) {
            $old_umask = umask(0); $made = @mkdir($dest_folder, 0775, true); umask($old_umask);
            if (!$made) fm_return('error', 'Destination folder does not exist and could not be created');
        }
        $count = 0; $cm_errors = [];
        foreach ($cm_paths as $p) {
            $src = shm_build_path($base_path, $p);
            if (!$src) { $cm_errors[] = "$p: invalid path"; continue; }
            $dest_item = $dest_folder . '/' . basename($src);
            if ($action === 'move') {
                if (@rename($src, $dest_item)) { $count++; }
                else { $err = error_get_last(); $cm_errors[] = basename($src) . ': ' . ($err['message'] ?? 'move failed'); }
            } else {
                if (shm_rcopy($src, $dest_item)) { $count++; }
                else { $cm_errors[] = basename($src) . ': copy failed'; }
            }
        }
        $verb = $action === 'move' ? 'moved' : 'copied';
        $cmsg = "$count item" . ($count !== 1 ? 's' : '') . " $verb";
        if (!empty($cm_errors)) $cmsg .= ' (' . count($cm_errors) . ' failed: ' . implode(', ', $cm_errors) . ')';
        if ($count > 0) fm_return('success', $cmsg);
        fm_return('error', ucfirst($action) . ' failed: ' . implode(', ', $cm_errors));
    }

    // 7. UNZIP / EXTRACT
    if (isset($_POST['unzip_item'])) {
        if (!class_exists('ZipArchive')) fm_return('error', 'ZipArchive PHP extension not available on this server');
        $zip_file = shm_build_path($base_path, $_POST['item'] ?? '');
        if (!$zip_file || !is_file($zip_file)) fm_return('error', 'Zip file not found');
        $zip = new ZipArchive;
        $open_res = $zip->open($zip_file);
        if ($open_res === TRUE) {
            $zip->extractTo(dirname($zip_file));
            $zip->close();
            fm_return('success', 'Extracted to ' . dirname($_POST['item']));
        }
        fm_return('error', 'Extraction failed (ZipArchive error code: ' . $open_res . ')');
    }

    // 8. DOWNLOAD
    if (isset($_POST['download_items'])) {
        $dl_paths = is_array($_POST['paths'] ?? null) ? $_POST['paths'] : [];
        if (empty($dl_paths)) { header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'msg' => 'No files selected']); exit; }

        // Single file — direct stream
        if (count($dl_paths) === 1) {
            $file = shm_build_path($base_path, $dl_paths[0]);
            if ($file && is_file($file)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . addslashes(basename($file)) . '"');
                header('Content-Length: ' . filesize($file));
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');
                ob_clean(); flush();
                readfile($file);
                exit;
            }
        }

        // Multi-file or folder — bundle into zip
        if (!class_exists('ZipArchive')) { header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'msg' => 'ZipArchive not available']); exit; }
        $zip_name = 'download_' . date('Ymd_His') . '.zip';
        $tmp_zip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zip_name;
        $zip = new ZipArchive();
        if ($zip->open($tmp_zip, ZipArchive::CREATE) !== TRUE) {
            header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'msg' => 'Could not create temporary archive']); exit;
        }
        foreach ($dl_paths as $p) {
            $abs = shm_build_path($base_path, $p);
            if (!$abs) continue;
            if (is_file($abs)) {
                $zip->addFile($abs, basename($abs));
            } elseif (is_dir($abs)) {
                $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($iter as $fobj) {
                    if (!$fobj->isDir()) { $zip->addFile($fobj->getRealPath(), basename($abs) . '/' . substr($fobj->getRealPath(), strlen($abs) + 1)); }
                }
            }
        }
        $zip->close();
        if (file_exists($tmp_zip)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_name . '"');
            header('Content-Length: ' . filesize($tmp_zip));
            ob_clean(); flush();
            readfile($tmp_zip);
            @unlink($tmp_zip);
            exit;
        }
        header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'msg' => 'Download archive could not be created']); exit;
    }

    // 9. PREVIEW
    if (isset($_POST['preview_item'])) {
        header('Content-Type: application/json');
        $file = shm_build_path($base_path, $_POST['item'] ?? '');
        if (!$file || !is_file($file)) {
            echo json_encode(['status' => 'error', 'msg' => 'File not found']);
            exit;
        }
        $filesize = filesize($file);
        if ($filesize > 10485760) {
            echo json_encode(['status' => 'error', 'msg' => 'File too large for preview (max 10MB)']);
            exit;
        }
        $content = file_get_contents($file, false, NULL, 0, 102400); // up to 100KB preview
        echo json_encode(['status' => 'success', 'type' => 'code', 'content' => htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')]);
        exit;
    }
}

/**
 * Helper function to get human-readable upload error messages
 */
function shm_get_upload_error_message($code)
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'File exceeds upload_max_filesize directive in php.ini';
        case UPLOAD_ERR_FORM_SIZE:
            return 'File exceeds MAX_FILE_SIZE directive in HTML form';
        case UPLOAD_ERR_PARTIAL:
            return 'File was only partially uploaded';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Missing temporary folder';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write file to disk';
        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension stopped the file upload';
        default:
            return 'Unknown upload error (code: ' . $code . ')';
    }
}

// -------- READ DIRECTORY --------
$items = [];
if (is_dir($full_path)) {
    $scan = scandir($full_path);
    if ($scan !== false) {
        foreach ($scan as $item) {
            if ($item === '.' || $item === '..')
                continue;
            $abs = $full_path . '/' . $item;
            $items[] = [
                'name' => $item,
                'is_dir' => is_dir($abs),
                'size' => is_dir($abs) ? '-' : formatBytes(filesize($abs)),
                'perm' => substr(sprintf('%o', fileperms($abs)), -4),
                'date' => date("Y-m-d H:i", filemtime($abs)),
                'rel' => shm_normalize_relative($current_path . '/' . $item)
            ];
        }

        // Sort: Folders first, then Files
        usort($items, function ($a, $b) {
            if ($a['is_dir'] && !$b['is_dir']) {
                return -1;
            }
            if (!$a['is_dir'] && $b['is_dir']) {
                return 1;
            }
            return strnatcasecmp($a['name'], $b['name']);
        });
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title>File Manager | Vivzon Cloud</title>
    <link rel="stylesheet" href="/assets/css/modern-design.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <style>
        /* Ã¢â€â‚¬Ã¢â€â‚¬ File Manager Specific Styles Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ */
        .fm-shell {
            display: flex;
            height: 100vh;
            overflow: hidden;
            background: var(--slate-50);
        }

        /* Ã¢â€â‚¬Ã¢â€â‚¬ Top Bar Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ */
        .fm-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 4rem;
            padding: 0 1.5rem;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--slate-200);
            flex-shrink: 0;
            gap: 1rem;
            z-index: 20;
        }

        .fm-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .fm-brand-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .fm-brand-title {
            font-family: var(--font-heading);
            font-weight: 800;
            font-size: 1rem;
            color: var(--slate-900);
            letter-spacing: -0.02em;
        }

        .fm-divider {
            width: 1px;
            height: 1.5rem;
            background: var(--slate-200);
            flex-shrink: 0;
        }

        /* Breadcrumb */
        .fm-breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8125rem;
            font-weight: 500;
            min-width: 0;
            flex: 1;
        }

        .fm-breadcrumb a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            min-width: 28px;
            height: 28px;
            border-radius: var(--radius-md);
            background: var(--primary-light);
            color: var(--primary);
            text-decoration: none;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .fm-breadcrumb a:hover {
            background: rgba(37, 99, 235, 0.2);
            transform: scale(1.05);
        }

        .fm-breadcrumb .crumb {
            padding: 0.3rem 0.6rem;
            border-radius: var(--radius-md);
            color: var(--slate-600);
            transition: all 0.15s;
            text-decoration: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 120px;
            display: inline-block;
        }

        .fm-breadcrumb .crumb:hover {
            background: white;
            color: var(--slate-900);
            box-shadow: var(--shadow-sm);
        }

        .fm-breadcrumb .crumb-sep {
            color: var(--slate-300);
            flex-shrink: 0;
        }

        /* Top bar right actions */
        .fm-topbar-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-shrink: 0;
        }

        .fm-search-wrap {
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .fm-search-wrap>i {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--slate-400);
            pointer-events: none;
            width: 14px;
            height: 14px;
        }

        .fm-search {
            width: 200px;
            height: 2.25rem;
            padding: 0 0.875rem 0 2.5rem;
            background: var(--slate-100);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-full);
            font-size: 0.8125rem;
            font-family: inherit;
            color: var(--slate-900);
            outline: none;
            transition: all 0.25s;
        }

        .fm-search:focus {
            width: 240px;
            background: white;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .fm-view-toggle {
            display: flex;
            background: var(--slate-100);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 2px;
        }

        .fm-view-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 26px;
            border: none;
            background: transparent;
            border-radius: calc(var(--radius-md) - 2px);
            color: var(--slate-500);
            cursor: pointer;
            transition: all 0.15s;
        }

        .fm-view-btn.active {
            background: white;
            color: var(--primary);
            box-shadow: var(--shadow-sm);
        }

        /* Ã¢â€â‚¬Ã¢â€â‚¬ Left panel (domains sidebar) Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ */
        .fm-left {
            width: 220px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--slate-200);
            background: white;
            overflow: hidden;
        }

        .fm-left-header {
            padding: 1rem;
            border-bottom: 1px solid var(--slate-100);
        }

        .fm-new-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.625rem;
            background: var(--primary-light);
            color: var(--primary);
            border: 1.5px dashed rgba(37, 99, 235, 0.3);
            border-radius: var(--radius-md);
            font-size: 0.8125rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }

        .fm-new-btn:hover {
            background: var(--primary);
            color: white;
            border-style: solid;
            border-color: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }

        .fm-left-section-label {
            padding: 0.75rem 0.875rem 0.375rem;
            font-size: 0.625rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--slate-400);
            font-family: var(--font-heading);
        }

        .fm-left-links {
            flex: 1;
            overflow-y: auto;
            padding: 0 0.5rem 1rem;
        }

        .fm-left-link {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-md);
            color: var(--slate-600);
            font-size: 0.8125rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.15s;
            margin-bottom: 1px;
        }

        .fm-left-link:hover {
            background: var(--slate-50);
            color: var(--slate-900);
        }

        .fm-left-link.active {
            background: var(--primary-light);
            color: var(--primary);
            font-weight: 500;
        }

        .fm-left-link.active i {
            color: var(--primary);
        }

        /* Storage bar */
        .fm-storage {
            padding: 1rem;
            border-top: 1px solid var(--slate-100);
        }

        .fm-storage-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.6875rem;
            font-weight: 500;
            color: var(--slate-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .fm-storage-bar {
            height: 4px;
            background: var(--slate-100);
            border-radius: 9999px;
            overflow: hidden;
        }

        .fm-storage-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 9999px;
        }

        /* Ã¢â€â‚¬Ã¢â€â‚¬ Main content area Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ */
        .fm-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: var(--slate-50);
        }

        /* Ã¢â€â‚¬Ã¢â€â‚¬ Action bar (contextual) Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ */
        .fm-action-bar {
            position: relative;
            height: 0;
            overflow: visible;
            z-index: 30;
            pointer-events: none;
        }

        .fm-action-bar-inner {
            position: absolute;
            top: 0.75rem;
            left: 50%;
            transform: translateX(-50%) translateY(-120px);
            background: rgba(15, 23, 42, 0.88);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: var(--radius-xl);
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            font-size: 0.8125rem;
            font-weight: 500;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s;
            opacity: 0;
            pointer-events: none;
        }

        .fm-action-bar-inner.visible {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
            pointer-events: all;
        }

        .fm-bar-sep {
            width: 1px;
            height: 1.25rem;
            background: rgba(255, 255, 255, 0.15);
            margin: 0 0.25rem;
        }

        .fm-bar-btn {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.45rem 0.75rem;
            border-radius: var(--radius-md);
            border: none;
            background: transparent;
            color: rgba(255, 255, 255, 0.75);
            font-size: 0.8125rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            font-family: inherit;
        }

        .fm-bar-btn:hover {
            color: white;
            background: rgba(255, 255, 255, 0.1);
        }

        .fm-bar-btn.danger {
            color: #f87171;
        }

        .fm-bar-btn.danger:hover {
            color: #fca5a5;
            background: rgba(248, 113, 113, 0.12);
        }

        .fm-bar-count {
            background: rgba(59, 130, 246, 0.25);
            color: #93c5fd;
            padding: 0.2rem 0.6rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 800;
        }

        /* Ã¢â€â‚¬Ã¢â€â‚¬ File list area Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ */
        .fm-file-area {
            flex: 1;
            overflow: hidden;
            position: relative;
        }

        #file-view {
            height: 100%;
            overflow-y: auto;
            padding: 0px;
        }

        /* List header */
        .fm-list-header {
            display: grid;
            grid-template-columns: 1fr 90px 80px 130px;
            gap: 0.5rem;
            padding: 0.5rem 1rem 0.5rem 3.25rem;
            font-size: 0.6875rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--slate-400);
            font-family: var(--font-heading);
            border-bottom: 1px solid var(--slate-200);
            margin-bottom: 0.5rem;
            position: sticky;
            top: 0;
            background: var(--slate-50);
            z-index: 5;
        }

        /* File item */
        .file-item {
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: background 0.15s;
            user-select: none;
        }

        .file-item:hover {
            background: white;
        }

        .file-item.selected {
            background: rgba(37, 99, 235, 0.06) !important;
        }

        .file-item.selected .fi-name {
            color: var(--primary) !important;
        }

        /* List layout */
        .list-layout {
            display: grid;
            grid-template-columns: 1fr 90px 80px 130px;
            gap: 0.5rem;
            align-items: center;
            padding: 0.625rem 1rem 0.625rem 0.75rem;
        }

        .fi-name-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            overflow: hidden;
            min-width: 0;
        }

        .fi-checkbox {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            accent-color: var(--primary);
            opacity: 0;
            cursor: pointer;
            transition: opacity 0.15s;
        }

        .file-item:hover .fi-checkbox,
        .file-item.selected .fi-checkbox {
            opacity: 1;
        }

        .fi-icon-wrap {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .fi-name {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--slate-800);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
            transition: color 0.15s;
        }

        .fi-size {
            font-size: 0.8125rem;
            color: var(--slate-500);
            font-family: var(--font-mono);
        }

        .fi-type {
            font-size: 0.6875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--slate-400);
        }

        .fi-date {
            font-size: 0.75rem;
            color: var(--slate-500);
            font-family: var(--font-mono);
        }

        /* Grid layout */
        #file-view.view-grid .list-layout {
            display: none !important;
        }

        #file-view.view-grid .grid-layout {
            display: flex !important;
        }

        #file-view.view-grid .fm-list-header {
            display: none;
        }

        #file-view.view-grid #file-view-inner {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 0.75rem;
        }

        #file-view.view-list .grid-layout {
            display: none !important;
        }

        .grid-layout {
            display: none;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 1.25rem 0.75rem 1rem;
            gap: 0.625rem;
            position: relative;
        }

        .grid-layout .fi-icon-wrap {
            width: 56px;
            height: 56px;
            border-radius: 1rem;
        }

        .grid-layout .fi-name {
            font-size: 0.75rem;
            width: 100%;
            white-space: normal;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .grid-cb-wrap {
            position: absolute;
            top: 0.5rem;
            left: 0.5rem;
            opacity: 0;
            transition: opacity 0.15s;
        }

        .file-item:hover .grid-cb-wrap,
        .file-item.selected .grid-cb-wrap {
            opacity: 1;
        }

        /* Icon colours */
        .ic-folder {
            background: rgba(245, 158, 11, 0.12);
            color: #f59e0b;
        }

        .ic-img {
            background: rgba(139, 92, 246, 0.12);
            color: #8b5cf6;
        }

        .ic-video {
            background: rgba(239, 68, 68, 0.12);
            color: #ef4444;
        }

        .ic-audio {
            background: rgba(236, 72, 153, 0.12);
            color: #ec4899;
        }

        .ic-archive {
            background: rgba(249, 115, 22, 0.12);
            color: #f97316;
        }

        .ic-code {
            background: rgba(37, 99, 235, 0.12);
            color: var(--primary);
        }

        .ic-file {
            background: var(--slate-100);
            color: var(--slate-500);
        }

        /* Parent dir row */
        .fm-parent-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 1rem 0.625rem 0.75rem;
            border-radius: var(--radius-lg);
            color: var(--slate-500);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
            margin-bottom: 0.25rem;
        }

        .fm-parent-row:hover {
            background: white;
            color: var(--slate-900);
        }

        /* Empty state */
        .fm-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 5rem 2rem;
            color: var(--slate-400);
            text-align: center;
        }

        .fm-empty-icon {
            width: 72px;
            height: 72px;
            background: var(--slate-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }

        /* Drop overlay */
        #drag-overlay {
            position: absolute;
            inset: 1rem;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(10px);
            border: 2.5px dashed var(--primary);
            border-radius: 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 50;
            pointer-events: none;
        }

        /* Ã¢â€â‚¬Ã¢â€â‚¬ Modals Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ */
        .modal {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(8px);
            opacity: 0;
            transition: opacity 0.25s;
            pointer-events: none;
            padding: 1rem;
        }

        .modal:not(.hidden) {
            opacity: 1;
            pointer-events: all;
        }

        .modal-box {
            background: white;
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
            width: 100%;
            overflow: hidden;
            transform: translateY(16px) scale(0.98);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .modal:not(.hidden) .modal-box {
            transform: translateY(0) scale(1);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-100);
        }

        .modal-title {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            font-family: var(--font-heading);
            font-weight: 800;
            font-size: 1.125rem;
            color: var(--slate-900);
        }

        .modal-title-icon {
            width: 34px;
            height: 34px;
            background: var(--primary-light);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .modal-close {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: transparent;
            border-radius: var(--radius-md);
            color: var(--slate-400);
            cursor: pointer;
            transition: all 0.15s;
        }

        .modal-close:hover {
            background: var(--slate-100);
            color: var(--slate-700);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.625rem;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--slate-100);
            background: var(--slate-50);
        }

        /* Drop zone */
        .fm-drop-zone {
            border: 2px dashed var(--slate-300);
            border-radius: var(--radius-xl);
            padding: 2.5rem 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 1.25rem;
        }

        .fm-drop-zone:hover {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        /* Type toggle */
        .fm-type-toggle {
            display: flex;
            background: var(--slate-100);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 3px;
            margin-bottom: 1.25rem;
        }

        .fm-type-btn {
            flex: 1;
            padding: 0.4rem;
            border: none;
            border-radius: calc(var(--radius-md) - 2px);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            background: transparent;
            color: var(--slate-500);
            font-family: inherit;
        }

        .fm-type-btn.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }

        /* Context menu */
        #ctx-menu {
            position: fixed;
            z-index: 70;
            background: white;
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-xl);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(0, 0, 0, 0.04);
            width: 13rem;
            padding: 0.375rem;
            transform: scale(0.95);
            opacity: 0;
            transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.1s;
            transform-origin: top left;
        }

        #ctx-menu:not(.hidden) {
            transform: scale(1);
            opacity: 1;
        }

        .ctx-item {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.5rem 0.875rem;
            border-radius: var(--radius-md);
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--slate-700);
            cursor: pointer;
            transition: all 0.12s;
            border: none;
            background: transparent;
            width: 100%;
            text-align: left;
            font-family: inherit;
        }

        .ctx-item:hover {
            background: var(--slate-50);
            color: var(--slate-900);
        }

        .ctx-item.danger {
            color: var(--accent-red);
        }

        .ctx-item.danger:hover {
            background: rgba(239, 68, 68, 0.08);
            color: #dc2626;
        }

        .ctx-sep {
            height: 1px;
            background: var(--slate-100);
            margin: 0.25rem 0;
        }

        /* Toast */
        #toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 0.875rem;
            padding: 0.875rem 1.25rem;
            background: rgba(15, 23, 42, 0.92);
            backdrop-filter: blur(12px);
            color: white;
            border-radius: var(--radius-lg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 16px 32px rgba(0, 0, 0, 0.2);
            font-size: 0.875rem;
            font-weight: 500;
            min-width: 240px;
            max-width: 380px;
            transform: translateY(5rem) scale(0.9);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        #toast.visible {
            transform: translateY(0) scale(1);
            opacity: 1;
        }

        #toast-icon-wrapper,
        #toast-icon-wrap {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* Scrollbar */
        .fm-scroll::-webkit-scrollbar {
            width: 5px;
        }

        .fm-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .fm-scroll::-webkit-scrollbar-thumb {
            background: var(--slate-200);
            border-radius: 9999px;
        }

        .fm-scroll::-webkit-scrollbar-thumb:hover {
            background: var(--slate-300);
        }

        /* Warning box */
        .fm-warning {
            padding: 0.75rem 1rem;
            background: rgba(245, 158, 11, 0.08);
            border: 1px solid rgba(245, 158, 11, 0.25);
            border-radius: var(--radius-md);
            display: flex;
            align-items: flex-start;
            gap: 0.625rem;
            font-size: 0.8125rem;
            color: #92400e;
            margin-bottom: 1rem;
        }

        @keyframes fm-bounce {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-8px);
            }
        }

        .fm-upload-icon {
            animation: fm-bounce 2s ease-in-out infinite;
        }
    </style>
</head>

<body style="background:var(--slate-50);">

    <?php
    $current_page = 'files.php';
    $no_sidebar = true; // File manager is full-screen, no cpanel sidebar needed
    include 'layout/sidebar.php';
    ?>

    <!-- FILE MANAGER SHELL -->
    <div style="flex:1;display:flex;flex-direction:column;height:100vh;overflow:hidden;">

        <!-- TOP BAR -->
        <header class="fm-topbar">
            <div class="fm-brand">
                <div class="fm-brand-icon">
                    <i data-lucide="folder-kanban" style="width:18px;height:18px;color:white;"></i>
                </div>
                <span class="fm-brand-title">File Manager</span>
            </div>

            <div class="fm-divider"></div>
            <!-- Breadcrumbs -->
            <nav class="fm-breadcrumb">
                <a href="?domain_id=<?= $domain_id ?>&path=/" title="Root">
                    <i data-lucide="hard-drive" style="width:14px;height:14px;"></i>
                </a>
                <?php
                $crumbs = array_filter(explode('/', $current_path));
                $acc = '';
                foreach ($crumbs as $c):
                    $acc .= '/' . $c;
                    ?>
                    <i data-lucide="chevron-right" class="crumb-sep" style="width:13px;height:13px;"></i>
                    <a href="?domain_id=<?= $domain_id ?>&path=<?= $acc ?>" class="crumb"><?= htmlspecialchars($c) ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="fm-topbar-right">
                <!-- Search -->
                <div class="fm-search-wrap">
                    <i data-lucide="search"></i>
                    <input id="file-search" onkeyup="FM.filter()" placeholder="Search files..." class="fm-search">
                </div>
                <!-- View Toggle -->
                <div class="fm-view-toggle">
                    <button onclick="FM.setView('list')" id="btn-list" class="fm-view-btn active" title="List">
                        <i data-lucide="list" style="width:14px;height:14px;"></i>
                    </button>
                    <button onclick="FM.setView('grid')" id="btn-grid" class="fm-view-btn" title="Grid">
                        <i data-lucide="layout-grid" style="width:14px;height:14px;"></i>
                    </button>
                </div>
                <div class="fm-divider"></div>
                <button onclick="FM.openCreate()" class="btn btn-secondary btn-sm">
                    <i data-lucide="plus" style="width:14px;height:14px;"></i> New
                </button>
                <button onclick="FM.openUpload()" class="btn btn-primary btn-sm">
                    <i data-lucide="upload-cloud" style="width:14px;height:14px;"></i> Upload
                </button>
            </div>
        </header>

        <div style="display:flex;flex:1;overflow:hidden;">

            <!-- LEFT PANEL (Domain switcher) -->
            <aside class="fm-left">
                <div class="fm-left-links fm-scroll">
                    <div class="fm-left-section-label">Locations</div>
                    <a href="?domain_id=<?= $domain_id ?>&path=/" class="fm-left-link active">
                        <i data-lucide="home" style="width:14px;height:14px;flex-shrink:0;"></i> Home Root
                    </a>
                    <div class="fm-left-section-label" style="margin-top:0.75rem;">Domains</div>
                    <?php
                    $doms = $pdo->prepare("SELECT id, domain FROM domains WHERE client_id = ?");
                    $doms->execute([$user_id]);
                    while ($d = $doms->fetch()):
                        $isActive = $d['id'] == $domain_id;
                        ?>
                        <a href="?domain_id=<?= $d['id'] ?>" class="fm-left-link <?= $isActive ? 'active' : '' ?>">
                            <i data-lucide="globe" style="width:14px;height:14px;flex-shrink:0;"></i>
                            <span
                                style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($d['domain']) ?></span>
                        </a>
                    <?php endwhile; ?>
                </div>
                <div class="fm-storage">
                    <div class="fm-storage-label">
                        <span>Storage</span>
                        <span
                            style="color:var(--slate-700);font-family:var(--font-mono);"><?= $domain['disk_usage'] ?? '0' ?>
                            MB</span>
                    </div>
                    <div class="fm-storage-bar">
                        <?php
                        $disk_used = (float)($domain['disk_usage'] ?? 0);
                        $disk_quota = (float)($domain['disk_quota'] ?? 1000); // fallback 1000 MB
                        $disk_pct = $disk_quota > 0 ? min(100, round($disk_used / $disk_quota * 100, 1)) : 0;
                        ?>
                        <div class="fm-storage-fill" style="width:<?= $disk_pct ?>%;">
                        </div>
                    </div>
                </div>
            </aside>

            <!-- MAIN FILE AREA -->
            <div class="fm-main" id="drop-zone-global" style="position:relative;">

                <!-- FLOATING ACTION BAR -->
                <div class="fm-action-bar">
                    <div class="fm-action-bar-inner" id="action-bar">
                        <span class="fm-bar-count" id="selection-count">0 Selected</span>
                        <div class="fm-bar-sep"></div>
                        <button class="fm-bar-btn" id="btn-select-all" onclick="FM.selectAll(true)" title="Ctrl+A">
                            <i data-lucide="check-square" style="width:13px;height:13px;"></i> All
                        </button>
                        <button class="fm-bar-btn hidden" id="btn-unselect-all" onclick="FM.selectAll(false)">
                            <i data-lucide="square" style="width:13px;height:13px;"></i> None
                        </button>
                        <div class="fm-bar-sep"></div>
                        <button class="fm-bar-btn" onclick="FM.bulk('download')">
                            <i data-lucide="download" style="width:13px;height:13px;"></i> Download
                        </button>
                        <button class="fm-bar-btn" onclick="FM.bulk('zip')">
                            <i data-lucide="archive" style="width:13px;height:13px;"></i> Archive
                        </button>
                        <button class="fm-bar-btn" onclick="FM.bulk('copy')">
                            <i data-lucide="copy" style="width:13px;height:13px;"></i> Copy
                        </button>
                        <button class="fm-bar-btn" onclick="FM.bulk('move')">
                            <i data-lucide="move" style="width:13px;height:13px;"></i> Move
                        </button>
                        <div class="fm-bar-sep"></div>
                        <button class="fm-bar-btn danger" onclick="FM.bulk('delete')" title="Delete (Del)">
                            <i data-lucide="trash-2" style="width:13px;height:13px;"></i> Delete
                        </button>
                        <div class="fm-bar-sep"></div>
                        <button class="fm-bar-btn" onclick="FM.clearSelection()" style="padding:0.45rem 0.5rem;">
                            <i data-lucide="x" style="width:13px;height:13px;"></i>
                        </button>
                    </div>
                </div>

                <div id="file-view" class="view-list fm-scroll" style="background:white;">

                    <!-- LIST HEADER -->
                    <div class="fm-list-header">
                        <div style="display:flex;align-items:center;gap:0.625rem;">
                            <input type="checkbox" id="header-select-all" onchange="FM.selectAll(this.checked)"
                                style="accent-color:var(--primary);width:14px;height:14px;cursor:pointer;flex-shrink:0;">
                            Name
                        </div>
                        <div>Size</div>
                        <div>Type</div>
                        <div>Modified</div>
                    </div>

                    <div id="file-view-inner">
                        <!-- PARENT DIR -->
                        <?php if ($current_path != '/'): ?>
                            <div class="fm-parent-row"
                                onclick="location.href='?domain_id=<?= $domain_id ?>&path=<?= dirname($current_path) ?>'">
                                <i data-lucide="corner-left-up" style="width:15px;height:15px;flex-shrink:0;"></i>
                                <span>Parent Directory</span>
                            </div>
                        <?php endif; ?>

                        <!-- ITEMS LOOP -->
                        <?php foreach ($items as $i):
                            $icon = 'file';
                            $icClass = 'ic-file';
                            $type = $i['is_dir'] ? 'DIR' : strtoupper(pathinfo($i['name'], PATHINFO_EXTENSION) ?: 'FILE');
                            if ($i['is_dir']) {
                                $icon = 'folder';
                                $icClass = 'ic-folder';
                            } else {
                                $ext = strtolower(pathinfo($i['name'], PATHINFO_EXTENSION));
                                if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp'])) {
                                    $icon = 'image';
                                    $icClass = 'ic-img';
                                } elseif (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'flv'])) {
                                    $icon = 'film';
                                    $icClass = 'ic-video';
                                } elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'flac'])) {
                                    $icon = 'music';
                                    $icClass = 'ic-audio';
                                } elseif (in_array($ext, ['zip', 'tar', 'gz', 'rar', '7z'])) {
                                    $icon = 'archive';
                                    $icClass = 'ic-archive';
                                } elseif (in_array($ext, ['php', 'js', 'ts', 'css', 'html', 'htm', 'json', 'xml', 'sql', 'py', 'sh', 'env', 'ini', 'conf', 'htaccess', 'md'])) {
                                    $icon = 'code-2';
                                    $icClass = 'ic-code';
                                }
                            }
                            ?>
                            <div class="file-item" data-name="<?= strtolower(htmlspecialchars($i['name'])) ?>"
                                data-path="<?= htmlspecialchars($i['rel']) ?>"
                                data-type="<?= $i['is_dir'] ? 'dir' : 'file' ?>" onclick="FM.toggleSelect(this, event)"
                                ondblclick="FM.open('<?= htmlspecialchars($i['rel']) ?>','<?= $i['is_dir'] ? 'dir' : 'file' ?>')">

                                <!-- List Layout -->
                                <div class="list-layout">
                                    <div class="fi-name-cell">
                                        <input type="checkbox" class="fi-checkbox file-check"
                                            onclick="event.stopPropagation();">
                                        <div class="fi-icon-wrap <?= $icClass ?>">
                                            <i data-lucide="<?= $icon ?>" style="width:15px;height:15px;"></i>
                                        </div>
                                        <span class="fi-name"><?= htmlspecialchars($i['name']) ?></span>
                                    </div>
                                    <div class="fi-size"><?= htmlspecialchars($i['size']) ?></div>
                                    <div class="fi-type"><?= htmlspecialchars($type) ?></div>
                                    <div class="fi-date"><?= htmlspecialchars($i['date']) ?></div>
                                </div>

                                <!-- Grid Layout -->
                                <div class="grid-layout">
                                    <div class="grid-cb-wrap" onclick="event.stopPropagation();">
                                        <input type="checkbox" class="fi-checkbox file-check"
                                            style="width:14px;height:14px;accent-color:var(--primary);"
                                            onclick="event.stopPropagation();">
                                    </div>
                                    <div class="fi-icon-wrap <?= $icClass ?>">
                                        <i data-lucide="<?= $icon ?>" style="width:26px;height:26px;"></i>
                                    </div>
                                    <div style="width:100%;">
                                        <div class="fi-name"><?= htmlspecialchars($i['name']) ?></div>
                                        <div class="fi-size" style="margin-top:0.2rem;font-size:0.6875rem;">
                                            <?= htmlspecialchars($i['size']) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($items)): ?>
                            <div class="fm-empty">
                                <div class="fm-empty-icon">
                                    <i data-lucide="folder-open" style="width:30px;height:30px;opacity:0.4;"></i>
                                </div>
                                <p style="font-weight: 500;color:var(--slate-600);">This folder is empty</p>
                                <p style="font-size:0.8125rem;margin-top:0.375rem;color:var(--slate-400);">Drag & drop files
                                    here to upload</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- DRAG OVERLAY -->
                <div id="drag-overlay" class="hidden">
                    <div class="fm-upload-icon" style="margin-bottom:1rem;">
                        <i data-lucide="cloud-upload" style="width:52px;height:52px;color:var(--primary);"></i>
                    </div>
                    <h3
                        style="font-family:var(--font-heading);font-weight:800;font-size:1.5rem;color:var(--slate-900);">
                        Drop to upload</h3>
                    <p style="color:var(--slate-500);margin-top:0.375rem;font-size:0.875rem;">to <strong
                            style="color:var(--primary);"><?= htmlspecialchars($current_path) ?></strong></p>
                </div>

            </div><!-- .fm-main -->
        </div>
    </div><!-- shell -->

    <!-- UPLOAD MODAL -->
    <div id="modal-upload" class="modal hidden">
        <div class="modal-box" style="max-width:34rem;">
            <div class="modal-header">
                <div class="modal-title">
                    <div class="modal-title-icon"><i data-lucide="upload-cloud" style="width:16px;height:16px;"></i>
                    </div>
                    Upload Files
                </div>
                <button class="modal-close" onclick="FM.closeModals()"><i data-lucide="x"
                        style="width:15px;height:15px;"></i></button>
            </div>
            <div class="modal-body">
                <div class="fm-drop-zone" onclick="document.getElementById('inp-upload-files').click()"
                    ondrop="FM.handleDrop(event.dataTransfer.files); FM.closeModals();"
                    ondragover="event.preventDefault()">
                    <div class="fm-upload-icon" style="display:inline-flex;margin-bottom:0.75rem;">
                        <i data-lucide="cloud-upload" style="width:40px;height:40px;color:var(--primary);"></i>
                    </div>
                    <p style="font-weight: 500;color:var(--slate-700);margin-bottom:0.25rem;">Drop files or click to
                        browse</p>
                    <p style="font-size:0.8125rem;color:var(--slate-400);">Any file type accepted</p>
                    <input type="file" id="inp-upload-files" multiple style="display:none;"
                        onchange="FM.doUploadInput(this)">
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="FM.closeModals()" class="btn btn-secondary btn-sm">Close</button>
            </div>
        </div>
    </div>

    <!-- CHMOD MODAL -->
    <div id="modal-chmod" class="modal hidden">
        <div class="modal-box" style="max-width:28rem;">
            <div class="modal-header">
                <div class="modal-title">
                    <div class="modal-title-icon"><i data-lucide="lock" style="width:16px;height:16px;"></i></div>
                    Permissions
                </div>
                <button class="modal-close" onclick="FM.closeModals()"><i data-lucide="x"
                        style="width:15px;height:15px;"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="chmod-target">
                <div class="fm-warning hidden" id="chmod-warning">
                    <i data-lucide="alert-triangle"
                        style="width:15px;height:15px;color:#f59e0b;flex-shrink:0;margin-top:1px;"></i>
                    <div>
                        <p style="font-weight: 500;margin-bottom:0.2rem;">Permission may be denied</p>
                        <p>Web server user (<?= htmlspecialchars($process_user) ?>) may not own this file.</p>
                    </div>
                </div>
                <div style="margin-bottom:1rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                        <label class="form-label" style="margin:0;">Octal Value</label>
                        <span style="font-size:0.75rem;color:var(--slate-500);">Current: <span id="chmod-current"
                                style="font-family:var(--font-mono);">-</span></span>
                    </div>
                    <input type="text" id="chmod-val" value="0775" class="form-input"
                        style="font-family:var(--font-mono);">
                    <p style="font-size:0.75rem;color:var(--slate-400);margin-top:0.375rem;">
                        Quick:
                        <span style="color:var(--primary);cursor:pointer;font-weight: 500;"
                            onclick="document.getElementById('chmod-val').value='0775'">0775</span> (dir) &middot;
                        <span style="color:var(--primary);cursor:pointer;font-weight: 500;"
                            onclick="document.getElementById('chmod-val').value='0664'">0664</span> (file)
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="FM.closeModals()" class="btn btn-secondary btn-sm">Cancel</button>
                <button onclick="FM.doChmod()" class="btn btn-primary btn-sm">Save</button>
            </div>
        </div>
    </div>

    <!-- CONTEXT MENU -->
    <div id="ctx-menu" class="hidden">
        <button class="ctx-item" onclick="FM.openCtx()">
            <i data-lucide="folder-open" style="width:14px;height:14px;color:var(--primary);"></i> Open
        </button>
        <button class="ctx-item" onclick="FM.editCtx()" id="ctx-btn-edit">
            <i data-lucide="file-code" style="width:14px;height:14px;color:#10b981;"></i> Edit
        </button>
        <button class="ctx-item" onclick="FM.renameCtx()">
            <i data-lucide="edit-3" style="width:14px;height:14px;"></i> Rename
        </button>
        <div class="ctx-sep"></div>
        <button class="ctx-item" onclick="FM.extractCtx()" id="ctx-btn-extract">
            <i data-lucide="package-open" style="width:14px;height:14px;color:#f97316;"></i> Extract
        </button>
        <button class="ctx-item" onclick="FM.chmodCtx()">
            <i data-lucide="lock" style="width:14px;height:14px;"></i> Permissions
        </button>
        <button class="ctx-item" onclick="FM.bulk('copy')">
            <i data-lucide="copy" style="width:14px;height:14px;"></i> Copy
        </button>
        <button class="ctx-item" onclick="FM.bulk('move')">
            <i data-lucide="move" style="width:14px;height:14px;"></i> Move
        </button>
        <button class="ctx-item" onclick="FM.bulk('zip')">
            <i data-lucide="archive" style="width:14px;height:14px;"></i> Archive
        </button>
        <div class="ctx-sep"></div>
        <button class="ctx-item danger" onclick="FM.bulk('delete')">
            <i data-lucide="trash-2" style="width:14px;height:14px;"></i> Delete
        </button>
    </div>

    <!-- CREATE MODAL -->
    <div id="modal-create" class="modal hidden">
        <div class="modal-box" style="max-width:24rem;">
            <div class="modal-header">
                <div class="modal-title">
                    <div class="modal-title-icon"><i data-lucide="file-plus-2" style="width:16px;height:16px;"></i>
                    </div>
                    New Item
                </div>
                <button class="modal-close" onclick="FM.closeModals()"><i data-lucide="x"
                        style="width:15px;height:15px;"></i></button>
            </div>
            <div class="modal-body">
                <div class="fm-type-toggle">
                    <button onclick="FM.setCreateType('file')" id="btn-c-file" class="fm-type-btn active">
                        <i data-lucide="file"
                            style="width:14px;height:14px;vertical-align:middle;margin-right:0.25rem;"></i> File
                    </button>
                    <button onclick="FM.setCreateType('folder')" id="btn-c-folder" class="fm-type-btn">
                        <i data-lucide="folder"
                            style="width:14px;height:14px;vertical-align:middle;margin-right:0.25rem;"></i> Folder
                    </button>
                </div>
                <input id="input-create" type="text" placeholder="Enter name&hellip;" class="form-input">
            </div>
            <div class="modal-footer">
                <button onclick="FM.closeModals()" class="btn btn-secondary btn-sm">Cancel</button>
                <button onclick="FM.doCreate()" class="btn btn-primary btn-sm">Create</button>
            </div>
        </div>
    </div>

    <!-- RENAME MODAL -->
    <div id="modal-rename" class="modal hidden">
        <div class="modal-box" style="max-width:24rem;">
            <div class="modal-header">
                <div class="modal-title">
                    <div class="modal-title-icon"><i data-lucide="edit-3" style="width:16px;height:16px;"></i></div>
                    Rename
                </div>
                <button class="modal-close" onclick="FM.closeModals()"><i data-lucide="x"
                        style="width:15px;height:15px;"></i></button>
            </div>
            <div class="modal-body">
                <input id="input-rename" type="text" class="form-input" placeholder="New name&hellip;">
                <input id="rename-target" type="hidden">
            </div>
            <div class="modal-footer">
                <button onclick="FM.closeModals()" class="btn btn-secondary btn-sm">Cancel</button>
                <button onclick="FM.doRename()" class="btn btn-primary btn-sm">Save</button>
            </div>
        </div>
    </div>

    <!-- COPY/MOVE MODAL -->
    <div id="modal-copymove" class="modal hidden">
        <div class="modal-box" style="max-width:26rem;">
            <div class="modal-header">
                <div class="modal-title">
                    <div class="modal-title-icon"><i data-lucide="folder-output" style="width:16px;height:16px;"></i>
                    </div>
                    <span id="cm-title">Move Items</span>
                </div>
                <button class="modal-close" onclick="FM.closeModals()"><i data-lucide="x"
                        style="width:15px;height:15px;"></i></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Destination Path</label>
                <div style="display:flex;align-items:center;gap:0.625rem;padding:0 0.875rem;border:1px solid var(--slate-200);border-radius:var(--radius-md);background:var(--slate-50);transition:border-color 0.2s;"
                    onfocusin="this.style.borderColor='var(--primary)'"
                    onfocusout="this.style.borderColor='var(--slate-200)'">
                    <i data-lucide="folder" style="width:14px;height:14px;color:var(--slate-400);flex-shrink:0;"></i>
                    <input id="cm-dest" type="text"
                        style="flex:1;border:none;background:transparent;outline:none;padding:0.625rem 0;font-family:var(--font-mono);font-size:0.875rem;color:var(--slate-900);"
                        placeholder="/path/to/folder">
                </div>
                <input type="hidden" id="cm-action">
            </div>
            <div class="modal-footer">
                <button onclick="FM.closeModals()" class="btn btn-secondary btn-sm">Cancel</button>
                <button onclick="FM.doCopyMove()" class="btn btn-primary btn-sm">Confirm</button>
            </div>
        </div>
    </div>

    <!-- PREVIEW MODAL -->
    <div id="modal-preview" class="modal hidden" style="padding:1.5rem;">
        <div class="modal-box" style="max-width:64rem;height:87vh;display:flex;flex-direction:column;">
            <div class="modal-header">
                <div class="modal-title">
                    <div class="modal-title-icon"><i data-lucide="eye" style="width:16px;height:16px;"></i></div>
                    <span id="preview-title"
                        style="font-family:var(--font-mono);font-size:0.875rem;">filename.txt</span>
                </div>
                <button class="modal-close" onclick="FM.closeModals()"><i data-lucide="x"
                        style="width:15px;height:15px;"></i></button>
            </div>
            <div id="preview-content"
                style="flex:1;overflow:auto;background:white;display:flex;align-items:center;justify-content:center;">
            </div>
        </div>
    </div>

    <!-- DOWNLOAD FORM (hidden, used by JS bulk download) -->
    <form id="form-download" method="POST" style="display:none;">
        <input type="hidden" name="download_items" value="1">
        <input type="hidden" name="domain_id" value="<?= $domain_id ?>">
        <input type="hidden" name="path" value="<?= htmlspecialchars($current_path) ?>">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div id="download-inputs"></div>
    </form>

    <!-- TOAST -->
    <div id="toast">
        <div id="toast-icon-wrapper"
            style="display:flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;flex-shrink:0;">
        </div>
        <span style="flex:1;" id="toast-msg"></span>
        <script>
            // CONFIG
            const CONFIG = {
                domainId: <?= $domain_id ?>,
                currentPath: '<?= htmlspecialchars($current_path, ENT_QUOTES) ?>',
                isWritable: <?= $is_writable ? 'true' : 'false' ?>,
                totalItems: <?= count($items) ?>,
                processUser: '<?= htmlspecialchars($process_user) ?>',
                processUid: <?= $process_uid ?>
            };

            // ICONS
            lucide.createIcons();

            // FILE MANAGER CLASS
            class FileManager {
                constructor() {
                    this.view = localStorage.getItem('fm_view') || 'list';
                    this.selected = new Set();
                    this.allSelected = false;
                    this.init();
                }

                init() {
                    this.setView(this.view);
                    this.initDragDrop();
                    document.addEventListener('keydown', e => {
                        if (e.key === 'Escape') this.closeModals();
                        if (e.ctrlKey && e.key === 'a') {
                            e.preventDefault();
                            this.selectAll(true);
                        }
                        if (e.key === 'Delete' && this.selected.size > 0) {
                            e.preventDefault();
                            this.bulk('delete');
                        }
                    });

                    // Context Menu Listener
                    document.addEventListener('contextmenu', e => {
                        const row = e.target.closest('.file-item');
                        if (row) {
                            e.preventDefault();
                            this.openCtxMenu(e, row);
                        } else {
                            this.closeCtx();
                        }
                    });
                    document.addEventListener('click', () => this.closeCtx());
                }

                closeCtx() {
                    document.getElementById('ctx-menu').classList.add('hidden', 'opacity-0', 'scale-95');
                }

                openCtxMenu(e, row) {
                    const path = row.dataset.path;
                    // Auto-select if not selected
                    if (!this.selected.has(path)) {
                        this.clearSelection();
                        this.selected.add(path);
                        row.classList.add('selected');
                        row.querySelector('.file-check').checked = true;
                        // For grid view opacity
                        const chk = row.querySelector('.file-check');
                        if (chk) chk.classList.add('opacity-100');
                        this.updateActionBar();
                    }

                    this.ctxItem = path;
                    this.ctxType = row.dataset.type;
                    const menu = document.getElementById('ctx-menu');

                    // Show/Hide context buttons based on type
                    const isDir = this.ctxType === 'dir';
                    const ext = path.split('.').pop().toLowerCase();
                    const isZip = ext === 'zip';

                    // Toggle Edit button
                    const btnEdit = document.getElementById('ctx-btn-edit');
                    if (btnEdit) btnEdit.style.display = isDir ? 'none' : 'flex';

                    // Toggle Extract button
                    const btnExtract = document.getElementById('ctx-btn-extract');
                    if (btnExtract) btnExtract.style.display = isZip ? 'flex' : 'none';

                    // Adjust position
                    let x = e.clientX;
                    let y = e.clientY;
                    if (x + 200 > window.innerWidth) x -= 200;
                    if (y + 300 > window.innerHeight) y -= 300; // Increased height safety

                    menu.style.top = y + 'px';
                    menu.style.left = x + 'px';
                    menu.classList.remove('hidden', 'opacity-0', 'scale-95');
                }

                // Context Menu Actions
                openCtx() { this.open(this.ctxItem, this.ctxType); }

                editCtx() {
                    if (this.ctxType === 'file') {
                        location.href = `editor.php?domain_id=${CONFIG.domainId}&file=${this.ctxItem}`;
                    }
                }

                extractCtx() {
                    this.request('unzip_item', { item: this.ctxItem });
                }

                renameCtx() {
                    document.getElementById('input-rename').value = this.ctxItem.split('/').pop();
                    document.getElementById('rename-target').value = this.ctxItem;
                    document.getElementById('modal-rename').classList.remove('hidden');
                }

                doRename() {
                    const oldName = document.getElementById('rename-target').value;
                    const newName = document.getElementById('input-rename').value;
                    if (newName && oldName) this.request('rename_item', { old: oldName, new_name: newName });
                }

                chmodCtx() {
                    // Find the file element to get current permissions
                    const fileEl = document.querySelector(`.file-item[data-path="${this.ctxItem}"]`);
                    let currentPerms = '';

                    if (fileEl) {
                        // Get permissions from the list view (4th column in list layout)
                        const permEl = null; // fileEl.querySelector('.list-layout > div:nth-child(4)');
                        if (permEl) {
                            currentPerms = permEl.textContent.trim();
                        }
                    }

                    // Determine suggestion based on type
                    const suggested = this.ctxType === 'dir' ? '0775' : '0664';
                    document.getElementById('chmod-val').value = suggested;
                    document.getElementById('chmod-target').value = this.ctxItem;

                    // Update current permissions display
                    const currentPermEl = document.getElementById('chmod-current');
                    if (currentPermEl) {
                        currentPermEl.textContent = currentPerms || 'Unknown';
                    }

                    // Show warning if file might not be owned by web server
                    const warningEl = document.getElementById('chmod-warning');
                    if (warningEl) {
                        warningEl.classList.remove('hidden');
                    }

                    document.getElementById('modal-chmod').classList.remove('hidden');
                }

                doChmod() {
                    const target = document.getElementById('chmod-target').value;
                    const mode = document.getElementById('chmod-val').value;
                    if (target && mode) {
                        this.request('chmod_item', { item: target, mode: mode });
                        this.closeModals();
                    }
                }

                // VIEW & UI
                setView(mode) {
                    this.view = mode;
                    localStorage.setItem('fm_view', mode);
                    const container = document.getElementById('file-view');
                    const btnList = document.getElementById('btn-list');
                    const btnGrid = document.getElementById('btn-grid');

                    if (mode === 'grid') {
                        container.classList.add('view-grid');
                        container.classList.remove('view-list');
                        btnGrid.classList.add('active');
                        btnList.classList.remove('active');
                    } else {
                        container.classList.add('view-list');
                        container.classList.remove('view-grid');
                        btnList.classList.add('active');
                        btnGrid.classList.remove('active');
                    }
                }

                // SELECTION
                toggleSelect(el, e) {
                    // Allow checkbox clicks to work normally
                    if (e.target.tagName === 'INPUT' && e.target.type === 'checkbox') {
                        const path = el.dataset.path;
                        if (e.target.checked) {
                            this.selected.add(path);
                            el.classList.add('selected');
                        } else {
                            this.selected.delete(path);
                            el.classList.remove('selected');
                        }
                        this.syncHeaderCheckbox();
                        this.updateActionBar();
                        return;
                    }

                    // Click on row (not checkbox)
                    const path = el.dataset.path;
                    if (this.selected.has(path)) {
                        this.selected.delete(path);
                        el.classList.remove('selected');
                        const checkbox = el.querySelector('.file-check');
                        if (checkbox) {
                            checkbox.checked = false;
                            checkbox.classList.remove('opacity-100');
                        }
                    } else {
                        this.selected.add(path);
                        el.classList.add('selected');
                        const checkbox = el.querySelector('.file-check');
                        if (checkbox) {
                            checkbox.checked = true;
                            checkbox.classList.add('opacity-100');
                        }
                    }
                    this.syncHeaderCheckbox();
                    this.updateActionBar();
                }

                syncHeaderCheckbox() {
                    const headerCheckbox = document.getElementById('header-select-all');
                    if (!headerCheckbox) return;

                    const fileItems = document.querySelectorAll('.file-item');
                    if (fileItems.length === 0) {
                        headerCheckbox.checked = false;
                        headerCheckbox.indeterminate = false;
                        return;
                    }

                    if (this.selected.size === 0) {
                        headerCheckbox.checked = false;
                        headerCheckbox.indeterminate = false;
                    } else if (this.selected.size === fileItems.length) {
                        headerCheckbox.checked = true;
                        headerCheckbox.indeterminate = false;
                    } else {
                        headerCheckbox.checked = false;
                        headerCheckbox.indeterminate = true;
                    }
                }

                updateActionBar() {
                    const bar = document.getElementById('action-bar');
                    const count = document.getElementById('selection-count');
                    const btnSelectAll = document.getElementById('btn-select-all');
                    const btnUnselectAll = document.getElementById('btn-unselect-all');

                    if (this.selected.size > 0) {
                        bar.classList.add('visible');
                        count.innerText = this.selected.size + ' Selected';

                        if (this.selected.size === CONFIG.totalItems && CONFIG.totalItems > 0) {
                            btnSelectAll.classList.add('hidden');
                            btnUnselectAll.classList.remove('hidden');
                        } else {
                            btnSelectAll.classList.remove('hidden');
                            btnUnselectAll.classList.add('hidden');
                        }
                    } else {
                        bar.classList.remove('visible');
                    }
                }

                clearSelection() {
                    this.selected.clear();
                    this.allSelected = false;
                    document.querySelectorAll('.file-item.selected').forEach(el => {
                        el.classList.remove('selected');
                        const checkbox = el.querySelector('.file-check');
                        if (checkbox) checkbox.checked = false;
                    });
                    // Also uncheck the header checkbox
                    const headerCheckbox = document.getElementById('header-select-all');
                    if (headerCheckbox) headerCheckbox.checked = false;
                    this.updateActionBar();
                }

                // FIX: Select All / Unselect All functionality
                selectAll(checked) {
                    const fileItems = document.querySelectorAll('.file-item');
                    const headerCheckbox = document.querySelector('.list-header input[type="checkbox"]');

                    if (checked === true) {
                        // Select all
                        this.selected.clear();
                        fileItems.forEach(el => {
                            const path = el.dataset.path;
                            this.selected.add(path);
                            el.classList.add('selected');
                            const checkbox = el.querySelector('.file-check');
                            if (checkbox) checkbox.checked = true;
                        });
                        this.allSelected = true;
                        if (headerCheckbox) {
                            headerCheckbox.checked = true;
                            headerCheckbox.indeterminate = false;
                        }
                    } else {
                        // Unselect all (when checked is false or undefined)
                        this.selected.clear();
                        this.allSelected = false;
                        fileItems.forEach(el => {
                            el.classList.remove('selected');
                            const checkbox = el.querySelector('.file-check');
                            if (checkbox) checkbox.checked = false;
                        });
                        if (headerCheckbox) {
                            headerCheckbox.checked = false;
                            headerCheckbox.indeterminate = false;
                        }
                    }
                    this.updateActionBar();
                }

                // NAVIGATION
                open(path, type) {
                    if (type === 'dir') {
                        location.href = `?domain_id=${CONFIG.domainId}&path=${encodeURIComponent(path)}`;
                    } else {
                        const ext = path.split('.').pop().toLowerCase();
                        const editable = ['php', 'html', 'css', 'js', 'json', 'xml', 'txt', 'md', 'sql', 'htaccess', 'env', 'ini', 'conf'];

                        if (editable.includes(ext)) {
                            location.href = `editor.php?domain_id=${CONFIG.domainId}&file=${encodeURIComponent(path)}`;
                        } else {
                            // Start Preview
                            this.preview(path);
                        }
                    }
                }

                // ACTIONS
                async request(action, data = {}) {
                    const fd = new FormData();
                    fd.append('ajax', '1');
                    fd.append(action, '1');
                    fd.append('domain_id', CONFIG.domainId);
                    fd.append('path', CONFIG.currentPath);
                    fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                    for (let k in data) {
                        if (Array.isArray(data[k])) data[k].forEach(v => fd.append(`${k}[]`, v));
                        else fd.append(k, data[k]);
                    }

                    // Show loading cursor
                    document.body.style.cursor = 'wait';
                    try {
                        const res = await fetch('', { method: 'POST', body: fd });

                        // Check if response is JSON
                        const contentType = res.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            const text = await res.text();
                            console.error('Non-JSON response:', text);
                            this.toast('error', 'Server returned invalid response. Check server logs.');
                            document.body.style.cursor = 'default';
                            return;
                        }

                        const json = await res.json();
                        document.body.style.cursor = 'default';
                        if (json.status === 'success') {
                            this.toast('success', json.msg);
                            setTimeout(() => location.reload(), 500);
                        } else {
                            // Handle detailed error with solutions
                            let errorMsg = json.msg;
                            if (typeof json.msg === 'object') {
                                errorMsg = json.msg.msg || 'Operation failed';
                                if (json.msg.details) {
                                    errorMsg += '\n\n' + json.msg.details;
                                }
                                if (json.msg.solutions && json.msg.solutions.length > 0) {
                                    errorMsg += '\n\nPossible solutions:\n• ' + json.msg.solutions.join('\n• ');
                                }
                                if (json.msg.current_perms) {
                                    errorMsg += '\n\nCurrent permissions: ' + json.msg.current_perms;
                                }
                            }
                            this.toast('error', errorMsg);

                            // Also log to console for debugging
                            console.error('Operation failed:', json);
                        }
                    } catch (e) {
                        document.body.style.cursor = 'default';
                        this.toast('error', 'Server Error: ' + e.message);
                    }
                }

                bulk(action) {
                    if (this.selected.size === 0) return;
                    const paths = Array.from(this.selected);

                    if (action === 'delete') {
                        if (confirm(`Delete ${paths.length} items?`)) {
                            this.request('delete_paths', { paths: paths });
                        }
                    } else if (action === 'download') {
                        const form = document.getElementById('form-download');
                        const inputs = document.getElementById('download-inputs');
                        inputs.innerHTML = '';
                        paths.forEach(p => inputs.innerHTML += `<input type="hidden" name="paths[]" value="${p}">`);
                        form.submit();
                    } else if (action === 'zip') {
                        this.request('zip_paths', { paths: paths });
                    } else if (action === 'copy' || action === 'move') {
                        this.openCopyMove(action);
                    }
                }

                openCopyMove(action) {
                    if (this.selected.size === 0) return;
                    const paths = Array.from(this.selected);
                    document.getElementById('cm-title').innerText = (action === 'copy' ? 'Copy' : 'Move') + ' ' + paths.length + ' Items';
                    document.getElementById('cm-action').value = action;
                    document.getElementById('cm-dest').value = CONFIG.currentPath; // Pre-fill current
                    document.getElementById('modal-copymove').classList.remove('hidden');
                }

                doCopyMove() {
                    const action = document.getElementById('cm-action').value;
                    const dest = document.getElementById('cm-dest').value;
                    const paths = Array.from(this.selected);
                    this.request('copy_move_items', { action: action, destination: dest, paths: paths });
                }

                // PREVIEW
                async preview(path) {
                    const ext = path.split('.').pop().toLowerCase();
                    const modal = document.getElementById('modal-preview');
                    const container = document.getElementById('preview-content');

                    modal.classList.remove('hidden');
                    container.innerHTML = '<div style="animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; color: var(--slate-700);">Loading...</div>';

                    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                        const fd = new FormData();
                        fd.append('download_items', '1');
                        fd.append('paths[]', path);
                        fd.append('domain_id', CONFIG.domainId);
                        fd.append('path', CONFIG.currentPath);
                        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                        try {
                            const res = await fetch('', { method: 'POST', body: fd });
                            const blob = await res.blob();
                            const url = URL.createObjectURL(blob);
                            container.innerHTML = `<img src="${url}" style="max-height: 100%; max-width: 100%; border-radius: 0.25rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);">`;
                        } catch (e) { container.innerHTML = 'Error loading image'; }
                    } else {
                        const fd = new FormData();
                        fd.append('preview_item', '1');
                        fd.append('item', path);
                        fd.append('domain_id', CONFIG.domainId);
                        fd.append('path', CONFIG.currentPath);
                        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                        if (res.status === 'success') {
                            container.innerHTML = `<pre style="font-size: 0.8125rem; font-family: 'JetBrains Mono', monospace; line-height: 1.6; color: var(--slate-700); padding: 1.5rem; width: 100%; height: 100%; overflow: auto; text-align: left; margin: 0; tab-size: 4;">${res.content}</pre>`;
                        } else {
                            container.innerHTML = `<div style="display: flex; flex-direction: column; align-items: center; gap: 1rem; color: #ef4444;"><i data-lucide="alert-circle" style="width: 48px; height: 48px;"></i><span style="font-weight: 500;">${res.msg}</span></div>`;
                            lucide.createIcons();
                        }
                    }
                }

                // UTILS
                toast(type, msg) {
                    const el = document.getElementById('toast');
                    const iconWrapper = document.getElementById('toast-icon-wrapper');
                    const span = document.getElementById('toast-msg');

                    span.innerText = msg;

                    if (type === 'success') {
                        iconWrapper.style.backgroundColor = 'rgba(16, 185, 129, 0.2)';
                        iconWrapper.innerHTML = '<i data-lucide="check" style="width: 14px; height: 14px; color: #10b981;"></i>';
                    } else {
                        iconWrapper.style.backgroundColor = 'rgba(239, 68, 68, 0.2)';
                        iconWrapper.innerHTML = '<i data-lucide="alert-circle" style="width: 14px; height: 14px; color: #ef4444;"></i>';
                    }
                    lucide.createIcons({ nameAttr: 'data-lucide', attrs: { class: "lucide" } });

                    el.classList.add('visible');
                    clearTimeout(this._toastTimer);
                    this._toastTimer = setTimeout(() => el.classList.remove('visible'), 3500);
                }

                closeModals() {
                    document.querySelectorAll('.modal').forEach(m => m.classList.add('hidden'));
                }

                filter() {
                    const q = document.getElementById('file-search').value.toLowerCase();
                    document.querySelectorAll('.file-item').forEach(el => {
                        el.classList.toggle('hidden', !el.dataset.name.includes(q));
                    });
                }

                // Handlers for HTML Buttons
                openUpload() {
                    const modal = document.getElementById('modal-upload');
                    if (modal) {
                        modal.classList.remove('hidden');
                    }
                }

                openCreate() {
                    document.getElementById('modal-create').classList.remove('hidden');
                    this.setCreateType('file');
                }

                setCreateType(t) {
                    this.createType = t;
                    const btnFile = document.getElementById('btn-c-file');
                    const btnFolder = document.getElementById('btn-c-folder');
                    btnFile.classList.toggle('active', t === 'file');
                    btnFolder.classList.toggle('active', t === 'folder');
                }

                doCreate() {
                    const name = document.getElementById('input-create').value;
                    if (!name) return;
                    this.request('create_item', { name: name, type: this.createType || 'file' });
                }

                initDragDrop() {
                    const zone = document.getElementById('drop-zone-global');
                    const overlay = document.getElementById('drag-overlay');
                    let timer;

                    window.addEventListener('dragover', e => {
                        e.preventDefault();
                        overlay.classList.remove('hidden');
                        clearTimeout(timer);
                    });

                    window.addEventListener('dragleave', e => {
                        timer = setTimeout(() => overlay.classList.add('hidden'), 100);
                    });

                    window.addEventListener('drop', e => {
                        e.preventDefault();
                        overlay.classList.add('hidden');
                        this.handleDrop(e.dataTransfer.files);
                    });
                }

                async handleDrop(files) {
                    if (files.length === 0) return;

                    const fd = new FormData();
                    fd.append('upload_files', '1');
                    fd.append('ajax', '1');
                    fd.append('domain_id', CONFIG.domainId);
                    fd.append('path', CONFIG.currentPath);
                    fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

                    for (let i = 0; i < files.length; i++) {
                        fd.append('files[]', files[i]);
                    }

                    this.toast('success', 'Uploading...');
                    try {
                        const res = await fetch('', { method: 'POST', body: fd });

                        // Check if response is JSON
                        const contentType = res.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            const text = await res.text();
                            console.error('Non-JSON response:', text);
                            this.toast('error', 'Server returned invalid response. Check server logs.');
                            return;
                        }

                        const json = await res.json();
                        if (json.status === 'success') {
                            this.toast('success', json.msg || 'Uploaded successfully');
                            setTimeout(() => location.reload(), 500);
                        } else {
                            console.error('Upload Error:', json);
                            this.toast('error', json.msg || 'Upload failed');
                        }
                    } catch (e) {
                        console.error('Fetch Error:', e);
                        this.toast('error', 'Network or Server Error: ' + e.message);
                    }
                }

                doUploadInput(input) {
                    if (input.files.length > 0) {
                        this.handleDrop(input.files);
                        this.closeModals();
                    }
                }
            }

            const FM = new FileManager();
        </script>
</body>

</html>