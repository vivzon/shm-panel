<?php
/**
 * VIVZON FILE MANAGER - Enterprise v5.0
 * Optimized for CPanel Integration
 */
// Config Path
require_once __DIR__ . '/../shared/config.php';

// Authentication Check
if (!isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['cid'];

// Increase execution limits for large uploads/zips
// Increase execution limits for large uploads/zips
ini_set('upload_max_filesize', '2048M');
ini_set('post_max_size', '2048M');
ini_set('memory_limit', '2048M');
ini_set('max_execution_time', '3600');

/**
 * PATH HELPERS
 */
function shm_normalize_relative($path)
{
    $path = str_replace(['\\', '//'], '/', $path);
    $path = '/' . ltrim($path, '/');
    $parts = array_filter(explode('/', $path));
    $safe = [];
    foreach ($parts as $part) {
        if ($part === '.')
            continue;
        if ($part === '..') {
            array_pop($safe);
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
    // Security check: ensure final path starts with base
    if (strpos($full, $base) !== 0)
        return false;
    return $full;
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
    foreach (scandir($path) as $item) {
        if ($item === '.' || $item === '..')
            continue;
        if (!shm_rrmdir($path . DIRECTORY_SEPARATOR . $item))
            return false;
    }
    return @rmdir($path);
}

function shm_rcopy($src, $dst)
{
    if (file_exists($dst))
        shm_rrmdir($dst);
    if (is_dir($src)) {
        mkdir($dst);
        $files = scandir($src);
        foreach ($files as $file) {
            if ($file != "." && $file != "..")
                shm_rcopy("$src/$file", "$dst/$file");
        }
    } else if (file_exists($src)) {
        copy($src, $dst);
    }
}

// ------------- INPUTS -------------
$domain_id = isset($_REQUEST['domain_id']) ? (int) $_REQUEST['domain_id'] : 0;
$current_path = isset($_REQUEST['path']) ? shm_normalize_relative($_REQUEST['path']) : '/';

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

// On Windows local dev, map /var/www to a local folder
if (DIRECTORY_SEPARATOR === '\\') {
    // If path starts with /var, re-map it to a local 'storage' folder for testing
    if (strpos($base_path, '/var') === 0 || strpos($base_path, '/') === 0) {
        $base_path = __DIR__ . '/../../storage/' . ($_SESSION['client'] ?? 'guest');
        $base_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base_path);
    }
}

// FIX: Ensure Base Path Exists & Is Writable
$setup_error = null;
if (!file_exists($base_path)) {
    // Attempt creation without suppression to log warnings
    $created = mkdir($base_path, 0777, true);
    if (!$created) {
        $error = error_get_last();
        // Fallback for Windows Local Dev if not already handled
        if (DIRECTORY_SEPARATOR === '\\') {
            $base_path = __DIR__ . '/../../storage/default';
            mkdir($base_path, 0777, true);
        } else {
            $setup_error = "Failed to create directory (mkdir returned false): " . ($error['message'] ?? 'Unknown error');
            error_log("SHM-FM Critical: $setup_error");
        }
    } else {
        // Mkdir returned true, verify existence
        if (!is_dir($base_path)) {
            $setup_error = "Ghost Directory: mkdir returned true but directory does not exist. Check Filesystem/Mounts.";
            error_log("SHM-FM Critical: $setup_error");
        }
    }
}

// Try to Fix Permissions
if (is_dir($base_path) && !is_writable($base_path)) {
    chmod($base_path, 0775);
}

$full_path = shm_build_path($base_path, $current_path);

// Auto-create subfolders if missing
if (!file_exists($full_path)) {
    $created = mkdir($full_path, 0777, true);
    clearstatcache(true, $full_path); // Clear cache to ensure next check is real

    if (!$created) {
        $err = error_get_last();
        if (!$setup_error)
            $setup_error = "Failed to create subfolder: " . ($err['message'] ?? 'Unknown');
    } elseif (!is_dir($full_path)) {
        // It said true, but it's not there?
        if (!$setup_error)
            $setup_error = "Ghost Directory (Subfolder): mkdir said success but dir is missing.";
    }
}

// Re-Check
clearstatcache(true, $full_path);
$is_writable = is_writable($full_path);

// -------- POST ACTIONS --------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['status' => 'error', 'msg' => 'Operation Failed'];
    $is_ajax = isset($_POST['ajax']) || isset($_POST['ajax_action']);

    if (!$is_writable) {
        // Diagnostic Info
        $process_user = (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
        $path_owner = file_exists($full_path) ? fileowner($full_path) : 'N/A';
        $perms = file_exists($full_path) ? substr(sprintf('%o', fileperms($full_path)), -4) : 'N/A';

        $debug = "Path: $full_path | Process: $process_user | User: " . get_current_user() . " | Owner: $path_owner | Perms: $perms | Exists: " . (file_exists($full_path) ? 'Y' : 'N');

        $msg = $setup_error ? "System Error: $setup_error" : "Permission Denied. $debug";

        error_log("SHM-FM Error: $msg");

        if ($is_ajax) {
            echo json_encode(['status' => 'error', 'msg' => $msg]);
            exit;
        }
    }

    // Helper to return
    function fm_return($status, $msg = '', $data = [])
    {
        global $domain_id, $current_path, $is_ajax;
        if ($is_ajax) {
            echo json_encode(array_merge(['status' => $status, 'msg' => $msg], $data));
        } else {
            header("Location: ?domain_id=$domain_id&path=$current_path");
        }
        exit;
    }

    // 1. UPLOAD
    if (isset($_POST['upload_files'])) {
        $count = 0;
        $errors = [];
        if (!isset($_FILES['files']['name'])) {
            fm_return('error', 'No files received');
        }
        foreach ($_FILES['files']['name'] as $key => $name) {
            $target = $full_path . '/' . basename($name);
            if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) {
                $errors[] = "$name: Error Code " . $_FILES['files']['error'][$key];
                continue;
            }
            if (move_uploaded_file($_FILES['files']['tmp_name'][$key], $target)) {
                $count++;
            } else {
                $errors[] = "$name: Move failed (Check Permissions)";
                error_log("SHM-FM Upload Error: Could not move " . $_FILES['files']['tmp_name'][$key] . " to $target");
            }
        }
        if ($count > 0) {
            fm_return('success', "$count files uploaded");
        } else {
            fm_return('error', 'Upload failed: ' . implode(', ', $errors));
        }
    }

    // 2. CREATE
    if (isset($_POST['create_item'])) {
        $name = preg_replace('/[^a-zA-Z0-9\._-]/', '', $_POST['name']);
        $target = $full_path . '/' . $name;
        if (file_exists($target))
            fm_return('error', 'Item already exists');

        if ($_POST['type'] == 'folder') {
            if (mkdir($target, 0775))
                fm_return('success', 'Folder created');
        } else {
            if (file_put_contents($target, '') !== false)
                fm_return('success', 'File created');
        }
        fm_return('error', 'Creation failed');
    }

    // 3. DELETE
    if (isset($_POST['delete_paths'])) {
        $count = 0;
        foreach ($_POST['paths'] as $p) {
            $abs = shm_build_path($base_path, $p);
            // Critical Safeguard: Prevent Deletion of Root
            if ($abs === $base_path)
                continue;

            if ($abs && shm_rrmdir($abs))
                $count++;
        }
        fm_return('success', "$count items deleted");
    }

    // 4. ZIP
    if (isset($_POST['zip_paths'])) {
        $zip = new ZipArchive();
        $zip_name = $full_path . '/' . (count($_POST['paths']) > 1 ? 'archive_' . date('Hi') . '.zip' : basename($_POST['paths'][0]) . '.zip');
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
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        } else {
            // Zip Download
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
                header('Content-Type: application/zip');
                header('Content-disposition: attachment; filename=' . $zip_name);
                header('Content-Length: ' . filesize($tmp_zip));
                readfile($tmp_zip);
                unlink($tmp_zip);
                exit;
            }
        }
    }

    // 9. PREVIEW
    if (isset($_POST['preview_item'])) {
        $file = shm_build_path($base_path, $_POST['item']);
        if (is_file($file)) {
            $content = file_get_contents($file, false, NULL, 0, 10240);
            echo json_encode(['status' => 'success', 'type' => 'code', 'content' => htmlspecialchars($content)]);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'File not found']);
        }
        exit;
    }

    // 10. CHMOD
    if (isset($_POST['chmod_item'])) {
        $target = shm_build_path($base_path, $_POST['item']);
        $mode = intval($_POST['mode'], 8); // Octal
        if ($target && chmod($target, $mode)) {
            fm_return('success', 'Permissions updated');
        }
        fm_return('error', 'Failed to change permissions');
    }
}

