<?php
/**
 * VIVZON CODE EDITOR - Ace Integration
 */
require_once '../shared/config.php';

if (!isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit;
}

$domain_id = isset($_GET['domain_id']) ? (int) $_GET['domain_id'] : 0;
$file = $_GET['file'] ?? '';

// Helpers (Same as files.php)
function shm_normalize_relative($path)
{
    $path = str_replace(['\\', '//'], '/', $path);
    $path = '/' . ltrim($path, '/');
    $parts = array_filter(explode('/', $path));
    $safe = [];
    foreach ($parts as $part) {
        if ($part === '.')
            continue;
        if ($part === '..')
            array_pop($safe);
        else
            $safe[] = $part;
    }
    return '/' . implode('/', $safe);
}

// Get Domain
$stmt = $pdo->prepare("SELECT * FROM domains WHERE id = ? AND client_id = ?");
$stmt->execute([$domain_id, $_SESSION['cid']]);
$domain = $stmt->fetch();

if (!$domain)
    die("Invalid Domain");

$base_path = rtrim($domain['document_root'] ?? "/var/www/clients/" . $_SESSION['client'] . "/public_html", '/');
$cleaned_file = shm_normalize_relative($file);
$abs_path = $base_path . $cleaned_file;

// Ensure path is safe
if (strpos($abs_path, $base_path) !== 0 || !is_file($abs_path)) {
    die("Invalid File: " . htmlspecialchars($cleaned_file));
}

// SAVE ACTION
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents($abs_path, $_POST['content']);
    $msg = "Saved successfully at " . date("H:i:s");
}

$content = file_get_contents($abs_path);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit
        <?= basename($cleaned_file) ?>
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/ace.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style type="text/css" media="screen">
        #editor {
            position: absolute;
            top: 60px;
            right: 0;
            bottom: 0;
            left: 0;
        }
    </style>
</head>

<body class="bg-slate-900 text-white overflow-hidden">

    <header class="h-[60px] bg-slate-950 flex items-center justify-between px-6 border-b border-slate-800">
        <div class="flex items-center gap-4">
            <a href="files.php?domain_id=<?= $domain_id ?>&path=<?= dirname($cleaned_file) ?>"
                class="p-2 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition">
                <i data-lucide="arrow-left" class="w-5"></i>
            </a>
            <span class="font-mono text-sm text-slate-400">
                <?= $cleaned_file ?>
            </span>
            <?php if ($msg): ?>
                <span class="text-xs bg-emerald-500/20 text-emerald-400 px-3 py-1 rounded-full animate-pulse">
                    <?= $msg ?>
                </span>
            <?php endif; ?>
        </div>
        <button onclick="saveFile()"
            class="bg-blue-600 hover:bg-blue-500 text-white px-6 py-2 rounded-lg font-bold flex items-center gap-2 transition">
            <i data-lucide="save" class="w-4"></i> Save
        </button>
    </header>

    <div id="editor">
        <?= htmlspecialchars($content) ?>
    </div>

    <form id="save-form" method="POST" class="hidden">
        <textarea name="content" id="form-content"></textarea>
    </form>

    <script>
        lucide.createIcons();
        var editor = ace.edit("editor");
        editor.setTheme("ace/theme/one_dark");
        editor.session.setMode("ace/mode/php"); // Auto-detect later?
        editor.setShowPrintMargin(false);
        editor.setOptions({
            fontSize: "14px",
            fontFamily: "'JetBrains Mono', 'Fira Code', monospace"
        });

        function saveFile() {
            document.getElementById('form-content').value = editor.getValue();
            document.getElementById('save-form').submit();
        }

        // Ctrl+S
        document.addEventListener('keydown', e => {
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                saveFile();
            }
        });
    </script>
</body>

</html>