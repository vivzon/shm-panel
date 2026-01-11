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
// Increase execution limits for large uploads/zips (1GB+)
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
            background: #f8fafc;
        }

        .file-row:hover {
            background: #f1f5f9;
            cursor: pointer;
        }

        .file-row.selected {
            background: #e0f2fe;
            border-left: 4px solid #0ea5e9;
        }

        .context-menu {
            position: fixed;
            background: white;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            border-radius: 8px;
            z-index: 100;
            min-width: 180px;
            display: none;
        }

        #selection-toolbar {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            display: none;
            align-items: center;
            gap: 20px;
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.2);
            z-index: 1000;
        }
    </style>
</head>

<body class="flex flex-col h-screen">

    <!-- Header / Breadcrumb -->
    <header class="bg-white border-b px-8 py-4 flex items-center justify-between">
        <div class="flex items-center gap-6">
            <h1 class="text-xl font-bold text-slate-900 flex items-center gap-2"><i data-lucide="folder-tree"
                    class="text-blue-600"></i> File Manager</h1>
            <div class="flex items-center text-sm text-slate-400">
                <a href="?domain_id=<?= $domain_id ?>&path=/" class="hover:text-blue-600">Root</a>
                <?php
                $crumbs = explode('/', trim($current_path, '/'));
                $path_acc = '';
                foreach ($crumbs as $c):
                    if ($c == '')
                        continue;
                    $path_acc .= '/' . $c; ?>
                    <span class="mx-2">/</span><a href="?domain_id=<?= $domain_id ?>&path=<?= $path_acc ?>"
                        class="hover:text-blue-600"><?= $c ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="flex gap-3">
            <button onclick="openModal('upload')"
                class="bg-blue-600 text-white px-4 py-2 rounded-lg font-bold flex gap-2"><i data-lucide="upload"
                    class="w-4"></i> Upload</button>
            <button onclick="openModal('create')"
                class="bg-slate-800 text-white px-4 py-2 rounded-lg font-bold flex gap-2"><i data-lucide="plus"
                    class="w-4"></i> New</button>
        </div>
    </header>

    <!-- Main Content -->
    <div class="flex flex-1 overflow-hidden">

        <!-- Sidebar Domains -->
        <aside class="w-64 bg-white border-r p-6 overflow-y-auto">
            <h3 class="text-xs font-bold text-slate-400 uppercase mb-4">Domains</h3>
            <div class="space-y-1">
                <?php
                $all_doms = $pdo->prepare("SELECT id, domain FROM domains WHERE client_id = ?");
                $all_doms->execute([$user_id]);
                while ($d = $all_doms->fetch()): ?>
                    <a href="?domain_id=<?= $d['id'] ?>"
                        class="flex items-center gap-3 p-3 rounded-xl transition <?= $d['id'] == $domain_id ? 'bg-blue-50 text-blue-600 font-bold' : 'hover:bg-slate-50' ?>">
                        <i data-lucide="globe" class="w-4"></i> <?= $d['domain'] ?>
                    </a>
                <?php endwhile; ?>
            </div>
        </aside>

        <!-- File List -->
        <main class="flex-1 overflow-y-auto p-8" oncontextmenu="return false;">
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase text-slate-400 tracking-widest">
                        <tr>
                            <th class="p-4 w-10 text-center"><input type="checkbox" id="master-check"></th>
                            <th class="p-4">Name</th>
                            <th class="p-4">Size</th>
                            <th class="p-4">Permissions</th>
                            <th class="p-4">Modified</th>
                        </tr>
                    </thead>
                    <tbody id="file-body">
                        <?php if ($current_path != '/'): ?>
                            <tr class="file-row border-b"
                                onclick="window.location='?domain_id=<?= $domain_id ?>&path=<?= dirname($current_path) ?>'">
                                <td class="p-4"></td>
                                <td class="p-4 flex items-center gap-3"><i data-lucide="corner-left-up"
                                        class="w-4 text-slate-400"></i> ..</td>
                                <td colspan="3"></td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($items as $i): ?>
                            <tr class="file-row border-b group" data-path="<?= $i['rel'] ?>"
                                data-type="<?= $i['is_dir'] ? 'dir' : 'file' ?>"
                                ondblclick="location.href='<?= $i['is_dir'] ? "?domain_id=$domain_id&path=" . $i['rel'] : "editor.php?domain_id=$domain_id&file=" . $i['rel'] ?>'">
                                <td class="p-4 text-center"><input type="checkbox" class="file-check"
                                        value="<?= $i['rel'] ?>"></td>
                                <td class="p-4 flex items-center gap-3 font-medium">
                                    <i data-lucide="<?= $i['is_dir'] ? 'folder' : 'file-text' ?>"
                                        class="w-5 <?= $i['is_dir'] ? 'text-amber-400' : 'text-blue-400' ?>"></i>
                                    <?= $i['name'] ?>
                                </td>
                                <td class="p-4 text-slate-500 text-sm"><?= $i['size'] ?></td>
                                <td class="p-4 text-slate-400 font-mono text-xs"><?= $i['perm'] ?></td>
                                <td class="p-4 text-slate-400 text-xs"><?= $i['date'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Multi-Selection Toolbar -->
    <div id="selection-toolbar">
        <span id="select-count" class="font-bold">0 Selected</span>
        <div class="w-px h-6 bg-slate-700"></div>
        <div class="flex gap-4">
            <button onclick="bulkAction('download')" class="flex gap-2 text-emerald-400 hover:text-emerald-300"><i
                    data-lucide="download"></i> Download</button>
            <button onclick="bulkAction('copy')" class="flex gap-2 text-slate-300 hover:text-white"><i
                    data-lucide="copy"></i> Copy</button>
            <button onclick="bulkAction('move')" class="flex gap-2 text-slate-300 hover:text-white"><i
                    data-lucide="move"></i> Move</button>
            <button onclick="bulkAction('delete')" class="flex gap-2 text-red-400 hover:text-red-300"><i
                    data-lucide="trash-2"></i> Delete</button>
            <button onclick="bulkAction('zip')" class="flex gap-2 text-blue-400 hover:text-blue-300"><i
                    data-lucide="file-archive"></i> Zip</button>
        </div>
    </div>

    <!-- Context Menu -->
    <div id="context-menu" class="context-menu flex flex-col py-2">
        <button onclick="ctxAction('open')" class="text-left px-4 py-2 hover:bg-slate-100 flex items-center gap-2"><i data-lucide="folder-open" class="w-4"></i> Open</button>
        <button onclick="ctxAction('download')" class="text-left px-4 py-2 hover:bg-slate-100 flex items-center gap-2"><i data-lucide="download" class="w-4"></i> Download</button>
        <button onclick="ctxAction('rename')" class="text-left px-4 py-2 hover:bg-slate-100 flex items-center gap-2"><i data-lucide="edit-3" class="w-4"></i> Rename</button>
        <button onclick="ctxAction('copy')" class="text-left px-4 py-2 hover:bg-slate-100 flex items-center gap-2"><i data-lucide="copy" class="w-4"></i> Copy</button>
        <button onclick="ctxAction('move')" class="text-left px-4 py-2 hover:bg-slate-100 flex items-center gap-2"><i data-lucide="move" class="w-4"></i> Move</button>
        <button onclick="ctxAction('unzip')" id="ctx-unzip" class="text-left px-4 py-2 hover:bg-slate-100 flex items-center gap-2 hidden"><i data-lucide="file-archive" class="w-4"></i> Extract</button>
        <div class="h-px bg-slate-200 my-1"></div>
        <button onclick="ctxAction('delete')" class="text-left px-4 py-2 hover:bg-red-50 text-red-600 flex items-center gap-2"><i data-lucide="trash-2" class="w-4"></i> Delete</button>
    </div>

    <!-- Modals -->
    <div id="modal-upload" class="fixed inset-0 bg-black/60 hidden flex items-center justify-center z-50">
        <div class="bg-white p-10 rounded-3xl w-full max-w-md">
            <h3 class="text-xl font-bold mb-6">Upload Files (Max 1GB)</h3>
            <div id="drop-zone"
                class="border-2 border-dashed border-slate-200 rounded-2xl p-10 text-center hover:border-blue-500 transition">
                <i data-lucide="cloud-upload" class="w-12 h-12 text-blue-600 mx-auto mb-4"></i>
                <p class="text-sm text-slate-500">Drag files here or click to browse</p>
                <input type="file" id="file-input" multiple class="hidden">
            </div>
            <div id="upload-progress" class="mt-4 hidden">
                <div class="w-full bg-slate-100 h-2 rounded-full">
                    <div id="progress-bar" class="bg-blue-600 h-full w-0 transition-all"></div>
                </div>
            </div>
            <button onclick="closeModal('upload')"
                class="w-full mt-6 py-3 bg-slate-100 rounded-xl font-bold">Cancel</button>
        </div>
    </div>

    <!-- Rename Modal -->
    <div id="modal-rename" class="fixed inset-0 bg-black/60 hidden flex items-center justify-center z-50">
        <form method="POST" class="bg-white p-8 rounded-3xl w-full max-w-sm">
            <h3 class="text-lg font-bold mb-4">Rename Item</h3>
            <input type="hidden" name="domain_id" value="<?= $domain_id ?>">
            <input type="hidden" name="rename_item" value="1">
            <input type="hidden" name="old_name" id="rename-old">
            <input name="new_name" id="rename-new" required class="w-full p-3 bg-slate-50 border rounded-xl mb-4 outline-none focus:border-blue-500">
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('rename')" class="flex-1 py-3 bg-slate-100 rounded-xl font-bold">Cancel</button>
                <button type="submit" class="flex-1 py-3 bg-blue-600 text-white rounded-xl font-bold">Rename</button>
            </div>
        </form>
    </div>

    <!-- Copy/Move Modal -->
    <div id="modal-copymove" class="fixed inset-0 bg-black/60 hidden flex items-center justify-center z-50">
        <form method="POST" class="bg-white p-8 rounded-3xl w-full max-w-md">
            <h3 class="text-lg font-bold mb-4" id="cm-title">Move Items</h3>
            <input type="hidden" name="domain_id" value="<?= $domain_id ?>">
            <input type="hidden" name="copy_move_items" value="1">
            <input type="hidden" name="action" id="cm-action">
            <div id="cm-inputs"></div>
            
            <label class="text-xs font-bold text-slate-500 uppercase mb-2 block">Destination Folder</label>
            <input name="destination" value="<?= $current_path ?>" required class="w-full p-3 bg-slate-50 border rounded-xl mb-6 outline-none focus:border-blue-500">
            
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('copymove')" class="flex-1 py-3 bg-slate-100 rounded-xl font-bold">Cancel</button>
                <button type="submit" class="flex-1 py-3 bg-blue-600 text-white rounded-xl font-bold">Confirm</button>
            </div>
        </form>
    </div>

    <!-- Create Modal -->
    <div id="modal-create" class="fixed inset-0 bg-black/60 hidden flex items-center justify-center z-50">
        <form method="POST" class="bg-white p-8 rounded-3xl w-full max-w-sm">
            <h3 class="text-lg font-bold mb-4">Create New</h3>
            <input type="hidden" name="domain_id" value="<?= $domain_id ?>">
            <input type="hidden" name="create_item" value="1">
            
            <div class="flex gap-4 mb-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="type" value="file" checked class="w-4 h-4 text-blue-600"> <span>File</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="type" value="folder" class="w-4 h-4 text-blue-600"> <span>Folder</span>
                </label>
            </div>

            <input name="name" placeholder="Name" required class="w-full p-3 bg-slate-50 border rounded-xl mb-4 outline-none focus:border-blue-500">
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('create')" class="flex-1 py-3 bg-slate-100 rounded-xl font-bold">Cancel</button>
                <button type="submit" class="flex-1 py-3 bg-blue-600 text-white rounded-xl font-bold">Create</button>
            </div>
        </form>
    </div>

    <form id="bulk-form" method="POST" class="hidden">
        <input type="hidden" name="domain_id" value="<?= $domain_id ?>">
        <div id="bulk-inputs"></div>
    </form>

    <script>
        lucide.createIcons();

        // Modal Controls
        function openModal(id) { document.getElementById('modal-' + id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById('modal-' + id).classList.add('hidden'); }

        // Selection Logic
        const toolbar = document.getElementById('selection-toolbar');
        const countLabel = document.getElementById('select-count');

        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('file-check') || e.target.id === 'master-check') {
                const checks = document.querySelectorAll('.file-check:checked');
                const count = checks.length;

                if (e.target.id === 'master-check') {
                    document.querySelectorAll('.file-check').forEach(c => c.checked = e.target.checked);
                    location.reload(); // Quick refresh to toggle row highlights
                }

                if (count > 0) {
                    toolbar.style.display = 'flex';
                    countLabel.innerText = count + ' Selected';
                } else {
                    toolbar.style.display = 'none';
                }
            }
        });

        // Bulk Actions
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
            if (type === 'zip') { /* handled below */ }

            const form = document.getElementById('bulk-form');
            const inputs = document.getElementById('bulk-inputs');
            inputs.innerHTML = `<input type="hidden" name="${type}_paths" value="1">`;
            checks.forEach(c => inputs.innerHTML += `<input type="hidden" name="paths[]" value="${c.value}">`);
            form.submit();
        }

        // Copy/Move Modal Logic
        function openCopyMove(type, paths) {
            document.getElementById('cm-title').innerText = (type === 'copy' ? 'Copy' : 'Move') + ' Items';
            document.getElementById('cm-action').value = type;
            const inputs = document.getElementById('cm-inputs');
            inputs.innerHTML = '';
            paths.forEach(p => inputs.innerHTML += `<input type="hidden" name="paths[]" value="${p}">`);
            openModal('copymove');
        }

        // Context Menu Logic
        const ctxMenu = document.getElementById('context-menu');
        let currentCtxItem = null;
        let currentCtxType = null;

        document.addEventListener('contextmenu', (e) => {
            const row = e.target.closest('.file-row');
            if (row && row.dataset.path) {
                e.preventDefault();
                currentCtxItem = row.dataset.path;
                currentCtxType = row.dataset.type;
                
                // Show/Hide Unzip
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
            if (action === 'copy' || action === 'move') {
                openCopyMove(action, [currentCtxItem]);
            }
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

        // AJAX Upload
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');

        dropZone.onclick = () => fileInput.click();
        fileInput.onchange = (e) => handleFiles(e.target.files);

        async function handleFiles(files) {
            const formData = new FormData();
            formData.append('upload_files', '1');
            formData.append('domain_id', '<?= $domain_id ?>');
            for (let f of files) formData.append('files[]', f);

            document.getElementById('upload-progress').classList.remove('hidden');
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.upload.onprogress = (e) => {
                const pc = (e.loaded / e.total) * 100;
                document.getElementById('progress-bar').style.width = pc + '%';
            };
            xhr.onload = () => location.reload();
            xhr.send(formData);
        }
    </script>
</body>

</html>