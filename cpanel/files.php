<?php
/**
 * VIVZON FILE MANAGER - Enterprise v5.0
 * Optimized for CPanel Integration
 */
// Config Path (Deployed as index.php in subfolder, so we use absolute to be safe)
require_once '/var/www/panel/shared/config.php';

// Authentication Check
if (!isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['cid'];

// Increase execution limits for large uploads/zips

ini_set('upload_max_filesize', '1024M');
ini_set('post_max_size', '1024M');
ini_set('memory_limit', '1024M');
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
$current_path = isset($_GET['path']) ? shm_normalize_relative($_GET['path']) : '/';

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

$base_path = rtrim($domain['document_root'] ?? "/var/www/clients/" . $_SESSION['client'] . "/public_html", '/');
$full_path = shm_build_path($base_path, $current_path);

// -------- POST ACTIONS --------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = ['status' => 'error', 'msg' => 'Operation Failed'];

    // 1. AJAX UPLOAD
    if (isset($_POST['upload_files'])) {
        foreach ($_FILES['files']['name'] as $key => $name) {
            $target = $full_path . '/' . basename($name);
            move_uploaded_file($_FILES['files']['tmp_name'][$key], $target);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // 2. CREATE FOLDER/FILE
    if (isset($_POST['create_item'])) {
        $name = preg_replace('/[^a-zA-Z0-9\._-]/', '', $_POST['name']);
        $target = $full_path . '/' . $name;
        if ($_POST['type'] == 'folder')
            mkdir($target, 0755);
        else
            file_put_contents($target, '');
        header("Location: ?domain_id=$domain_id&path=$current_path");
        exit;
    }

    // 3. DELETE (SINGLE OR MULTI)
    if (isset($_POST['delete_paths'])) {
        foreach ($_POST['paths'] as $p) {
            $abs = shm_build_path($base_path, $p);
            if ($abs)
                shm_rrmdir($abs);
        }
        header("Location: ?domain_id=$domain_id&path=$current_path");
        exit;
    }

    // 4. ZIP/UNZIP
    if (isset($_POST['zip_paths'])) {
        $zip = new ZipArchive();
        $zip_name = $full_path . '/' . (count($_POST['paths']) > 1 ? 'archive.zip' : basename($_POST['paths'][0]) . '.zip');
        if ($zip->open($zip_name, ZipArchive::CREATE) === TRUE) {
            foreach ($_POST['paths'] as $p) {
                $abs = shm_build_path($base_path, $p);
                if (is_file($abs))
                    $zip->addFile($abs, basename($abs));
            }
            $zip->close();
        }
        header("Location: ?domain_id=$domain_id&path=$current_path");
        exit;
    }

    // 5. RENAME
    if (isset($_POST['rename_item'])) {
        $old = shm_build_path($base_path, $_POST['old_name']);
        $new = shm_build_path($base_path, $_POST['new_name']);
        if ($old && $new)
            rename($old, $new);
        header("Location: ?domain_id=$domain_id&path=$current_path");
        exit;
    }

    // 6. COPY/MOVE
    if (isset($_POST['copy_move_items'])) {
        $action = $_POST['action']; // 'copy' or 'move'
        $dest_folder = shm_build_path($base_path, $_POST['destination']);

        foreach ($_POST['paths'] as $p) {
            $src = shm_build_path($base_path, $p);
            $name = basename($src);
            $dest = $dest_folder . '/' . $name;

            if ($src && $dest_folder) {
                if ($action == 'move')
                    rename($src, $dest);
                else
                    shm_rcopy($src, $dest);
            }
        }
        header("Location: ?domain_id=$domain_id&path=$current_path");
        exit;
    }

    // 7. UNZIP
    if (isset($_POST['unzip_item'])) {
        $zip_file = shm_build_path($base_path, $_POST['item']);
        $zip = new ZipArchive;
        if ($zip->open($zip_file) === TRUE) {
            $zip->extractTo(dirname($zip_file));
            $zip->close();
        }
        header("Location: ?domain_id=$domain_id&path=$current_path");
        exit;
    }

    // 8. DOWNLOAD
    if (isset($_POST['download_items'])) {
        $paths = $_POST['paths'];

        if (count($paths) === 1 && is_file(shm_build_path($base_path, $paths[0]))) {
            // Single File Download
            $file = shm_build_path($base_path, $paths[0]);
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        } else {
            // Multi-File/Folder Zip Download
            $zip_name = 'download_' . date('Ymd_His') . '.zip';
            $tmp_zip = sys_get_temp_dir() . '/' . $zip_name;
            $zip = new ZipArchive();

            if ($zip->open($tmp_zip, ZipArchive::CREATE)) {
                foreach ($paths as $p) {
                    $abs = shm_build_path($base_path, $p);
                    if (is_dir($abs)) {
                        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs), RecursiveIteratorIterator::LEAVES_ONLY);
                        foreach ($files as $name => $file) {
                            if (!$file->isDir()) {
                                $filePath = $file->getRealPath();
                                $relativePath = substr($filePath, strlen($abs) + 1);
                                $zip->addFile($filePath, basename($abs) . '/' . $relativePath);
                            }
                        }
                    } else {
                        $zip->addFile($abs, basename($abs));
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
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['php', 'html', 'css', 'js', 'json', 'txt', 'md', 'xml', 'sql', 'conf', 'sh', 'env'])) {
                // Read first 10KB
                $content = file_get_contents($file, false, NULL, 0, 10240);
                echo json_encode(['status' => 'success', 'type' => 'code', 'content' => htmlspecialchars($content)]);
            } else {
                echo json_encode(['status' => 'error', 'msg' => 'Preview not supported for this file type.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'File not found.']);
        }
        exit;
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
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>File Manager | Vivzon CPanel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #0f172a;
            color: #cbd5e1;
        }

        .glass-panel {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .glass-card {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.7), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
        }

        .file-row:hover {
            background: rgba(30, 41, 59, 0.5);
            cursor: pointer;
        }

        .file-row.selected {
            background: rgba(59, 130, 246, 0.15);
            border-left: 4px solid #3b82f6;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #0f172a;
        }

        ::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }

        .context-menu {
            position: fixed;
            background: #1e293b;
            border: 1px solid #334155;
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.5);
            border-radius: 12px;
            z-index: 100;
            min-width: 200px;
            display: none;
            overflow: hidden;
            padding: 6px;
        }

        .context-menu button {
            color: #cbd5e1;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .context-menu button:hover {
            background: #334155;
            color: white;
        }

        #selection-toolbar {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 12px 30px;
            border-radius: 50px;
            display: none;
            align-items: center;
            gap: 24px;
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.5);
            z-index: 1000;
        }

        input[type="checkbox"] {
            accent-color: #3b82f6;
            width: 16px;
            height: 16px;
            background: #1e293b;
            border-color: #475569;
            accent-color: #3b82f6;
            width: 16px;
            height: 16px;
            background: #1e293b;
            border-color: #475569;
        }

        /* VIEW MODES */
        .view-list .list-header {
            display: grid;
        }

        .view-list #file-list {
            display: block;
        }

        .view-list .file-row {
            display: block;
        }

        .view-list .file-row:hover {
            background: rgba(30, 41, 59, 0.5);
        }

        .view-list .list-layout {
            display: grid;
        }

        .view-list .grid-layout {
            display: none;
        }

        .view-grid .list-header {
            display: none;
        }

        .view-grid #file-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 16px;
            padding: 16px;
        }

        .view-grid .file-row {
            display: flex;
            flex-direction: column;
            background: transparent;
        }

        .view-grid .list-layout {
            display: none;
        }

        .view-grid .grid-layout {
            display: flex;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            background: rgba(30, 41, 59, 0.3);
        }

        .view-grid .grid-layout:hover {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
    </style>
</head>

<body class="flex flex-col h-screen overflow-hidden">

    <!-- Header / Breadcrumb -->
    <header class="glass-panel border-b border-slate-700/50 px-8 py-5 flex items-center justify-between z-20">
        <div class="flex items-center gap-6">
            <h1 class="text-xl font-bold text-white flex items-center gap-3 tracking-tight">
                <div
                    class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center text-white shadow-lg shadow-blue-500/30">
                    <i data-lucide="folder-tree" class="w-5"></i>
                </div>
                File Manager
            </h1>
            <div class="h-6 w-px bg-slate-700"></div>
            <div class="flex items-center text-sm font-mono text-slate-400">
                <a href="?domain_id=<?= $domain_id ?>&path=/"
                    class="hover:text-blue-400 transition bg-slate-800/50 px-2 py-1 rounded">Root</a>
                <?php
                $crumbs = explode('/', trim($current_path, '/'));
                $path_acc = '';
                foreach ($crumbs as $c):
                    if ($c == '')
                        continue;
                    $path_acc .= '/' . $c; ?>
                    <span class="mx-1 text-slate-600">/</span>
                    <a href="?domain_id=<?= $domain_id ?>&path=<?= $path_acc ?>"
                        class="hover:text-blue-400 transition px-1 rounded hover:bg-slate-800/50"><?= $c ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="flex items-center gap-3 bg-slate-800/50 p-1 rounded-xl border border-slate-700/50">
            <div class="relative">
                <i data-lucide="search" class="w-4 h-4 text-slate-500 absolute left-3 top-3"></i>
                <input id="file-search" placeholder="Search files..." onkeyup="filterFiles()"
                    class="bg-transparent pl-9 pr-4 py-2 text-sm text-white placeholder-slate-600 outline-none w-48 focus:w-64 transition-all">
            </div>
            <div class="h-6 w-px bg-slate-700"></div>
            <button onclick="setView('list')" id="btn-list"
                class="p-2 rounded-lg text-white bg-slate-700 shadow-sm transition"><i data-lucide="list"
                    class="w-4"></i></button>
            <button onclick="setView('grid')" id="btn-grid"
                class="p-2 rounded-lg text-slate-400 hover:text-white transition"><i data-lucide="layout-grid"
                    class="w-4"></i></button>
        </div>

        <div class="flex gap-3">
            <button onclick="openModal('upload')"
                class="bg-blue-600 text-white px-5 py-2.5 rounded-xl font-bold flex gap-2 hover:bg-blue-500 transition shadow-lg shadow-blue-600/20 text-sm border border-transparent"><i
                    data-lucide="upload" class="w-4"></i> Upload</button>
            <button onclick="openModal('create')"
                class="glass-card text-slate-300 px-5 py-2.5 rounded-xl font-bold flex gap-2 hover:bg-slate-700 hover:text-white transition text-sm"><i
                    data-lucide="plus" class="w-4"></i> New</button>
        </div>
    </header>

    <!-- Main Content -->
    <div class="flex flex-1 overflow-hidden relative">

        <!-- Sidebar Domains -->
        <aside class="w-72 glass-panel border-r border-slate-700/50 p-6 overflow-y-auto z-10 hidden md:block">
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-6">Active Domains</h3>
            <div class="space-y-2">
                <?php
                $all_doms = $pdo->prepare("SELECT id, domain FROM domains WHERE client_id = ?");
                $all_doms->execute([$user_id]);
                while ($d = $all_doms->fetch()): ?>
                    <a href="?domain_id=<?= $d['id'] ?>"
                        class="flex items-center gap-3 p-3 rounded-xl transition font-medium text-sm <?= $d['id'] == $domain_id ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/20' : 'text-slate-400 hover:bg-slate-800/50 hover:text-white' ?>">
                        <i data-lucide="globe" class="w-4"></i> <?= $d['domain'] ?>
                    </a>
                <?php endwhile; ?>
            </div>
        </aside>

        <!-- File List -->
        <main class="flex-1 overflow-y-auto p-8" oncontextmenu="return false;">
            <div id="file-container" class="glass-card rounded-2xl overflow-hidden shadow-2xl p-2 view-list">
                <!-- Grid/List Header (Visible only in List Mode) -->
                <div
                    class="grid grid-cols-12 gap-4 px-4 py-3 bg-slate-900/50 text-[10px] font-bold uppercase text-slate-500 tracking-widest border-b border-slate-700/50 list-header">
                    <div class="col-span-6 flex items-center gap-3">
                        <input type="checkbox" id="master-check" class="cursor-pointer">
                        <span>Name</span>
                    </div>
                    <div class="col-span-2">Size</div>
                    <div class="col-span-2">Permissions</div>
                    <div class="col-span-2">Modified</div>
                </div>

                <div id="file-list" class="overflow-y-auto max-h-[calc(100vh-250px)] p-2">
                    <?php if ($current_path != '/'): ?>
                        <div class="file-row p-3 rounded-xl flex items-center gap-3 text-slate-400 hover:text-white hover:bg-slate-800/50 transition cursor-pointer mb-1"
                            onclick="window.location='?domain_id=<?= $domain_id ?>&path=<?= dirname($current_path) ?>'">
                            <div class="w-8 flex justify-center"><i data-lucide="corner-left-up" class="w-5"></i></div>
                            <span class="font-bold">..</span>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($items as $i):
                        $icon = $i['is_dir'] ? 'folder' : 'file-text';
                        if (!$i['is_dir']) {
                            $ext = strtolower(pathinfo($i['name'], PATHINFO_EXTENSION));
                            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']))
                                $icon = 'image';
                            if (in_array($ext, ['mp4', 'webm']))
                                $icon = 'film';
                            if (in_array($ext, ['mp3', 'wav']))
                                $icon = 'music';
                            if (in_array($ext, ['zip', 'tar', 'gz', 'rar']))
                                $icon = 'archive';
                            if (in_array($ext, ['php', 'js', 'css', 'html', 'json']))
                                $icon = 'code-2';
                        }
                        ?>
                        <div class="file-row group transition duration-150 relative" data-path="<?= $i['rel'] ?>"
                            data-name="<?= strtolower($i['name']) ?>" data-type="<?= $i['is_dir'] ? 'dir' : 'file' ?>"
                            ondblclick="location.href='<?= $i['is_dir'] ? "?domain_id=$domain_id&path=" . $i['rel'] : "editor.php?domain_id=$domain_id&file=" . $i['rel'] ?>'">

                            <!-- List View Structure -->
                            <div class="list-layout grid grid-cols-12 gap-4 items-center w-full">
                                <div class="col-span-6 flex items-center gap-3 overflow-hidden">
                                    <input type="checkbox" class="file-check cursor-pointer" value="<?= $i['rel'] ?>">
                                    <div
                                        class="p-2 rounded-lg shrink-0 <?= $i['is_dir'] ? 'bg-amber-500/10 text-amber-500' : 'bg-blue-500/10 text-blue-500' ?>">
                                        <i data-lucide="<?= $icon ?>" class="w-5 h-5"></i>
                                    </div>
                                    <span
                                        class="font-medium text-slate-300 group-hover:text-white truncate"><?= $i['name'] ?></span>
                                </div>
                                <div class="col-span-2 text-slate-500 text-sm font-mono"><?= $i['size'] ?></div>
                                <div class="col-span-2 text-slate-500 font-mono text-xs"><?= $i['perm'] ?></div>
                                <div class="col-span-2 text-slate-500 text-xs font-mono"><?= $i['date'] ?></div>
                            </div>

                            <!-- Grid View Structure (Hidden by default CSS) -->
                            <div
                                class="grid-layout flex-col items-center justify-center text-center w-full h-full p-4 rounded-xl hover:bg-slate-800/50 hidden">
                                <div class="absolute top-3 left-3">
                                    <input type="checkbox" class="file-check cursor-pointer" value="<?= $i['rel'] ?>">
                                </div>
                                <div
                                    class="w-16 h-16 mb-3 mx-auto flex items-center justify-center rounded-2xl <?= $i['is_dir'] ? 'bg-amber-500/10 text-amber-500' : 'bg-blue-500/10 text-blue-500' ?>">
                                    <i data-lucide="<?= $icon ?>" class="w-10 h-10"></i>
                                </div>
                                <span
                                    class="text-sm font-medium text-slate-300 group-hover:text-white line-clamp-2 break-all"><?= $i['name'] ?></span>
                                <span class="text-[10px] text-slate-500 mt-1"><?= $i['size'] ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Multi-Selection Toolbar -->
    <div id="selection-toolbar">
        <span id="select-count" class="font-bold text-white">0 Selected</span>
        <div class="w-px h-6 bg-slate-600"></div>
        <div class="flex gap-6 text-sm font-bold">
            <button onclick="bulkAction('download')"
                class="flex gap-2 text-emerald-400 hover:text-emerald-300 transition"><i data-lucide="download"
                    class="w-4"></i> Download</button>
            <button onclick="bulkAction('copy')" class="flex gap-2 text-slate-300 hover:text-white transition"><i
                    data-lucide="copy" class="w-4"></i> Copy</button>
            <button onclick="bulkAction('move')" class="flex gap-2 text-slate-300 hover:text-white transition"><i
                    data-lucide="move" class="w-4"></i> Move</button>
            <button onclick="bulkAction('zip')" class="flex gap-2 text-blue-400 hover:text-blue-300 transition"><i
                    data-lucide="file-archive" class="w-4"></i> Zip</button>
            <button onclick="bulkAction('delete')" class="flex gap-2 text-red-400 hover:text-red-300 transition"><i
                    data-lucide="trash-2" class="w-4"></i> Delete</button>
        </div>
    </div>

    <!-- Context Menu -->
    <div id="context-menu" class="context-menu flex flex-col">
        <button onclick="ctxAction('open')" class="text-left px-4 py-2.5 flex items-center gap-3 text-sm font-medium"><i
                data-lucide="folder-open" class="w-4"></i> Open</button>
        <button onclick="ctxAction('download')"
            class="text-left px-4 py-2.5 flex items-center gap-3 text-sm font-medium"><i data-lucide="download"
                class="w-4"></i> Download</button>
        <button onclick="ctxAction('rename')"
            class="text-left px-4 py-2.5 flex items-center gap-3 text-sm font-medium"><i data-lucide="edit-3"
                class="w-4"></i> Rename</button>
        <button onclick="ctxAction('copy')" class="text-left px-4 py-2.5 flex items-center gap-3 text-sm font-medium"><i
                data-lucide="copy" class="w-4"></i> Copy</button>
        <button onclick="ctxAction('move')" class="text-left px-4 py-2.5 flex items-center gap-3 text-sm font-medium"><i
                data-lucide="move" class="w-4"></i> Move</button>
        <button onclick="ctxAction('unzip')" id="ctx-unzip"
            class="text-left px-4 py-2.5 flex items-center gap-3 text-sm font-medium hidden"><i
                data-lucide="file-archive" class="w-4"></i> Extract</button>
        <div class="h-px bg-slate-700 my-1 mx-2"></div>
        <button onclick="ctxAction('delete')"
            class="text-left px-4 py-2.5 flex items-center gap-3 text-sm font-medium text-red-400 hover:!text-red-300 hover:!bg-red-500/10"><i
                data-lucide="trash-2" class="w-4"></i> Delete</button>
    </div>

    <!-- Modals -->
    <!-- Upload Modal -->
    <div id="modal-upload"
        class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center z-50">
        <div class="glass-card p-10 rounded-3xl w-full max-w-md border border-slate-700">
            <h3 class="text-xl font-bold mb-6 text-white text-center">Upload Files</h3>
            <div id="drop-zone"
                class="border-2 border-dashed border-slate-600 rounded-2xl p-10 text-center hover:border-blue-500 hover:bg-slate-800/50 transition cursor-pointer group">
                <div
                    class="w-16 h-16 bg-blue-500/10 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:scale-110 transition">
                    <i data-lucide="cloud-upload" class="w-8 h-8 text-blue-500"></i>
                </div>
                <p class="text-sm text-slate-400 font-medium">Drag files here or click to browse</p>
                <p class="text-xs text-slate-600 mt-2">Max Size: 1GB</p>
                <input type="file" id="file-input" multiple class="hidden">
            </div>
            <div id="upload-progress" class="mt-6 hidden">
                <div class="flex justify-between text-xs text-slate-400 mb-1">
                    <span>Uploading...</span>
                    <span id="progress-text">0%</span>
                </div>
                <div class="w-full bg-slate-700 h-2 rounded-full overflow-hidden">
                    <div id="progress-bar"
                        class="bg-blue-600 h-full w-0 transition-all shadow-[0_0_10px_rgba(37,99,235,0.5)]"></div>
                </div>
            </div>
            <div class="mt-8 flex gap-3">
                <button onclick="closeModal('upload')"
                    class="flex-1 py-3 bg-slate-800 text-slate-300 rounded-xl font-bold hover:bg-slate-700 transition">Close</button>
            </div>
        </div>
    </div>

    <!-- Rename Modal -->
    <div id="modal-rename"
        class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center z-50">
        <form method="POST" class="glass-card p-10 rounded-3xl w-full max-w-md border border-slate-700">
            <h3 class="text-xl font-bold mb-6 text-white text-center">Rename Item</h3>
            <input type="hidden" name="domain_id" value="<?= $domain_id ?>">
            <input type="hidden" name="rename_item" value="1">
            <input type="hidden" name="old_name" id="rename-old">

            <div class="mb-6">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">New Name</label>
                <input name="new_name" id="rename-new" required
                    class="w-full p-4 bg-slate-900/50 border border-slate-600 rounded-xl outline-none focus:border-blue-500 text-white placeholder-slate-600 transition">
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeModal('rename')"
                    class="flex-1 py-3 bg-slate-800 text-slate-300 rounded-xl font-bold hover:bg-slate-700 transition">Cancel</button>
                <button type="submit"
                    class="flex-1 py-3 bg-blue-600 text-white rounded-xl font-bold hover:bg-blue-500 transition shadow-lg shadow-blue-600/20">Rename</button>
            </div>
        </form>
    </div>

    <!-- Copy/Move Modal -->
    <div id="modal-copymove"
        class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center z-50">
        <form method="POST" class="glass-card p-10 rounded-3xl w-full max-w-md border border-slate-700">
            <h3 class="text-xl font-bold mb-6 text-white text-center" id="cm-title">Move Items</h3>
            <input type="hidden" name="domain_id" value="<?= $domain_id ?>">
            <input type="hidden" name="copy_move_items" value="1">
            <input type="hidden" name="action" id="cm-action">
            <div id="cm-inputs"></div>

            <div class="mb-8">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Destination Folder (Relative
                    to Root)</label>
                <div class="flex items-center bg-slate-900/50 border border-slate-600 rounded-xl overflow-hidden">
                    <span class="pl-4 text-slate-500"><i data-lucide="folder" class="w-4"></i></span>
                    <input name="destination" value="<?= $current_path ?>" required
                        class="w-full p-4 bg-transparent outline-none text-white placeholder-slate-600">
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeModal('copymove')"
                    class="flex-1 py-3 bg-slate-800 text-slate-300 rounded-xl font-bold hover:bg-slate-700 transition">Cancel</button>
                <button type="submit"
                    class="flex-1 py-3 bg-blue-600 text-white rounded-xl font-bold hover:bg-blue-500 transition shadow-lg shadow-blue-600/20">Confirm</button>
            </div>
        </form>
    </div>

    <!-- Create Modal -->
    <div id="modal-create"
        class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center z-50">
        <form method="POST" class="glass-card p-10 rounded-3xl w-full max-w-md border border-slate-700">
            <h3 class="text-xl font-bold mb-6 text-white text-center">Create New Item</h3>
            <input type="hidden" name="domain_id" value="<?= $domain_id ?>">
            <input type="hidden" name="create_item" value="1">

            <div class="flex bg-slate-900/50 p-1 rounded-xl mb-6 border border-slate-700">
                <label class="flex-1 cursor-pointer">
                    <input type="radio" name="type" value="file" checked class="hidden peer">
                    <div
                        class="text-center py-2 rounded-lg text-sm font-bold text-slate-400 peer-checked:bg-blue-600 peer-checked:text-white transition">
                        File</div>
                </label>
                <label class="flex-1 cursor-pointer">
                    <input type="radio" name="type" value="folder" class="hidden peer">
                    <div
                        class="text-center py-2 rounded-lg text-sm font-bold text-slate-400 peer-checked:bg-blue-600 peer-checked:text-white transition">
                        Folder</div>
                </label>
            </div>

            <div class="mb-8">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Item Name</label>
                <input name="name" placeholder="e.g. index.php or images" required
                    class="w-full p-4 bg-slate-900/50 border border-slate-600 rounded-xl outline-none focus:border-blue-500 text-white placeholder-slate-600 transition">
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeModal('create')"
                    class="flex-1 py-3 bg-slate-800 text-slate-300 rounded-xl font-bold hover:bg-slate-700 transition">Cancel</button>
                <button type="submit"
                    class="flex-1 py-3 bg-blue-600 text-white rounded-xl font-bold hover:bg-blue-500 transition shadow-lg shadow-blue-600/20">Create</button>
            </div>
        </form>
    </div>

    <!-- Status Bar -->
    <footer
        class="bg-slate-900 border-t border-slate-700/50 px-6 py-2 flex items-center justify-between text-xs text-slate-500 z-20">
        <div class="flex gap-4">
            <span><?= count($items) ?> items</span>
            <span id="select-stats">0 selected</span>
        </div>
        <div class="font-mono">
            Disk Usage: <?= $DISK ?? 'N/A' ?>
        </div>
    </footer>

    <!-- Preview Modal -->
    <div id="modal-preview"
        class="fixed inset-0 bg-black/90 backdrop-blur-md hidden flex items-center justify-center z-[60]">
        <button onclick="closeModal('preview')" class="absolute top-6 right-6 text-slate-400 hover:text-white"><i
                data-lucide="x" class="w-8 h-8"></i></button>
        <div class="w-full max-w-5xl h-[80vh] flex items-center justify-center p-4">
            <img id="preview-img" class="max-w-full max-h-full rounded-lg shadow-2xl hidden">
            <pre id="preview-code"
                class="w-full h-full bg-slate-900 text-slate-300 p-8 rounded-xl overflow-auto font-mono text-sm border border-slate-700 hidden"></pre>
            <div id="preview-msg" class="text-white text-xl font-bold hidden">Preview not available</div>
        </div>
        <div class="absolute bottom-6 text-center w-full">
            <h3 id="preview-title" class="text-white font-bold text-lg">Filename.jpg</h3>
            <p class="text-slate-400 text-sm">Preview Mode</p>
        </div>
    </div>

    <form id="bulk-form" method="POST" class="hidden">
        <input type="hidden" name="domain_id" value="<?= $domain_id ?>">
        <div id="bulk-inputs"></div>
    </form>

    <script>
        lucide.createIcons();
        function openModal(id) { document.getElementById('modal-' + id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById('modal-' + id).classList.add('hidden'); }

        const toolbar = document.getElementById('selection-toolbar');
        const countLabel = document.getElementById('select-count');

        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('file-check') || e.target.id === 'master-check') {
                const checks = document.querySelectorAll('.file-check:checked');
                const count = checks.length;
                if (e.target.id === 'master-check') {
                    document.querySelectorAll('.file-check').forEach(c => c.checked = e.target.checked);
                    location.reload();
                }
                if (count > 0) {
                    toolbar.style.display = 'flex';
                    countLabel.innerText = count + ' Selected';
                } else {
                    toolbar.style.display = 'none';
                }
            }
        });

        function bulkAction(type) {
            const checks = document.querySelectorAll('.file-check:checked');
            if (checks.length === 0) return;
            if (type === 'download') {
                const form = document.getElementById('bulk-form');
                const inputs = document.getElementById('bulk-inputs');
                inputs.innerHTML = `<input type="hidden" name="download_items" value="1">`;
                checks.forEach(c => inputs.innerHTML += `<input type="hidden" name="paths[]" value="${c.value}">`);
                form.submit();
                return;
            }
            if (type === 'copy' || type === 'move') {
                const paths = Array.from(checks).map(c => c.value);
                openCopyMove(type, paths);
                return;
            }
            if (type === 'delete' && !confirm('Delete selected items?')) return;
            if (type === 'zip') { /* handled via bulk-form implicitly if expanded, but for now reuse logic */ }

            const form = document.getElementById('bulk-form');
            const inputs = document.getElementById('bulk-inputs');
            inputs.innerHTML = `<input type="hidden" name="${type}_paths" value="1">`;
            checks.forEach(c => inputs.innerHTML += `<input type="hidden" name="paths[]" value="${c.value}">`);
            form.submit();
        }

        function openCopyMove(type, paths) {
            document.getElementById('cm-title').innerText = (type === 'copy' ? 'Copy' : 'Move') + ' Items';
            document.getElementById('cm-action').value = type;
            const inputs = document.getElementById('cm-inputs');
            inputs.innerHTML = '';
            paths.forEach(p => inputs.innerHTML += `<input type="hidden" name="paths[]" value="${p}">`);
            openModal('copymove');
        }

        const ctxMenu = document.getElementById('context-menu');
        let currentCtxItem = null;
        let currentCtxType = null;

        document.addEventListener('contextmenu', (e) => {
            const row = e.target.closest('.file-row');
            if (row && row.dataset.path) {
                e.preventDefault();
                currentCtxItem = row.dataset.path;
                currentCtxType = row.dataset.type;
                const isZip = currentCtxItem.endsWith('.zip');
                document.getElementById('ctx-unzip').classList.toggle('hidden', !isZip);
                ctxMenu.style.top = e.clientY + 'px';
                ctxMenu.style.left = e.clientX + 'px';
                ctxMenu.style.display = 'flex';
            } else {
                ctxMenu.style.display = 'none';
            }
        });

        document.addEventListener('click', () => ctxMenu.style.display = 'none');

        function ctxAction(action) {
            if (!currentCtxItem) return;
            if (action === 'open') {
                if (currentCtxType === 'dir') location.href = `?domain_id=<?= $domain_id ?>&path=${currentCtxItem}`;
                else location.href = `editor.php?domain_id=<?= $domain_id ?>&file=${currentCtxItem}`;
            }
            if (action === 'download') {
                const form = document.getElementById('bulk-form');
                form.innerHTML = `<input type="hidden" name="domain_id" value="<?= $domain_id ?>">
                    <input type="hidden" name="download_items" value="1">
                    <input type="hidden" name="paths[]" value="${currentCtxItem}">`;
                form.submit();
            }
            if (action === 'rename') {
                document.getElementById('rename-old').value = currentCtxItem;
                document.getElementById('rename-new').value = currentCtxItem.split('/').pop();
                openModal('rename');
            }
            if (action === 'copy' || action === 'copy') openCopyMove('copy', [currentCtxItem]);
            if (action === 'move') openCopyMove('move', [currentCtxItem]);

            if (action === 'delete') {
                if (confirm('Delete ' + currentCtxItem + '?')) {
                    const form = document.getElementById('bulk-form');
                    form.innerHTML = `<input type="hidden" name="domain_id" value="<?= $domain_id ?>">
                        <input type="hidden" name="delete_paths" value="1">
                        <input type="hidden" name="paths[]" value="${currentCtxItem}">`;
                    form.submit();
                }
            }
            if (action === 'unzip') {
                const form = document.getElementById('bulk-form');
                form.innerHTML = `<input type="hidden" name="domain_id" value="<?= $domain_id ?>">
                    <input type="hidden" name="unzip_item" value="1">
                    <input type="hidden" name="item" value="${currentCtxItem}">`;
                form.submit();
            }
        }

        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');
        dropZone.onclick = () => fileInput.click();
        fileInput.onchange = (e) => handleFiles(e.target.files);

        // VIEW MODES
        function setView(mode) {
            const container = document.getElementById('file-container');
            const btnList = document.getElementById('btn-list');
            const btnGrid = document.getElementById('btn-grid');

            if (mode === 'grid') {
                container.classList.remove('view-list');
                container.classList.add('view-grid');
                btnGrid.classList.add('bg-slate-700', 'text-white', 'shadow-sm');
                btnGrid.classList.remove('text-slate-400', 'hover:text-white');
                btnList.classList.remove('bg-slate-700', 'text-white', 'shadow-sm');
                btnList.classList.add('text-slate-400', 'hover:text-white');
            } else {
                container.classList.remove('view-grid');
                container.classList.add('view-list');
                btnList.classList.add('bg-slate-700', 'text-white', 'shadow-sm');
                btnList.classList.remove('text-slate-400', 'hover:text-white');
                btnGrid.classList.remove('bg-slate-700', 'text-white', 'shadow-sm');
                btnGrid.classList.add('text-slate-400', 'hover:text-white');
            }
            localStorage.setItem('fm_view', mode);
        }

        // Initialize View from Storage
        if (localStorage.getItem('fm_view') === 'grid') setView('grid');

        // SEARCH FILTER
        function filterFiles() {
            const query = document.getElementById('file-search').value.toLowerCase();
            document.querySelectorAll('.file-row').forEach(row => {
                if (row.dataset.name) {
                    const match = row.dataset.name.includes(query);
                    row.classList.toggle('hidden', !match);
                }
            });
        }

        // PREVIEW
        async function openPreview(path) {
            const ext = path.split('.').pop().toLowerCase();
            const modal = document.getElementById('modal-preview');
            const img = document.getElementById('preview-img');
            const code = document.getElementById('preview-code');
            const msg = document.getElementById('preview-msg');
            const title = document.getElementById('preview-title');

            title.innerText = path.split('/').pop();
            img.classList.add('hidden');
            code.classList.add('hidden');
            msg.classList.add('hidden');
            modal.classList.remove('hidden');

            if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) {
                // Image Preview (Direct URL if public, but better via base64 proxy if strict permissions? 
                // Since this is same domain, relative path usage might fail if outside webroot or restricted.
                // WE rely on `download_items` trick or standard web access. 
                // Let's try direct access assuming public_html)

                // Actually, for security and correct path resolution, let's use a blob fetch or just valid URL.
                // Since we are in cPanel, the file might just be in the webroot.
                // Simple trick: We will fail image preview if not web accessible easily, or we can use the Download handler to stream it.
                // Let's use the download handler as src! Smart.

                // Construct download URL
                // We need to fetch it as blob to set src

                try {
                    const fd = new FormData();
                    fd.append('download_items', '1');
                    fd.append('paths[]', path);
                    fd.append('domain_id', '<?= $domain_id ?>');

                    const res = await fetch('', { method: 'POST', body: fd });
                    const blob = await res.blob();
                    img.src = URL.createObjectURL(blob);
                    img.classList.remove('hidden');
                } catch (e) {
                    msg.innerText = "Failed to load image";
                    msg.classList.remove('hidden');
                }

            } else {
                // Code / Text Preview
                const fd = new FormData();
                fd.append('preview_item', '1');
                fd.append('item', path);
                fd.append('domain_id', '<?= $domain_id ?>');

                try {
                    const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                    if (res.status === 'success') {
                        code.innerText = res.content; // It's already htmlspecialchar'd by PHP
                        code.classList.remove('hidden');
                    } else {
                        msg.innerText = res.msg;
                        msg.classList.remove('hidden');
                    }
                } catch (e) {
                    msg.innerText = "Error fetching preview";
                    msg.classList.remove('hidden');
                }
            }
        }

        // CONTEXT MENU UPDATE
        // Update context menu to include "Preview"
        const ctxPreview = document.createElement('button');
        ctxPreview.className = "text-left px-4 py-2.5 flex items-center gap-3 text-sm font-medium";
        ctxPreview.innerHTML = `<i data-lucide="eye" class="w-4"></i> Preview`;
        ctxPreview.onclick = () => ctxAction('preview');
        // Insert as first item
        const ctxFirst = document.querySelector('#context-menu button');
        ctxPreview.classList.add('border-b', 'border-slate-700', 'mb-1', 'pb-1'); // Styling separation
        document.getElementById('context-menu').insertBefore(ctxPreview, ctxFirst);

        // Handle context action
        const oldCtxAction = ctxAction;
        ctxAction = function (action) {
            if (action === 'preview') {
                openPreview(currentCtxItem);
                return;
            }
            oldCtxAction(action);
        }

        // UPDATE STATS ON SELECTION
        const oldChange = document.onchange; // No, it was addEventListener.
        // We need to hook into the selection logic.
        const statsLabel = document.getElementById('select-stats');

        // Helper to update stats
        function updateStats() {
            const checks = document.querySelectorAll('.file-check:checked');
            let totalSize = 0; // Complexity: we don't have size in value.
            // We'd need to parse it from DOM or data attribute. 
            // Let's just show count for now.
            statsLabel.innerText = checks.length + " selected";
        }

        // Add listener to all checks
        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('file-check') || e.target.id === 'master-check') {
                setTimeout(updateStats, 10);
            }
        });

        // Initialize icons for dynamic content
        window.addEventListener('load', () => {
            lucide.createIcons();
        });

        async function handleFiles(files) {
            const formData = new FormData();
            formData.append('upload_files', '1');
            formData.append('domain_id', '<?= $domain_id ?>');
            for (let f of files) formData.append('files[]', f);

            document.getElementById('upload-progress').classList.remove('hidden');
            document.getElementById('progress-text').innerText = "0%";
            document.getElementById('progress-bar').style.width = "0%";

            const xhr = new XMLHttpRequest();
            xhr.upload.addEventListener("progress", (e) => {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    document.getElementById('progress-text').innerText = percent + "%";
                    document.getElementById('progress-bar').style.width = percent + "%";
                }
            });

            xhr.onload = () => {
                if (xhr.status === 200) {
                    location.reload();
                } else {
                    alert("Upload Failed");
                }
            };

            xhr.open("POST", "");
            xhr.send(formData);
        }

    </script>
</body>

</html>