// -------- READ DIRECTORY --------
$items = [];
if (is_dir($full_path)) {
    foreach (scandir($full_path) as $item) {
        if ($item === '.' || $item === '..')
            continue;
        $abs = $full_path . '/' . $item;
        $items[] = [
            'name' => $item,
            'is_dir' => is_dir($abs),
            'size' => is_dir($abs) ? '-' : round(filesize($abs) / 1024, 2) . ' KB',
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>File Manager | Vivzon CPanel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            color: #f1f5f9;
        }

        .glass-panel {
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .file-item:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .file-item.selected {
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        /* Grid View */
        .view-grid .list-layout {
            display: none;
        }

        .view-grid .grid-layout {
            display: flex;
        }

        .view-grid #file-view {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 16px;
            padding: 24px;
            align-content: start;
        }

        .view-grid .list-header {
            display: none;
        }

        /* List View */
        .view-list .list-layout {
            display: grid;
        }

        .view-list .grid-layout {
            display: none;
        }

        .view-list #file-view {
            display: block;
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 3px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }

        .dashed-border {
            border: 2px dashed rgba(255, 255, 255, 0.2);
        }
    </style>
</head>

<body class="flex h-screen overflow-hidden text-sm">

    <?php
    $current_page = 'files.php';
    $collapse_sidebar = true;
    include 'layout/sidebar.php';
    ?>

    <main class="flex-1 flex flex-col h-screen relative bg-[#0b1120] overflow-hidden">
        <!-- TOP NAVIGATION & ACTION BAR -->
        <header class="h-16 shrink-0 glass-panel border-b border-white/5 flex items-center justify-between px-6 z-20">
            <div class="flex items-center gap-6">
                <!-- Toggle Sidebar for Mobile (optional, but good to have space for) -->

                <div class="flex items-center gap-3">
                    <div
                        class="p-2 bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg shadow-lg shadow-blue-500/20">
                        <i data-lucide="folder-kanban" class="w-5 h-5 text-white"></i>
                    </div>
                    <h1 class="font-bold text-lg text-white tracking-tight">File Manager</h1>
                </div>

                <div class="h-6 w-px bg-white/10"></div>

                <!-- Breadcrumbs -->
                <nav class="flex items-center text-sm font-medium">
                    <a href="?domain_id=<?= $domain_id ?>&path=/"
                        class="hover:text-white transition flex items-center gap-1 group">
                        <i data-lucide="hard-drive" class="w-4 group-hover:text-blue-400 transition"></i>
                    </a>
                    <?php
                    $crumbs = array_filter(explode('/', $current_path));
                    $acc = '';
                    foreach ($crumbs as $c):
                        $acc .= '/' . $c;
                        ?>
                        <i data-lucide="chevron-right" class="w-4 text-slate-600 mx-1"></i>
                        <a href="?domain_id=<?= $domain_id ?>&path=<?= $acc ?>"
                            class="hover:text-white transition hover:bg-white/5 px-2 py-1 rounded-md"><?= $c ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="flex items-center gap-4">
                <!-- Search -->
                <div class="relative group">
                    <i data-lucide="search"
                        class="w-4 absolute left-3 top-2.5 text-slate-500 group-focus-within:text-blue-400 transition"></i>
                    <input id="file-search" onkeyup="FM.filter()" placeholder="Search current folder..."
                        class="bg-slate-900/50 border border-white/5 rounded-xl pl-10 pr-4 py-2 text-sm w-64 focus:w-80 transition-all outline-none focus:border-blue-500/50 focus:bg-slate-900">
                </div>

                <!-- View Toggles -->
                <div class="flex p-1 bg-slate-900/50 rounded-lg border border-white/5">
                    <button onclick="FM.setView('list')" id="btn-list"
                        class="p-1.5 rounded-md hover:text-white transition text-blue-400 bg-white/10"><i
                            data-lucide="list" class="w-4"></i></button>
                    <button onclick="FM.setView('grid')" id="btn-grid"
                        class="p-1.5 rounded-md hover:text-white transition text-slate-500"><i data-lucide="layout-grid"
                            class="w-4"></i></button>
                </div>

                <div class="h-6 w-px bg-white/10"></div>

                <button onclick="FM.openUpload()"
                    class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-xl text-sm font-bold shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
                    <i data-lucide="upload-cloud" class="w-4"></i> Upload
                </button>
            </div>
        </header>

        <!-- ACTION BAR (Contextual) -->
        <div id="action-bar"
            class="h-12 border-b border-white/5 bg-slate-900/30 flex items-center justify-between px-6 transition-all duration-300 transform -translate-y-full opacity-0 absolute top-16 w-full z-10 hidden">
            <div class="flex items-center gap-4 text-sm font-medium">
                <span class="text-blue-400 font-bold" id="selection-count">0 Selected</span>
                <div class="h-4 w-px bg-white/10"></div>
                <button onclick="FM.bulk('download')" class="hover:text-white flex items-center gap-2 transition"><i
                        data-lucide="download" class="w-4"></i> Download</button>
                <button onclick="FM.bulk('zip')" class="hover:text-white flex items-center gap-2 transition"><i
                        data-lucide="archive" class="w-4"></i> Archive</button>
                <button onclick="FM.bulk('copy')" class="hover:text-white flex items-center gap-2 transition"><i
                        data-lucide="copy" class="w-4"></i> Copy</button>
                <button onclick="FM.bulk('move')" class="hover:text-white flex items-center gap-2 transition"><i
                        data-lucide="move" class="w-4"></i> Move</button>
                <div class="h-4 w-px bg-white/10"></div>
                <button onclick="FM.bulk('delete')"
                    class="text-red-400 hover:text-red-300 flex items-center gap-2 transition"><i data-lucide="trash-2"
                        class="w-4"></i> Delete</button>
            </div>
            <button onclick="FM.clearSelection()" class="text-slate-500 hover:text-white"><i data-lucide="x"
                    class="w-4"></i></button>
        </div>

        <div class="flex flex-1 overflow-hidden">
            <!-- SIDEBAR (File System Nav) -->
            <aside class="w-64 border-r border-white/5 bg-slate-900/30 flex flex-col hidden md:flex">
                <div class="p-4">
                    <button onclick="FM.openCreate()"
                        class="w-full py-3 rounded-xl border border-dashed border-slate-600 hover:border-blue-500 hover:bg-blue-500/5 hover:text-blue-400 transition text-sm font-bold flex items-center justify-center gap-2 text-slate-400">
                        <i data-lucide="plus" class="w-4"></i> New Item
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-2 space-y-1">
                    <div class="px-3 py-2 text-xs font-bold text-slate-500 uppercase tracking-wider">Locations</div>
                    <a href="?domain_id=<?= $domain_id ?>&path=/"
                        class="flex items-center gap-3 px-3 py-2 rounded-lg bg-blue-500/10 text-blue-400 font-medium text-sm">
                        <i data-lucide="home" class="w-4"></i> Home Root
                    </a>

                    <div class="mt-6 px-3 py-2 text-xs font-bold text-slate-500 uppercase tracking-wider">Domains</div>
                    <?php
                    $doms = $pdo->prepare("SELECT id, domain FROM domains WHERE client_id = ?");
                    $doms->execute([$user_id]);
                    while ($d = $doms->fetch()):
                        ?>
                        <a href="?domain_id=<?= $d['id'] ?>"
                            class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-white/5 text-slate-400 hover:text-white transition text-sm <?= $d['id'] == $domain_id ? '!text-white !bg-white/10' : '' ?>">
                            <i data-lucide="globe" class="w-4"></i> <?= $d['domain'] ?>
                        </a>
                    <?php endwhile; ?>
                </div>

                <!-- Storage Status -->
                <div class="p-4 border-t border-white/5">
                    <div class="flex justify-between text-xs mb-2">
                        <span class="text-slate-400">Storage</span>
                        <span class="font-bold text-white"><?= $domain['disk_usage'] ?? '0' ?> MB</span>
                    </div>
                    <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-500 w-3/4"></div> <!-- Placeholder for real % -->
                    </div>
                </div>
            </aside>

            <!-- MAIN FILE AREA -->
            <main class="flex-1 relative bg-slate-900/20" id="drop-zone-global">

                <div id="file-view" class="h-full overflow-y-auto p-6 view-list custom-scrollbar">

                    <!-- LIST HEADER -->
                    <div
                        class="grid grid-cols-12 gap-4 px-4 py-2 border-b border-white/5 text-xs font-bold uppercase text-slate-500 tracking-wider mb-2 list-header sticky top-0 bg-[#0f172a] z-10 hidden">
                        <div class="col-span-6 pl-8 flex items-center gap-3">
                            <div class="w-5 flex justify-center">
                                <input type="checkbox" onchange="FM.selectAll(this.checked)"
                                    class="accent-blue-500 w-4 h-4 cursor-pointer">
                            </div>
                            Name
                        </div>
                        <div class="col-span-2">Size</div>
                        <div class="col-span-2">Type</div>
                        <div class="col-span-2 text-right">Modified</div>
                    </div>

                    <!-- PARENT DIR -->
                    <?php if ($current_path != '/'): ?>
                        <div onclick="location.href='?domain_id=<?= $domain_id ?>&path=<?= dirname($current_path) ?>'"
                            class="grid grid-cols-12 gap-4 px-4 py-3 rounded-xl hover:bg-white/5 cursor-pointer items-center text-slate-400 hover:text-white transition group mb-1">
                            <div class="col-span-6 flex items-center gap-4">
                                <i data-lucide="corner-left-up"
                                    class="w-5 text-slate-600 group-hover:text-blue-400 transition"></i>
                                <span class="font-bold">..</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ITEMS LOOP -->
                    <?php foreach ($items as $i):
                        $icon = $i['is_dir'] ? 'folder' : 'file';
                        $color = $i['is_dir'] ? 'text-amber-400' : 'text-slate-400';
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
                        <div class="file-item group select-none transition-all duration-200 cursor-pointer"
                            data-name="<?= strtolower($i['name']) ?>" data-path="<?= $i['rel'] ?>"
                            data-type="<?= $i['is_dir'] ? 'dir' : 'file' ?>" onclick="FM.toggleSelect(this, event)"
                            ondblclick="FM.open('<?= $i['rel'] ?>', '<?= $i['is_dir'] ? 'dir' : 'file' ?>')">

                            <!-- Inner Content (CSS handles List/Grid layout) -->
                            <div
                                class="file-inner px-4 py-3 rounded-xl border border-transparent group-hover:bg-white/5 group-hover:border-white/5">
                                <!-- List Layout -->
                                <div class="grid grid-cols-12 gap-4 items-center list-layout">
                                    <div class="col-span-6 flex items-center gap-4 overflow-hidden">
                                        <div class="w-5 flex justify-center">
                                            <input type="checkbox"
                                                class="accent-blue-500 w-4 h-4 opacity-0 group-hover:opacity-100 transition file-check pointer-events-none">
                                        </div>
                                        <i data-lucide="<?= $icon ?>" class="w-5 h-5 <?= $color ?> shrink-0"></i>
                                        <span
                                            class="truncate font-medium text-slate-300 group-hover:text-white"><?= $i['name'] ?></span>
                                    </div>
                                    <div class="col-span-2 text-sm text-slate-500 font-mono"><?= $i['size'] ?></div>
                                    <div class="col-span-2 text-sm text-slate-500 uppercase"><?= $type ?></div>
                                    <div class="col-span-2 text-right text-sm text-slate-500 font-mono"><?= $i['date'] ?>
                                    </div>
                                </div>

                                <!-- Grid Layout -->
                                <div class="hidden flex-col items-center text-center gap-3 py-4 grid-layout relative">
                                    <div class="absolute top-2 left-2 opacity-0 group-hover:opacity-100 transition">
                                        <input type="checkbox" class="accent-blue-500 w-4 h-4 file-check">
                                    </div>
                                    <div class="p-4 rounded-2xl bg-slate-800/50 group-hover:bg-slate-800 transition">
                                        <i data-lucide="<?= $icon ?>" class="w-10 h-10 <?= $color ?>"></i>
                                    </div>
                                    <div class="w-full">
                                        <div class="truncate font-medium text-sm text-slate-300 group-hover:text-white">
                                            <?= $i['name'] ?>
                                        </div>
                                        <div class="text-xs text-slate-500 mt-1"><?= $i['size'] ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($items)): ?>
                        <div class="flex flex-col items-center justify-center h-64 text-slate-500">
                            <i data-lucide="folder-open" class="w-12 h-12 mb-4 opacity-50"></i>
                            <p>This folder is empty</p>
                        </div>
                    <?php endif; ?>

                </div>

                <!-- Upload Overlay -->
                <div id="drag-overlay"
                    class="absolute inset-0 bg-blue-600/90 backdrop-blur-sm z-50 hidden flex flex-col items-center justify-center text-white dashed-border m-4 rounded-3xl pointer-events-none">
                    <i data-lucide="cloud-upload" class="w-20 h-20 mb-6 animate-bounce"></i>
                    <h3 class="text-3xl font-bold">Drop files to upload</h3>
                    <p class="text-blue-100 mt-2">to <?= $current_path ?></p>
                </div>
            </main>
        </div>
    </main>

    <!-- UPLOAD MODAL -->
    <div id="modal-upload"
        class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="glass-panel p-8 rounded-2xl w-full max-w-lg border border-white/10 shadow-2xl">
            <h3 class="font-bold text-xl text-white mb-6">Upload Files</h3>
            <div class="border-2 border-dashed border-slate-600 rounded-xl p-8 flex flex-col items-center justify-center text-center cursor-pointer hover:border-blue-500 hover:bg-slate-800/50 transition mb-6"
                onclick="document.getElementById('inp-upload-files').click()"
                ondrop="FM.handleDrop(event.dataTransfer.files); FM.closeModals();" ondragover="event.preventDefault()">
                <i data-lucide="cloud-upload" class="w-12 h-12 text-slate-400 mb-3"></i>
                <p class="text-slate-300 font-medium">Click to browse or drag files here</p>
                <input type="file" id="inp-upload-files" multiple class="hidden" onchange="FM.doUploadInput(this)">
            </div>
            <div class="flex justify-end">
                <button onclick="FM.closeModals()"
                    class="px-6 py-2 rounded-xl font-bold text-slate-400 hover:bg-white/5 transition">Close</button>
            </div>
        </div>
    </div>

    <!-- CHMOD MODAL -->
    <div id="modal-chmod"
        class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="glass-panel p-8 rounded-2xl w-full max-w-sm border border-white/10 shadow-2xl">
            <h3 class="font-bold text-xl text-white mb-4">Permissions</h3>
            <input type="hidden" id="chmod-target">
            <div class="mb-6">
                <label class="block text-slate-400 text-xs uppercase font-bold mb-2">Numeric Value (Octal)</label>
                <input type="text" id="chmod-val" value="0775"
                    class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-3 text-white font-mono outline-none focus:border-blue-500">
                <p class="text-[10px] text-slate-500 mt-2">
                    Examples: <span class="text-blue-400 cursor-pointer"
                        onclick="document.getElementById('chmod-val').value='0775'">0775</span> (Folder),
                    <span class="text-blue-400 cursor-pointer"
                        onclick="document.getElementById('chmod-val').value='0664'">0664</span> (File)
                </p>
            </div>
            <div class="flex justify-end gap-2">
                <button onclick="FM.closeModals()"
                    class="px-4 py-2 rounded-xl font-bold text-slate-400 hover:bg-white/5 transition">Cancel</button>
                <button onclick="FM.doChmod()"
                    class="px-6 py-2 rounded-xl font-bold bg-blue-600 text-white hover:bg-blue-500 transition shadow-lg">Save</button>
            </div>
        </div>
    </div>

    <!-- CONTEXT MENU & MODALS (Kept in body) -->
    <!-- CONTEXT MENU -->
    <div id="ctx-menu"
        class="fixed z-50 bg-[#1e293b] border border-slate-700 shadow-2xl rounded-xl w-48 py-1 hidden transform scale-95 opacity-0 transition-all duration-100 origin-top-left">
        <button onclick="FM.openCtx()"
            class="w-full text-left px-4 py-2 hover:bg-white/5 text-sm flex items-center gap-2 font-medium text-white"><i
                data-lucide="folder-open" class="w-4 text-blue-400"></i> Open</button>
        <button onclick="FM.editCtx()" id="ctx-btn-edit"
            class="w-full text-left px-4 py-2 hover:bg-white/5 text-sm flex items-center gap-2 font-medium text-white"><i
                data-lucide="file-code" class="w-4 text-emerald-400"></i> Edit</button>
        <button onclick="FM.renameCtx()"
            class="w-full text-left px-4 py-2 hover:bg-white/5 text-sm flex items-center gap-2 text-slate-300"><i
                data-lucide="edit-3" class="w-4"></i> Rename</button>
        <div class="h-px bg-white/10 my-1"></div>
        <button onclick="FM.extractCtx()" id="ctx-btn-extract"
            class="w-full text-left px-4 py-2 hover:bg-white/5 text-sm flex items-center gap-2 font-medium text-white"><i
                data-lucide="package-open" class="w-4 text-orange-400"></i> Extract</button>
        <button onclick="FM.chmodCtx()"
            class="w-full text-left px-4 py-2 hover:bg-white/5 text-sm flex items-center gap-2 text-slate-300">
            <i data-lucide="shield" class="w-4 text-slate-400"></i> Permissions
        </button>
        <button onclick="FM.bulk('copy')"
            class="w-full text-left px-4 py-2 hover:bg-white/5 text-sm flex items-center gap-2 text-slate-300"><i
                data-lucide="copy" class="w-4"></i> Copy</button>
        <button onclick="FM.bulk('move')"
            class="w-full text-left px-4 py-2 hover:bg-white/5 text-sm flex items-center gap-2 text-slate-300"><i
                data-lucide="move" class="w-4"></i> Move</button>
        <button onclick="FM.bulk('zip')"
            class="w-full text-left px-4 py-2 hover:bg-white/5 text-sm flex items-center gap-2 text-slate-300"><i
                data-lucide="archive" class="w-4"></i> Archive</button>
        <div class="h-px bg-white/10 my-1"></div>
        <button onclick="FM.bulk('delete')"
            class="w-full text-left px-4 py-2 hover:bg-red-500/10 text-red-400 hover:text-red-300 text-sm flex items-center gap-2 font-medium"><i
                data-lucide="trash-2" class="w-4"></i> Delete</button>
    </div>

    <!-- CREATE MODAL -->
    <div id="modal-create"
        class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="glass-panel p-8 rounded-2xl w-full max-w-sm border border-white/10 shadow-2xl">
            <h3 class="font-bold text-xl text-white mb-6">New Item</h3>
            <div class="flex bg-slate-900 rounded-lg p-1 mb-6">
                <button onclick="FM.setCreateType('file')" id="btn-c-file"
                    class="flex-1 py-1.5 rounded text-sm font-bold bg-blue-600 text-white shadow transition">File</button>
                <button onclick="FM.setCreateType('folder')" id="btn-c-folder"
                    class="flex-1 py-1.5 rounded text-sm font-bold text-slate-400 hover:text-white transition">Folder</button>
            </div>
            <input id="input-create" type="text" placeholder="Name"
                class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 outline-none focus:border-blue-500 mb-6 text-white text-sm">
            <div class="flex gap-3">
                <button onclick="FM.closeModals()"
                    class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-white/5 transition">Cancel</button>
                <button onclick="FM.doCreate()"
                    class="flex-1 py-2.5 rounded-xl font-bold bg-blue-600 text-white hover:bg-blue-500 shadow-lg shadow-blue-500/20 transition">Create</button>
            </div>
        </div>
    </div>

    <!-- RENAME MODAL -->
    <div id="modal-rename"
        class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="glass-panel p-8 rounded-2xl w-full max-w-sm border border-white/10 shadow-2xl">
            <h3 class="font-bold text-xl text-white mb-6">Rename</h3>
            <input id="input-rename" type="text"
                class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 outline-none focus:border-blue-500 mb-6 text-white text-sm">
            <input id="rename-target" type="hidden">
            <div class="flex gap-3">
                <button onclick="FM.closeModals()"
                    class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-white/5 transition">Cancel</button>
                <button onclick="FM.doRename()"
                    class="flex-1 py-2.5 rounded-xl font-bold bg-blue-600 text-white hover:bg-blue-500 shadow-lg shadow-blue-500/20 transition">Save</button>
            </div>
        </div>
    </div>

    <!-- COPY/MOVE MODAL -->
    <div id="modal-copymove"
        class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="glass-panel p-8 rounded-2xl w-full max-w-sm border border-white/10 shadow-2xl">
            <h3 class="font-bold text-xl text-white mb-6" id="cm-title">Move Items</h3>
            <div class="mb-4 text-xs text-slate-400 font-bold uppercase">Destination Folder</div>
            <div class="flex bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 mb-6 items-center gap-3">
                <i data-lucide="folder" class="w-4 text-slate-500"></i>
                <input id="cm-dest" type="text" class="bg-transparent outline-none flex-1 text-white text-sm"
                    placeholder="/path/to/folder">
            </div>
            <input type="hidden" id="cm-action">
            <div class="flex gap-3">
                <button onclick="FM.closeModals()"
                    class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-white/5 transition">Cancel</button>
                <button onclick="FM.doCopyMove()"
                    class="flex-1 py-2.5 rounded-xl font-bold bg-blue-600 text-white hover:bg-blue-500 shadow-lg shadow-blue-500/20 transition">Confirm</button>
            </div>
        </div>
    </div>

    <!-- PREVIEW MODAL -->
    <div id="modal-preview"
        class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/90 backdrop-blur-md">
        <div
            class="glass-panel rounded-2xl w-full max-w-4xl h-[80vh] border border-white/10 shadow-2xl flex flex-col overflow-hidden">
            <div class="h-12 border-b border-white/5 flex items-center justify-between px-4 bg-slate-900/50">
                <span id="preview-title" class="font-mono text-sm font-bold text-slate-300">filename.txt</span>
                <button onclick="FM.closeModals()"
                    class="p-1 hover:bg-white/10 rounded text-slate-400 hover:text-white"><i data-lucide="x"
                        class="w-5"></i></button>
            </div>
            <div class="flex-1 overflow-auto bg-[#0a0f1c] p-0 relative flex items-center justify-center"
                id="preview-content">
                <!-- Content injected here -->
            </div>
            <div class="h-12 border-t border-white/5 flex items-center justify-end px-4 gap-3 bg-slate-900/50">
                <button onclick="FM.closeModals()"
                    class="px-4 py-1.5 bg-white/10 text-white rounded-lg text-sm font-bold hover:bg-white/20 transition">Close</button>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div id="toast"
        class="fixed bottom-6 right-6 z-[100] transition-all duration-300 transform translate-y-20 opacity-0 bg-emerald-600 text-white px-6 py-3 rounded-xl shadow-2xl flex items-center gap-3 font-bold">
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
            currentPath: '<?= $current_path ?>',
            isWritable: <?= $is_writable ? 'true' : 'false' ?>
        };

        // ICONS
        lucide.createIcons();

        // FILE MANAGER CLASS
        class FileManager {
            constructor() {
                this.view = localStorage.getItem('fm_view') || 'list';
                this.selected = new Set();
                this.init();
            }

            init() {
                this.setView(this.view);
                this.initDragDrop();
                document.addEventListener('keydown', e => {
                    if (e.key === 'Escape') this.closeModals();
                    if (e.ctrlKey && e.key === 'a') {
                        e.preventDefault();
                        this.selectAll();
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
                // Determine suggestion based on type
                const suggested = this.ctxType === 'dir' ? '0775' : '0664';
                document.getElementById('chmod-val').value = suggested;
                document.getElementById('chmod-target').value = this.ctxItem;
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

                // document.getElementById('file-container').className = `flex-1 overflow-hidden relative view-${mode}`; // Update main container wrapper if needed, but we used IDs

                if (mode === 'grid') {
                    container.classList.add('view-grid');
                    container.classList.remove('view-list');
                    btnGrid.classList.add('bg-white/10', 'text-white');
                    btnGrid.classList.remove('text-slate-500');
                    btnList.classList.remove('bg-white/10', 'text-white');
                    btnList.classList.add('text-blue-400'); // actually swap style
                } else {
                    container.classList.add('view-list');
                    container.classList.remove('view-grid');
                    btnList.classList.add('bg-white/10', 'text-blue-400');
                    btnGrid.classList.remove('bg-white/10', 'text-white');
                    btnGrid.classList.add('text-slate-500');
                }
            }

            // SELECTION
            toggleSelect(el, e) {
                if (e.target.closest('input')) return; // Checkbox handled naturally? No, we custom handle
                // Actually in list view checkbox is separate.
                // Unified logic:
                const path = el.dataset.path;
                if (this.selected.has(path)) {
                    this.selected.delete(path);
                    el.classList.remove('selected');
                    el.querySelector('.file-check').checked = false;
                    el.querySelector('.file-check').classList.remove('opacity-100'); // Grid view
                } else {
                    this.selected.add(path);
                    el.classList.add('selected');
                    el.querySelector('.file-check').checked = true;
                    el.querySelector('.file-check').classList.add('opacity-100');
                }
                this.updateActionBar();
            }

            updateActionBar() {
                const bar = document.getElementById('action-bar');
                const count = document.getElementById('selection-count');
                if (this.selected.size > 0) {
                    bar.classList.remove('hidden', '-translate-y-full', 'opacity-0');
                    count.innerText = this.selected.size + ' Selected';
                } else {
                    bar.classList.add('-translate-y-full', 'opacity-0');
                    setTimeout(() => bar.classList.add('hidden'), 300);
                }
            }

            clearSelection() {
                this.selected.clear();
                document.querySelectorAll('.file-item.selected').forEach(el => {
                    el.classList.remove('selected');
                    el.querySelector('.file-check').checked = false;
                });
                this.updateActionBar();
            }

            selectAll(checked) {
                if (checked) {
                    document.querySelectorAll('.file-item').forEach(el => {
                        const path = el.dataset.path;
                        this.selected.add(path);
                        el.classList.add('selected');
                        el.querySelector('.file-check').checked = true;
                    });
                } else {
                    this.clearSelection();
                }
                this.updateActionBar();
            }

            // NAVIGATION
            open(path, type) {
                if (type === 'dir') {
                    location.href = `?domain_id=${CONFIG.domainId}&path=${path}`;
                } else {
                    const ext = path.split('.').pop().toLowerCase();
                    const editable = ['php', 'html', 'css', 'js', 'json', 'xml', 'txt', 'md', 'sql', 'htaccess', 'env', 'ini', 'conf'];

                    if (editable.includes(ext)) {
                        location.href = `editor.php?domain_id=${CONFIG.domainId}&file=${path}`;
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
                for (let k in data) {
                    if (Array.isArray(data[k])) data[k].forEach(v => fd.append(`${k}[]`, v));
                    else fd.append(k, data[k]);
                }

                // Show loading? type cursor
                document.body.style.cursor = 'wait';
                try {
                    const res = await fetch('', { method: 'POST', body: fd });
                    const json = await res.json();
                    document.body.style.cursor = 'default';
                    if (json.status === 'success') {
                        this.toast('success', json.msg);
                        setTimeout(() => location.reload(), 500); // Reload to reflect
                    } else {
                        this.toast('error', json.msg);
                    }
                } catch (e) {
                    document.body.style.cursor = 'default';
                    this.toast('error', 'Server Error');
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
                container.innerHTML = '<div class="animate-pulse">Loading...</div>';

                if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                    const fd = new FormData();
                    fd.append('download_items', '1');
                    fd.append('paths[]', path);
                    // We fetch blob
                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const blob = await res.blob();
                        const url = URL.createObjectURL(blob);
                        container.innerHTML = `<img src="${url}" class="max-h-full max-w-full rounded shadow-lg">`;
                    } catch (e) { container.innerHTML = 'Error loading image'; }
                } else {
                    const fd = new FormData();
                    fd.append('preview_item', '1');
                    fd.append('item', path);
                    const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                    if (res.status === 'success') {
                        container.innerHTML = `<pre class="text-xs font-mono text-slate-300 p-4 w-full h-full overflow-auto text-left">${res.content}</pre>`;
                    } else {
                        container.innerHTML = res.msg;
                    }
                }
            }

            // UTILS
            toast(type, msg) {
                const el = document.getElementById('toast');
                el.innerText = msg;
                el.className = `fixed bottom-6 right-6 z-[100] px-6 py-3 rounded-xl shadow-2xl flex items-center gap-3 font-bold transition-all duration-300 transform ${type === 'success' ? 'bg-emerald-600' : 'bg-red-600'} text-white translate-y-0 opacity-100`;
                setTimeout(() => el.classList.add('translate-y-20', 'opacity-0'), 3000);
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
            openUpload() { document.getElementById('modal-upload') ? document.getElementById('modal-upload').classList.remove('hidden') : this.handleDragOverlay(); }
            // Note: Original did not have modal-upload defined in HTML provided, relied on drag or maybe I missed it? 
            // Ah, line 465 calls openUpload(). Line 1073 defines it. 
            // The HTML I read has Drag Overlay but maybe not a traditional upload modal. 
            // I will assume Drag Overlay is primary or use a hidden input triggered.
            // Let's rely on standard logic. If modal-upload is missing, trigger drag overlay or file input.
            // For now, I'll direct to drag overlay as fallback.
            handleDragOverlay() { document.getElementById('drag-overlay').classList.remove('hidden'); }

            openCreate() { document.getElementById('modal-create').classList.remove('hidden'); }

            setCreateType(t) {
                this.createType = t;
                document.getElementById('btn-c-file').className = t === 'file' ? 'flex-1 py-1.5 rounded text-sm font-bold bg-blue-600 text-white shadow transition' : 'flex-1 py-1.5 rounded text-sm font-bold text-slate-400 hover:text-white transition';
                document.getElementById('btn-c-folder').className = t === 'folder' ? 'flex-1 py-1.5 rounded text-sm font-bold bg-blue-600 text-white shadow transition' : 'flex-1 py-1.5 rounded text-sm font-bold text-slate-400 hover:text-white transition';
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
                for (let i = 0; i < files.length; i++) fd.append('files[]', files[i]);

                this.toast('success', 'Uploading...');
                try {
                    const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                    if (res.status === 'success') {
                        this.toast('success', res.msg || 'Uploaded successfully');
                        setTimeout(() => location.reload(), 500);
                    } else {
                        console.error('Upload Error:', res);
                        this.toast('error', res.msg || 'Upload failed');
                    }
                } catch (e) {
                    console.error('Fetch Error:', e);
                    this.toast('error', 'Network or Server Error');
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