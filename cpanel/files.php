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

    // Add helpful tip for permission errors
    if ($status === 'error' && (stripos($msg, 'permission') !== false || stripos($msg, 'writable') !== false || stripos($msg, 'denied') !== false)) {
        $msg .= "<br><br><strong>💡 Tip:</strong> Go to <b>Tools > Troubleshoot</b> and run <b>Fix Permissions</b>.";
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

    // Permission check for other operations (skip for chmod which is handled above)
    if (!$is_writable && !isset($_POST['chmod_item'])) {
        // Diagnostic Info
        $process_user = (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
        $path_owner = file_exists($full_path) ? fileowner($full_path) : 'N/A';
        $perms = file_exists($full_path) ? substr(sprintf('%o', fileperms($full_path)), -4) : 'N/A';

        $debug = "Path: $full_path | Process: $process_user | Owner: $path_owner | Perms: $perms";

        $msg = $setup_error ? "System Error: $setup_error" : "Permission Denied. $debug";

        error_log("SHM-FM Error: $msg");

        if ($is_ajax) {
            echo json_encode(['status' => 'error', 'msg' => $msg]);
            exit;
        }
    }

    // 2. CREATE
    if (isset($_POST['create_item'])) {
        $name = preg_replace('/[^\w\.\-]/', '', $_POST['name']);
        if (empty($name)) {
            fm_return('error', 'Invalid name');
        }
        $target = $full_path . '/' . $name;
        if (file_exists($target))
            fm_return('error', 'Item already exists');

        if ($_POST['type'] == 'folder') {
            $old_umask = umask(0);
            if (mkdir($target, 0775, true))
                fm_return('success', 'Folder created');
            umask($old_umask);
        } else {
            if (file_put_contents($target, '') !== false) {
                $old_umask = umask(0);
                @chmod($target, 0644);
                umask($old_umask);
                fm_return('success', 'File created');
            }
        }
        fm_return('error', 'Creation failed');
    }

    // 3. DELETE
    if (isset($_POST['delete_paths'])) {
        $count = 0;
        foreach ($_POST['paths'] as $p) {
            $abs = shm_build_path($base_path, $p);
            // Critical Safeguard: Prevent Deletion of Root or parent directories
            if (!$abs || $abs === $base_path || strpos($abs, $base_path) !== 0)
                continue;

            if ($abs && shm_rrmdir($abs))
                $count++;
        }
        fm_return('success', "$count items deleted");
    }

    // 4. ZIP
    if (isset($_POST['zip_paths'])) {
        if (!class_exists('ZipArchive')) {
            fm_return('error', 'ZipArchive extension not available');
        }

        $zip = new ZipArchive();
        $zip_name = $full_path . '/' . (count($_POST['paths']) > 1 ? 'archive_' . date('Ymd_His') . '.zip' : basename($_POST['paths'][0]) . '.zip');
        if ($zip->open($zip_name, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($_POST['paths'] as $p) {
                $abs = shm_build_path($base_path, $p);
                if (is_file($abs))
                    $zip->addFile($abs, basename($abs));
                if (is_dir($abs)) {
                    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs), RecursiveIteratorIterator::LEAVES_ONLY);
                    foreach ($files as $name => $file) {
                        if (!$file->isDir()) {
                            $filePath = $file->getRealPath();
                            $relativePath = substr($filePath, strlen($abs) + 1);
                            $zip->addFile($filePath, basename($abs) . '/' . $relativePath);
                        }
                    }
                }
            }
            $zip->close();
            @chmod($zip_name, 0644);
            fm_return('success', 'Archive created');
        }
        fm_return('error', 'Zip creation failed');
    }

    // 5. RENAME
    if (isset($_POST['rename_item'])) {
        $old = shm_build_path($base_path, $_POST['old']);
        $new = shm_build_path($base_path, $_POST['new_name']);
        if ($old && $new && rename($old, $new))
            fm_return('success', 'Renamed successfully');
        fm_return('error', 'Rename failed');
    }

    // 6. COPY/MOVE
    if (isset($_POST['copy_move_items'])) {
        $action = $_POST['action'];
        $dest_folder = shm_build_path($base_path, $_POST['destination']);
        $count = 0;
        if ($dest_folder) {
            foreach ($_POST['paths'] as $p) {
                $src = shm_build_path($base_path, $p);
                $name = basename($src);
                $dest = $dest_folder . '/' . $name;
                if ($src && $action == 'move' && rename($src, $dest))
                    $count++;
                if ($src && $action == 'copy') {
                    shm_rcopy($src, $dest);
                    $count++;
                }
            }
            fm_return('success', "$count items processed");
        }
        fm_return('error', 'Invalid destination');
    }

    // 7. UNZIP
    if (isset($_POST['unzip_item'])) {
        if (!class_exists('ZipArchive')) {
            fm_return('error', 'ZipArchive extension not available');
        }

        $zip_file = shm_build_path($base_path, $_POST['item']);
        $zip = new ZipArchive;
        if ($zip->open($zip_file) === TRUE) {
            $zip->extractTo(dirname($zip_file));
            $zip->close();
            fm_return('success', 'Extracted successfully');
        }
        fm_return('error', 'Extraction failed');
    }

    // 8. DOWNLOAD
    if (isset($_POST['download_items'])) {
        $paths = $_POST['paths'];

        if (count($paths) === 1 && is_file(shm_build_path($base_path, $paths[0]))) {
            $file = shm_build_path($base_path, $paths[0]);
            if (file_exists($file)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($file) . '"');
                header('Content-Length: ' . filesize($file));
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');
                readfile($file);
                exit;
            }
        } else {
            // Zip Download
            if (!class_exists('ZipArchive')) {
                die('ZipArchive extension not available');
            }

            $zip_name = 'download_' . date('Ymd_His') . '.zip';
            $tmp_zip = sys_get_temp_dir() . '/' . $zip_name;
            $zip = new ZipArchive();
            if ($zip->open($tmp_zip, ZipArchive::CREATE)) {
                foreach ($paths as $p) {
                    $abs = shm_build_path($base_path, $p);
                    if (is_dir($abs) || is_file($abs)) {
                        if (is_file($abs))
                            $zip->addFile($abs, basename($abs));
                        if (is_dir($abs)) {
                            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs), RecursiveIteratorIterator::LEAVES_ONLY);
                            foreach ($files as $name => $file) {
                                if (!$file->isDir()) {
                                    $filePath = $file->getRealPath();
                                    $relativePath = substr($filePath, strlen($abs) + 1);
                                    $zip->addFile($filePath, basename($abs) . '/' . $relativePath);
                                }
                            }
                        }
                    }
                }
                $zip->close();
                if (file_exists($tmp_zip)) {
                    header('Content-Type: application/zip');
                    header('Content-disposition: attachment; filename=' . $zip_name);
                    header('Content-Length: ' . filesize($tmp_zip));
                    readfile($tmp_zip);
                    unlink($tmp_zip);
                    exit;
                }
            }
        }
        fm_return('error', 'Download failed');
    }

    // 9. PREVIEW
    if (isset($_POST['preview_item'])) {
        $file = shm_build_path($base_path, $_POST['item']);
        if (is_file($file)) {
            // Security: Check file size before previewing
            $filesize = filesize($file);
            if ($filesize > 10485760) { // 10MB limit for preview
                echo json_encode(['status' => 'error', 'msg' => 'File too large for preview (max 10MB)']);
                exit;
            }

            $content = file_get_contents($file, false, NULL, 0, 10240);
            echo json_encode(['status' => 'success', 'type' => 'code', 'content' => htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')]);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'File not found']);
        }
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
    <title>File Manager | Vivzon Cloud</title>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300 ;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        /* Utility classes and file-manager specific styles are now in modern-design.css */
    </style>
</head>

<body
    style="display: flex; height: 100vh; overflow: hidden; font-size: 0.875rem; background: var(--slate-50); color: var(--slate-900);">

    <?php
    $current_page = 'files.php';
    $collapse_sidebar = true;
    include 'layout/sidebar.php';
    ?>

    <main
        style="flex: 1; display: flex; flex-direction: column; height: 100vh; position: relative; background: var(--slate-50); overflow: hidden;">
        <!-- TOP NAVIGATION & ACTION BAR -->
        <header class="glass-card"
            style="height: 4rem; flex-shrink: 0; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 1.5rem; z-index: 20; border-radius: 0;">
            <div style="display: flex; align-items: center; gap: 1.5rem;">
                <!-- Toggle Sidebar for Mobile (optional, but good to have space for) -->

                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div
                        style="padding: 0.5rem; background: linear-gradient(to bottom right, var(--primary), #1d4ed8); border-radius: var(--radius-md); box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.2), 0 4px 6px -4px rgba(37, 99, 235, 0.1);">
                        <i data-lucide="folder-kanban" style="width: 20px; height: 20px; color: white;"></i>
                    </div>
                    <h1
                        style="font-weight: 700; font-size: 1.125rem; color: var(--slate-900); letter-spacing: -0.025em;">
                        File Manager</h1>
                </div>

                <div style="height: 1.5rem; width: 1px; background: var(--border-color);"></div>

                <!-- Breadcrumbs -->
                <nav style="display: flex; align-items: center; font-size: 0.875rem; font-weight: 500;">
                    <a href="?domain_id=<?= $domain_id ?>&path=/"
                        style="display: flex; align-items: center; gap: 0.25rem; color: var(--slate-700); text-decoration: none; transition: color 0.2s;"
                        onmouseover="this.style.color='var(--primary)'"
                        onmouseout="this.style.color='var(--slate-700)'">
                        <i data-lucide="hard-drive" style="width: 16px; height: 16px;"></i>
                    </a>
                    <?php
                    $crumbs = array_filter(explode('/', $current_path));
                    $acc = '';
                    foreach ($crumbs as $c):
                        $acc .= '/' . $c;
                        ?>
                        <i data-lucide="chevron-right"
                            style="width: 16px; height: 16px; color: var(--slate-700); margin: 0 0.25rem;"></i>
                        <a href="?domain_id=<?= $domain_id ?>&path=<?= $acc ?>"
                            style="color: var(--slate-700); text-decoration: none; padding: 0.25rem 0.5rem; border-radius: var(--radius-md); transition: background 0.2s, color 0.2s;"
                            onmouseover="this.style.backgroundColor='var(--slate-50)'; this.style.color='var(--slate-900)'"
                            onmouseout="this.style.backgroundColor='transparent'; this.style.color='var(--slate-700)'"><?= $c ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div style="display: flex; align-items: center; gap: 1rem;">
                <!-- Search -->
                <div style="position: relative;">
                    <i data-lucide="search"
                        style="width: 16px; height: 16px; position: absolute; left: 0.75rem; top: 0.625rem; color: var(--slate-700);"></i>
                    <input id="file-search" onkeyup="FM.filter()" placeholder="Search current folder..."
                        class="form-input" style="padding-left: 2.5rem; width: 16rem; transition: width 0.3s;"
                        onfocus="this.style.width='20rem'" onblur="this.style.width='16rem'">
                </div>

                <!-- View Toggles -->
                <div
                    style="display: flex; padding: 0.25rem; background: var(--slate-50); border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                    <button onclick="FM.setView('list')" id="btn-list"
                        style="padding: 0.375rem; border-radius: var(--radius-sm); border: none; background: #f1f5f9; color: var(--primary); cursor: pointer; transition: color 0.2s;"><i
                            data-lucide="list" style="width: 16px; height: 16px;"></i></button>
                    <button onclick="FM.setView('grid')" id="btn-grid"
                        style="padding: 0.375rem; border-radius: var(--radius-sm); border: none; background: transparent; color: var(--slate-700); cursor: pointer; transition: color 0.2s;"><i
                            data-lucide="layout-grid" style="width: 16px; height: 16px;"></i></button>
                </div>

                <div style="height: 1.5rem; width: 1px; background: var(--border-color);"></div>

                <button onclick="FM.openUpload()" class="btn btn-primary"
                    style="display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="upload-cloud" style="width: 16px; height: 16px;"></i> Upload
                </button>
            </div>
        </header>

        <div style="display: flex; flex: 1; overflow: hidden;">
            <!-- SIDEBAR (File System Nav) -->
            <aside class="glass-card"
                style="width: 16rem; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; display: none; border-radius: 0;">
                <div style="padding: 1rem;">
                    <button onclick="FM.openCreate()"
                        style="width: 100%; padding: 0.75rem; border-radius: var(--radius-md); border: 1px dashed var(--slate-300); background: transparent; color: var(--slate-700); font-size: 0.875rem; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 0.5rem; cursor: pointer; transition: all 0.2s;"
                        onmouseover="this.style.borderColor='var(--primary)'; this.style.backgroundColor='var(--slate-50)'; this.style.color='var(--primary)'"
                        onmouseout="this.style.borderColor='var(--slate-300)'; this.style.backgroundColor='transparent'; this.style.color='var(--slate-700)'">
                        <i data-lucide="plus" style="width: 16px; height: 16px;"></i> New Item
                    </button>
                </div>
                <div
                    style="flex: 1; overflow-y: auto; padding: 0 0.5rem; display: flex; flex-direction: column; gap: 0.25rem;">
                    <div
                        style="padding: 0.5rem 0.75rem; font-size: 0.625rem; font-weight: 700; color: var(--slate-700); text-transform: uppercase; letter-spacing: 0.05em;">
                        Locations</div>
                    <a href="?domain_id=<?= $domain_id ?>&path=/"
                        style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.75rem; border-radius: var(--radius-md); background: rgba(37, 99, 235, 0.1); color: var(--primary); font-weight: 500; font-size: 0.875rem; text-decoration: none;">
                        <i data-lucide="home" style="width: 16px; height: 16px;"></i> Home Root
                    </a>

                    <div
                        style="margin-top: 1.5rem; padding: 0.5rem 0.75rem; font-size: 0.625rem; font-weight: 700; color: var(--slate-700); text-transform: uppercase; letter-spacing: 0.05em;">
                        Domains</div>
                    <?php
                    $doms = $pdo->prepare("SELECT id, domain FROM domains WHERE client_id = ?");
                    $doms->execute([$user_id]);
                    while ($d = $doms->fetch()):
                        $isActive = $d['id'] == $domain_id;
                        $bg = $isActive ? 'var(--slate-100)' : 'transparent';
                        $color = $isActive ? 'var(--slate-900)' : 'var(--slate-700)';
                        ?>
                        <a href="?domain_id=<?= $d['id'] ?>"
                            style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.75rem; border-radius: var(--radius-md); background: <?= $bg ?>; color: <?= $color ?>; transition: background 0.2s, color 0.2s; font-size: 0.875rem; text-decoration: none;"
                            onmouseover="this.style.backgroundColor='var(--slate-50)'; this.style.color='var(--slate-900)'"
                            onmouseout="this.style.backgroundColor='<?= $bg ?>'; this.style.color='<?= $color ?>'">
                            <i data-lucide="globe" style="width: 16px; height: 16px;"></i> <?= $d['domain'] ?>
                        </a>
                    <?php endwhile; ?>
                </div>

                <!-- Storage Status -->
                <div style="padding: 1rem; border-top: 1px solid var(--border-color);">
                    <div
                        style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 0.5rem;">
                        <span style="color: var(--slate-700);">Storage</span>
                        <span style="font-weight: 700; color: var(--slate-900);"><?= $domain['disk_usage'] ?? '0' ?>
                            MB</span>
                    </div>
                    <div
                        style="height: 0.375rem; background: var(--slate-50); border-radius: 9999px; overflow: hidden;">
                        <div style="height: 100%; background: var(--primary); width: 75%;"></div>
                        <!-- Placeholder for real % -->
                    </div>
                </div>
            </aside>



            <!-- MAIN FILE AREA -->
            <main style="flex: 1; position: relative; background: white;" id="drop-zone-global">

                <!-- ACTION BAR (Contextual) -->
                <div id="action-bar" class="hidden"
                    style="position: absolute; width: 100%; height: 3rem; display: flex; align-items: center; justify-content: space-between; padding: 0 1.5rem; transition: all 0.3s; transform: translateY(-100%); z-index: 10; background: #354264d1; color: white;">
                    <div style="display: flex; align-items: center; gap: 1rem; font-size: 0.875rem; font-weight: 500;">
                        <span style="color: #93c5fd; font-weight: 700;" id="selection-count">0 Selected</span>
                        <div style="height: 1rem; width: 1px; background: rgba(255, 255, 255, 0.2);"></div>
                        <!-- FIX: Select All / Unselect All buttons -->
                        <button onclick="FM.selectAll(true)" id="btn-select-all"
                            style="display: flex; align-items: center; gap: 0.5rem; transition: color 0.2s; color: rgba(255, 255, 255, 0.8); background: transparent; border: none; cursor: pointer;"
                            onmouseover="this.style.color='white'"
                            onmouseout="this.style.color='rgba(255, 255, 255, 0.8)'" title="Select All (Ctrl+A)"><i
                                data-lucide="check-square" style="width: 16px; height: 16px;"></i> Select
                            All</button>
                        <button onclick="FM.selectAll(false)" id="btn-unselect-all"
                            style="display: none; align-items: center; gap: 0.5rem; transition: color 0.2s; color: rgba(255, 255, 255, 0.8); background: transparent; border: none; cursor: pointer;"
                            onmouseover="this.style.color='white'"
                            onmouseout="this.style.color='rgba(255, 255, 255, 0.8)'" title="Unselect All"><i
                                data-lucide="square" style="width: 16px; height: 16px;"></i> Unselect All</button>
                        <button onclick="FM.bulk('download')"
                            style="display: flex; align-items: center; gap: 0.5rem; transition: color 0.2s; color: rgba(255, 255, 255, 0.8); background: transparent; border: none; cursor: pointer;"
                            onmouseover="this.style.color='white'"
                            onmouseout="this.style.color='rgba(255, 255, 255, 0.8)'"><i data-lucide="download"
                                style="width: 16px; height: 16px;"></i> Download</button>
                        <button onclick="FM.bulk('zip')"
                            style="display: flex; align-items: center; gap: 0.5rem; transition: color 0.2s; color: rgba(255, 255, 255, 0.8); background: transparent; border: none; cursor: pointer;"
                            onmouseover="this.style.color='white'"
                            onmouseout="this.style.color='rgba(255, 255, 255, 0.8)'"><i data-lucide="archive"
                                style="width: 16px; height: 16px;"></i> Archive</button>
                        <button onclick="FM.bulk('copy')"
                            style="display: flex; align-items: center; gap: 0.5rem; transition: color 0.2s; color: rgba(255, 255, 255, 0.8); background: transparent; border: none; cursor: pointer;"
                            onmouseover="this.style.color='white'"
                            onmouseout="this.style.color='rgba(255, 255, 255, 0.8)'"><i data-lucide="copy"
                                style="width: 16px; height: 16px;"></i> Copy</button>
                        <button onclick="FM.bulk('move')"
                            style="display: flex; align-items: center; gap: 0.5rem; transition: color 0.2s; color: rgba(255, 255, 255, 0.8); background: transparent; border: none; cursor: pointer;"
                            onmouseover="this.style.color='white'"
                            onmouseout="this.style.color='rgba(255, 255, 255, 0.8)'"><i data-lucide="move"
                                style="width: 16px; height: 16px;"></i> Move</button>
                        <div style="height: 1rem; width: 1px; background: rgba(255, 255, 255, 0.2);"></div>
                        <button onclick="FM.bulk('delete')"
                            style="display: flex; align-items: center; gap: 0.5rem; transition: color 0.2s; color: #f87171; background: transparent; border: none; cursor: pointer;"
                            onmouseover="this.style.color='#fca5a5'" onmouseout="this.style.color='#f87171'"
                            title="Delete (Del key)"><i data-lucide="trash-2" style="width: 16px; height: 16px;"></i>
                            Delete</button>
                    </div>
                    <button onclick="FM.clearSelection()"
                        style="color: rgba(255, 255, 255, 0.8); transition: color 0.2s; background: transparent; border: none; cursor: pointer;"
                        onmouseover="this.style.color='white'" onmouseout="this.style.color='rgba(255, 255, 255, 0.8)'"
                        title="Clear Selection"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
                </div>

                <div id="file-view" class="view-list custom-scrollbar"
                    style="height: 100%; overflow-y: auto; padding: 1.5rem;">

                    <!-- LIST HEADER -->
                    <div class="list-header hidden"
                        style="display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 1rem; padding: 0.5rem 1rem; border-bottom: 1px solid var(--border-color); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--slate-700); letter-spacing: 0.05em; margin-bottom: 0.5rem; position: sticky; top: 0; background: white; z-index: 10;">
                        <div
                            style="grid-column: span 6 / span 6; padding-left: 2rem; display: flex; align-items: center; gap: 0.75rem;">
                            <div style="width: 1.25rem; display: flex; justify-content: center;">
                                <input type="checkbox" id="header-select-all" onchange="FM.selectAll(this.checked)"
                                    style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;"
                                    title="Select All">
                            </div>
                            Name
                        </div>
                        <div style="grid-column: span 2 / span 2;">Size</div>
                        <div style="grid-column: span 2 / span 2;">Type</div>
                        <div style="grid-column: span 2 / span 2; text-align: right;">Modified</div>
                    </div>

                    <style>
                        #file-view.view-list .list-header {
                            display: grid !important;
                        }
                    </style>

                    <!-- PARENT DIR -->
                    <?php if ($current_path != '/'): ?>
                        <div onclick="location.href='?domain_id=<?= $domain_id ?>&path=<?= dirname($current_path) ?>'"
                            style="display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 1rem; padding: 0.75rem 1rem; border-radius: var(--radius-xl); cursor: pointer; align-items: center; color: var(--slate-700); transition: background 0.2s, color 0.2s; margin-bottom: 0.25rem;"
                            onmouseover="this.style.backgroundColor='var(--slate-50)'; this.style.color='var(--slate-900)'"
                            onmouseout="this.style.backgroundColor='transparent'; this.style.color='var(--slate-700)'">
                            <div style="grid-column: span 6 / span 6; display: flex; align-items: center; gap: 1rem;">
                                <i data-lucide="corner-left-up"
                                    style="width: 20px; height: 20px; transition: color 0.2s;"></i>
                                <span style="font-weight: 700;">..</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ITEMS LOOP -->
                    <?php foreach ($items as $i):
                        $icon = $i['is_dir'] ? 'folder' : 'file';
                        $color = $i['is_dir'] ? 'text-amber-400' : 'text-slate-700';
                        $type = $i['is_dir'] ? 'Directory' : pathinfo($i['name'], PATHINFO_EXTENSION);

                        // Icon logic
                        if (!$i['is_dir']) {
                            $ext = strtolower($type);
                            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                                $icon = 'image';
                                $color = 'text-purple-400';
                            }
                            if (in_array($ext, ['mp4', 'webm', 'mov'])) {
                                $icon = 'film';
                                $color = 'text-red-400';
                            }
                            if (in_array($ext, ['mp3', 'wav'])) {
                                $icon = 'music';
                                $color = 'text-pink-400';
                            }
                            if (in_array($ext, ['zip', 'tar', 'gz', 'rar'])) {
                                $icon = 'archive';
                                $color = 'text-orange-400';
                            }
                            if (in_array($ext, ['php', 'js', 'css', 'html', 'json', 'sql'])) {
                                $icon = 'code-2';
                                $color = 'text-blue-400';
                            }
                        }
                        ?>
                        <div class="file-item" style="user-select: none; transition: all 0.2s; cursor: pointer;"
                            data-name="<?= strtolower(htmlspecialchars($i['name'])) ?>"
                            data-path="<?= htmlspecialchars($i['rel']) ?>" data-type="<?= $i['is_dir'] ? 'dir' : 'file' ?>"
                            onclick="FM.toggleSelect(this, event)"
                            ondblclick="FM.open('<?= htmlspecialchars($i['rel']) ?>', '<?= $i['is_dir'] ? 'dir' : 'file' ?>')"
                            onmouseover="if(!this.classList.contains('selected')) { this.style.backgroundColor='var(--slate-50)'; }"
                            onmouseout="if(!this.classList.contains('selected')) { this.style.backgroundColor='transparent'; }">

                            <!-- Inner Content (CSS handles List/Grid layout) -->
                            <div
                                style="padding: 0.75rem 1rem; border-radius: var(--radius-xl); border: 1px solid transparent; transition: background 0.2s, border-color 0.2s;">
                                <!-- List Layout -->
                                <div class="list-layout"
                                    style="display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 1rem; align-items: center;">
                                    <div
                                        style="grid-column: span 6 / span 6; display: flex; align-items: center; gap: 1rem; overflow: hidden;">
                                        <div style="width: 1.25rem; display: flex; justify-content: center;"
                                            onclick="event.stopPropagation();">
                                            <input type="checkbox" class="file-check"
                                                style="accent-color: var(--primary); width: 16px; height: 16px; opacity: 0; transition: opacity 0.2s; cursor: pointer;"
                                                onclick="event.stopPropagation();">
                                        </div>
                                        <i data-lucide="<?= $icon ?>" class="<?= $color ?>"
                                            style="width: 20px; height: 20px; flex-shrink: 0;"></i>
                                        <span
                                            style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 500; color: var(--slate-700); transition: color 0.2s;"
                                            onmouseover="this.style.color='var(--slate-900)'"
                                            onmouseout="this.style.color='var(--slate-700)'"><?= htmlspecialchars($i['name']) ?></span>
                                    </div>
                                    <div
                                        style="grid-column: span 2 / span 2; font-size: 0.875rem; color: var(--slate-700); font-family: monospace;">
                                        <?= htmlspecialchars($i['size']) ?>
                                    </div>
                                    <div
                                        style="grid-column: span 2 / span 2; font-size: 0.875rem; color: var(--slate-700); text-transform: uppercase;">
                                        <?= htmlspecialchars($type) ?>
                                    </div>
                                    <div
                                        style="grid-column: span 2 / span 2; text-align: right; font-size: 0.875rem; color: var(--slate-700); font-family: monospace;">
                                        <?= htmlspecialchars($i['date']) ?>
                                    </div>
                                </div>

                                <!-- Grid Layout -->
                                <div class="grid-layout"
                                    style="display: none; flex-direction: column; align-items: center; text-align: center; gap: 0.75rem; padding: 1rem 0; position: relative;">
                                    <div style="position: absolute; top: 0.5rem; left: 0.5rem; opacity: 0; transition: opacity 0.2s;"
                                        class="grid-checkbox-wrapper" onclick="event.stopPropagation();">
                                        <input type="checkbox" class="file-check"
                                            style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;"
                                            onclick="event.stopPropagation();">
                                    </div>
                                    <div style="padding: 1rem; border-radius: 1rem; background: rgba(248, 250, 252, 0.5); transition: background 0.2s;"
                                        onmouseover="this.style.backgroundColor='var(--slate-50)'"
                                        onmouseout="this.style.backgroundColor='rgba(248, 250, 252, 0.5)'">
                                        <i data-lucide="<?= $icon ?>" class="<?= $color ?>"
                                            style="width: 40px; height: 40px;"></i>
                                    </div>
                                    <div style="width: 100%;">
                                        <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 500; font-size: 0.875rem; color: var(--slate-700); transition: color 0.2s;"
                                            onmouseover="this.style.color='var(--slate-900)'"
                                            onmouseout="this.style.color='var(--slate-700)'">
                                            <?= htmlspecialchars($i['name']) ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--slate-700); margin-top: 0.25rem;">
                                            <?= htmlspecialchars($i['size']) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($items)): ?>
                        <div
                            style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 16rem; color: var(--slate-700);">
                            <i data-lucide="folder-open"
                                style="width: 48px; height: 48px; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>This folder is empty</p>
                        </div>
                    <?php endif; ?>

                    <style>
                        .file-item:hover .file-check,
                        .file-item:hover .grid-checkbox-wrapper {
                            opacity: 1 !important;
                        }
                    </style>

                </div>

                <div id="drag-overlay" class="hidden"
                    style="position: absolute; inset: 0; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(4px); z-index: 50; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--slate-900); border: 2px dashed rgba(37, 99, 235, 0.4); margin: 1rem; border-radius: 1.5rem; pointer-events: none;">
                    <i data-lucide="cloud-upload"
                        style="width: 80px; height: 80px; margin-bottom: 1.5rem; animation: bounce 1s infinite;"></i>
                    <h3 style="font-size: 1.875rem; font-weight: 700;">Drop files to upload</h3>
                    <p style="color: var(--primary); margin-top: 0.5rem;">to <?= htmlspecialchars($current_path) ?></p>
                </div>
            </main>
        </div>
    </main>

    <!-- UPLOAD MODAL -->
    <div id="modal-upload" class="modal hidden"
        style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px);">
        <div class="glass-card"
            style="padding: 2rem; border-radius: 1rem; width: 100%; max-width: 32rem; border: 1px solid var(--border-color); box-shadow: var(--shadow-2xl);">
            <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--slate-900); margin-bottom: 1.5rem;">Upload
                Files</h3>
            <div style="border: 2px dashed var(--slate-600); border-radius: var(--radius-xl); padding: 2rem; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; cursor: pointer; transition: all 0.2s; margin-bottom: 1.5rem;"
                onclick="document.getElementById('inp-upload-files').click()"
                ondrop="FM.handleDrop(event.dataTransfer.files); FM.closeModals();" ondragover="event.preventDefault()"
                onmouseover="this.style.borderColor='var(--primary)'; this.style.backgroundColor='rgba(248, 250, 252, 0.5)'"
                onmouseout="this.style.borderColor='var(--slate-600)'; this.style.backgroundColor='transparent'">
                <i data-lucide="cloud-upload"
                    style="width: 48px; height: 48px; color: var(--slate-700); margin-bottom: 0.75rem;"></i>
                <p style="color: var(--slate-700); font-weight: 500;">Click to browse or drag files here</p>
                <input type="file" id="inp-upload-files" multiple style="display: none;"
                    onchange="FM.doUploadInput(this)">
            </div>
            <div style="display: flex; justify-content: flex-end;">
                <button onclick="FM.closeModals()" class="btn btn-outline">Close</button>
            </div>
        </div>
    </div>

    <!-- CHMOD MODAL -->
    <div id="modal-chmod" class="modal hidden"
        style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px);">
        <div class="glass-card"
            style="padding: 2rem; border-radius: 1rem; width: 100%; max-width: 28rem; border: 1px solid var(--border-color); box-shadow: var(--shadow-2xl);">
            <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--slate-900); margin-bottom: 1rem;">Permissions
            </h3>
            <input type="hidden" id="chmod-target">
            <div style="margin-bottom: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <label
                        style="display: block; color: var(--slate-700); font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Numeric
                        Value (Octal)</label>
                    <span style="font-size: 0.75rem; color: var(--slate-700);">Current: <span id="chmod-current"
                            style="color: var(--slate-700); font-family: monospace;">-</span></span>
                </div>
                <input type="text" id="chmod-val" value="0775" class="form-input" style="font-family: monospace;">
                <p style="font-size: 0.625rem; color: var(--slate-700); margin-top: 0.5rem;">
                    Examples: <span style="color: var(--primary); cursor: pointer;"
                        onclick="document.getElementById('chmod-val').value='0775'">0775</span> (Folder),
                    <span style="color: var(--primary); cursor: pointer;"
                        onclick="document.getElementById('chmod-val').value='0664'">0664</span> (File)
                </p>
            </div>
            <div id="chmod-warning" class="hidden"
                style="margin-bottom: 1rem; padding: 0.75rem; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: var(--radius-lg);">
                <div style="display: flex; align-items: flex-start; gap: 0.5rem;">
                    <i data-lucide="alert-triangle"
                        style="width: 16px; height: 16px; color: #fbbf24; margin-top: 0.125rem; flex-shrink: 0;"></i>
                    <div style="font-size: 0.75rem; color: #fcd34d;">
                        <p style="font-weight: 700; margin-bottom: 0.25rem;">Warning: Permission may be denied</p>
                        <p>The web server user (<?= htmlspecialchars($process_user) ?>) may not own this file. Changes
                            may fail.</p>
                    </div>
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button onclick="FM.closeModals()" class="btn btn-outline">Cancel</button>
                <button onclick="FM.doChmod()" class="btn btn-primary">Save</button>
            </div>
        </div>
    </div>

    <!-- CONTEXT MENU & MODALS (Kept in body) -->
    <!-- CONTEXT MENU -->
    <div id="ctx-menu" class="hidden"
        style="position: fixed; z-index: 50; background: white; border: 1px solid var(--border-color); box-shadow: var(--shadow-xl); border-radius: var(--radius-lg); width: 12rem; padding: 0.25rem 0; transform: scale(0.95); opacity: 0; transition: all 0.1s; transform-origin: top left;">
        <button onclick="FM.openCtx()"
            style="width: 100%; text-align: left; padding: 0.5rem 1rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 500; color: var(--slate-900); background: transparent; border: none; cursor: pointer; transition: background 0.2s;"
            onmouseover="this.style.backgroundColor='var(--slate-50)'"
            onmouseout="this.style.backgroundColor='transparent'"><i data-lucide="folder-open"
                style="width: 16px; height: 16px; color: var(--primary);"></i> Open</button>
        <button onclick="FM.editCtx()" id="ctx-btn-edit"
            style="width: 100%; text-align: left; padding: 0.5rem 1rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 500; color: var(--slate-900); background: transparent; border: none; cursor: pointer; transition: background 0.2s;"
            onmouseover="this.style.backgroundColor='var(--slate-50)'"
            onmouseout="this.style.backgroundColor='transparent'"><i data-lucide="file-code"
                style="width: 16px; height: 16px; color: #10b981;"></i> Edit</button>
        <button onclick="FM.renameCtx()"
            style="width: 100%; text-align: left; padding: 0.5rem 1rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; color: var(--slate-700); background: transparent; border: none; cursor: pointer; transition: background 0.2s;"
            onmouseover="this.style.backgroundColor='var(--slate-50)'"
            onmouseout="this.style.backgroundColor='transparent'"><i data-lucide="edit-3"
                style="width: 16px; height: 16px;"></i> Rename</button>
        <div style="height: 1px; background: var(--border-color); margin: 0.25rem 0;"></div>
        <button onclick="FM.extractCtx()" id="ctx-btn-extract"
            style="width: 100%; text-align: left; padding: 0.5rem 1rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 500; color: var(--slate-900); background: transparent; border: none; cursor: pointer; transition: background 0.2s;"
            onmouseover="this.style.backgroundColor='var(--slate-50)'"
            onmouseout="this.style.backgroundColor='transparent'"><i data-lucide="package-open"
                style="width: 16px; height: 16px; color: #f97316;"></i> Extract</button>
        <button onclick="FM.chmodCtx()"
            style="width: 100%; text-align: left; padding: 0.5rem 1rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; color: var(--slate-700); background: transparent; border: none; cursor: pointer; transition: background 0.2s;"
            onmouseover="this.style.backgroundColor='var(--slate-50)'"
            onmouseout="this.style.backgroundColor='transparent'">
            <i data-lucide="shield" style="width: 16px; height: 16px; color: var(--slate-700);"></i> Permissions
        </button>
        <button onclick="FM.bulk('copy')"
            style="width: 100%; text-align: left; padding: 0.5rem 1rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; color: var(--slate-700); background: transparent; border: none; cursor: pointer; transition: background 0.2s;"
            onmouseover="this.style.backgroundColor='var(--slate-50)'"
            onmouseout="this.style.backgroundColor='transparent'"><i data-lucide="copy"
                style="width: 16px; height: 16px;"></i> Copy</button>
        <button onclick="FM.bulk('move')"
            style="width: 100%; text-align: left; padding: 0.5rem 1rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; color: var(--slate-700); background: transparent; border: none; cursor: pointer; transition: background 0.2s;"
            onmouseover="this.style.backgroundColor='var(--slate-50)'"
            onmouseout="this.style.backgroundColor='transparent'"><i data-lucide="move"
                style="width: 16px; height: 16px;"></i> Move</button>
        <button onclick="FM.bulk('zip')"
            style="width: 100%; text-align: left; padding: 0.5rem 1rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; color: var(--slate-700); background: transparent; border: none; cursor: pointer; transition: background 0.2s;"
            onmouseover="this.style.backgroundColor='var(--slate-50)'"
            onmouseout="this.style.backgroundColor='transparent'"><i data-lucide="archive"
                style="width: 16px; height: 16px;"></i> Archive</button>
        <div style="height: 1px; background: var(--border-color); margin: 0.25rem 0;"></div>
        <button onclick="FM.bulk('delete')"
            style="width: 100%; text-align: left; padding: 0.5rem 1rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 500; color: #f87171; background: transparent; border: none; cursor: pointer; transition: background 0.2s, color 0.2s;"
            onmouseover="this.style.backgroundColor='rgba(239, 68, 68, 0.1)'; this.style.color='#fca5a5'"
            onmouseout="this.style.backgroundColor='transparent'; this.style.color='#f87171'"><i data-lucide="trash-2"
                style="width: 16px; height: 16px;"></i> Delete</button>
    </div>

    <!-- CREATE MODAL -->
    <div id="modal-create" class="modal hidden"
        style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px);">
        <div class="glass-card"
            style="padding: 2rem; border-radius: 1rem; width: 100%; max-width: 24rem; border: 1px solid var(--border-color); box-shadow: var(--shadow-2xl);">
            <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--slate-900); margin-bottom: 1.5rem;">New Item
            </h3>
            <div
                style="display: flex; background: white; border-radius: var(--radius-lg); padding: 0.25rem; margin-bottom: 1.5rem;">
                <button onclick="FM.setCreateType('file')" id="btn-c-file"
                    style="flex: 1; padding: 0.375rem 0; border-radius: 0.25rem; border: none; font-size: 0.875rem; font-weight: 700; cursor: pointer; transition: all 0.2s; background: var(--primary); color: white; box-shadow: var(--shadow-sm);">File</button>
                <button onclick="FM.setCreateType('folder')" id="btn-c-folder"
                    style="flex: 1; padding: 0.375rem 0; border-radius: 0.25rem; border: none; font-size: 0.875rem; font-weight: 700; text-align: center; cursor: pointer; transition: all 0.2s; background: transparent; color: var(--slate-700);">Folder</button>
            </div>
            <input id="input-create" type="text" placeholder="Name" class="form-input" style="margin-bottom: 1.5rem;">
            <div style="display: flex; gap: 0.75rem;">
                <button onclick="FM.closeModals()" class="btn btn-outline" style="flex: 1;">Cancel</button>
                <button onclick="FM.doCreate()" class="btn btn-primary" style="flex: 1;">Create</button>
            </div>
        </div>
    </div>

    <!-- RENAME MODAL -->
    <div id="modal-rename" class="modal hidden"
        style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px);">
        <div class="glass-card"
            style="padding: 2rem; border-radius: 1rem; width: 100%; max-width: 24rem; border: 1px solid var(--border-color); box-shadow: var(--shadow-2xl);">
            <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--slate-900); margin-bottom: 1.5rem;">Rename
            </h3>
            <input id="input-rename" type="text" class="form-input" style="margin-bottom: 1.5rem;">
            <input id="rename-target" type="hidden">
            <div style="display: flex; gap: 0.75rem;">
                <button onclick="FM.closeModals()" class="btn btn-outline" style="flex: 1;">Cancel</button>
                <button onclick="FM.doRename()" class="btn btn-primary" style="flex: 1;">Save</button>
            </div>
        </div>
    </div>

    <!-- COPY/MOVE MODAL -->
    <div id="modal-copymove" class="modal hidden"
        style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px);">
        <div class="glass-card"
            style="padding: 2rem; border-radius: 1rem; width: 100%; max-width: 24rem; border: 1px solid var(--border-color); box-shadow: var(--shadow-2xl);">
            <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--slate-900); margin-bottom: 1.5rem;"
                id="cm-title">Move Items</h3>
            <div
                style="margin-bottom: 1rem; font-size: 0.75rem; color: var(--slate-700); font-weight: 700; text-transform: uppercase;">
                Destination Folder</div>
            <div
                style="display: flex; background: white; border: 1px solid var(--slate-300); border-radius: var(--radius-xl); padding: 0.75rem 1rem; margin-bottom: 1.5rem; align-items: center; gap: 0.75rem;">
                <i data-lucide="folder" style="width: 16px; height: 16px; color: var(--slate-700);"></i>
                <input id="cm-dest" type="text"
                    style="background: transparent; outline: none; border: none; flex: 1; color: var(--slate-900); font-size: 0.875rem;"
                    placeholder="/path/to/folder">
            </div>
            <input type="hidden" id="cm-action">
            <div style="display: flex; gap: 0.75rem;">
                <button onclick="FM.closeModals()" class="btn btn-outline" style="flex: 1;">Cancel</button>
                <button onclick="FM.doCopyMove()" class="btn btn-primary" style="flex: 1;">Confirm</button>
            </div>
        </div>
    </div>

    <!-- PREVIEW MODAL -->
    <div id="modal-preview" class="modal hidden"
        style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0, 0, 0, 0.8); backdrop-filter: blur(6px);">
        <div class="glass-card"
            style="border-radius: 1rem; width: 100%; max-width: 56rem; height: 80vh; border: 1px solid var(--border-color); box-shadow: var(--shadow-2xl); display: flex; flex-direction: column; overflow: hidden; padding: 0;">
            <div
                style="height: 3rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 1rem; background: var(--slate-50);">
                <span id="preview-title"
                    style="font-family: monospace; font-size: 0.875rem; font-weight: 700; color: var(--slate-700);">filename.txt</span>
                <button onclick="FM.closeModals()"
                    style="padding: 0.25rem; background: transparent; border: none; border-radius: 0.25rem; color: var(--slate-700); cursor: pointer; transition: background 0.2s, color 0.2s;"
                    onmouseover="this.style.backgroundColor='var(--slate-100)'; this.style.color='var(--slate-900)'"
                    onmouseout="this.style.backgroundColor='transparent'; this.style.color='var(--slate-700)'"><i
                        data-lucide="x" style="width: 20px; height: 20px;"></i></button>
            </div>
            <div style="flex: 1; overflow: auto; background: var(--slate-50); padding: 0; position: relative; display: flex; align-items: center; justify-content: center;"
                id="preview-content">
                <!-- Content injected here -->
            </div>
            <div
                style="height: 3rem; border-top: 1px solid var(--border-color); display: flex; align-items: center; justify-content: flex-end; padding: 0 1rem; gap: 0.75rem; background: var(--slate-50);">
                <button onclick="FM.closeModals()" class="btn btn-outline">Close</button>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div id="toast"
        style="position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 100; transition: all 0.3s; transform: translateY(5rem); opacity: 0; background: #10b981; color: white; padding: 0.75rem 1.5rem; border-radius: var(--radius-xl); box-shadow: var(--shadow-2xl); display: flex; align-items: center; gap: 0.75rem; font-weight: 700;">
        <span></span>
    </div>

    <!-- HIDDEN FORMS FOR DOWNLOADS -->
    <form id="form-download" method="POST" target="_blank">
        <input type="hidden" name="domain_id" value="<?= $domain_id ?>">
        <input type="hidden" name="download_items" value="1">
        <div id="download-inputs"></div>
    </form>

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
                    btnGrid.classList.add('bg-slate-100', 'text-slate-900');
                    btnGrid.classList.remove('text-slate-700');
                    btnList.classList.remove('bg-slate-100', 'text-slate-900');
                    btnList.classList.add('text-blue-400');
                } else {
                    container.classList.add('view-list');
                    container.classList.remove('view-grid');
                    btnList.classList.add('bg-slate-100', 'text-blue-400');
                    btnGrid.classList.remove('bg-slate-100', 'text-slate-900');
                    btnGrid.classList.add('text-slate-700');
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
                const headerCheckbox = document.querySelector('.list-header input[type="checkbox"]');
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
                    bar.classList.remove('hidden', '-translate-y-full', 'opacity-0');
                    count.innerText = this.selected.size + ' Selected';

                    // Show Select All or Unselect All based on current state
                    if (this.selected.size === CONFIG.totalItems && CONFIG.totalItems > 0) {
                        btnSelectAll.classList.add('hidden');
                        btnUnselectAll.classList.remove('hidden');
                    } else {
                        btnSelectAll.classList.remove('hidden');
                        btnUnselectAll.classList.add('hidden');
                    }
                } else {
                    bar.classList.add('-translate-y-full', 'opacity-0');
                    setTimeout(() => bar.classList.add('hidden'), 300);
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
                const headerCheckbox = document.querySelector('.list-header input[type="checkbox"]');
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
                    // We fetch blob
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
                    const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                    if (res.status === 'success') {
                        container.innerHTML = `<pre style="font-size: 0.75rem; font-family: monospace; color: var(--slate-700); padding: 1rem; width: 100%; height: 100%; overflow: auto; text-align: left;">${res.content}</pre>`;
                    } else {
                        container.innerHTML = res.msg;
                    }
                }
            }

            // UTILS
            toast(type, msg) {
                const el = document.getElementById('toast');
                el.innerText = msg;
                const bg = type === 'success' ? '#10b981' : '#ef4444';
                el.style.cssText = `position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 100; transition: all 0.3s; transform: translateY(0); opacity: 1; background: ${bg}; color: white; padding: 0.75rem 1.5rem; border-radius: var(--radius-xl); box-shadow: var(--shadow-2xl); display: flex; align-items: center; gap: 0.75rem; font-weight: 700;`;
                setTimeout(() => {
                    el.style.transform = 'translateY(5rem)';
                    el.style.opacity = '0';
                }, 3000);
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
                const activeStyle = "flex: 1; padding: 0.375rem 0; border-radius: 0.25rem; border: none; font-size: 0.875rem; font-weight: 700; cursor: pointer; transition: all 0.2s; background: var(--primary); color: white; box-shadow: var(--shadow-sm);";
                const inactiveStyle = "flex: 1; padding: 0.375rem 0; border-radius: 0.25rem; border: none; font-size: 0.875rem; font-weight: 700; text-align: center; cursor: pointer; transition: all 0.2s; background: transparent; color: var(--slate-700);";
                document.getElementById('btn-c-file').style.cssText = t === 'file' ? activeStyle : inactiveStyle;
                document.getElementById('btn-c-folder').style.cssText = t === 'folder' ? activeStyle : inactiveStyle;
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