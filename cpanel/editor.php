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
    <title>Edit <?= basename($cleaned_file) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        blue: {
                            50: '#f0f5ff',
                            100: '#e0ebff',
                            200: '#cce0ff',
                            300: '#99c2ff',
                            400: '#66a3ff',
                            500: '#4880ed',
                            600: '#2563eb', /* Primary */
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        },
                        indigo: {
                            50: '#f2f4fb',
                            100: '#e6ebfb',
                            200: '#cdcdfa',
                            300: '#9ea6eb',
                            400: '#6f7ee1',
                            500: '#3f51b5', /* Secondary */
                            600: '#36469b',
                            700: '#2c397e',
                            800: '#242f67',
                            900: '#1f2752',
                        }
                    }
                }
            }
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/ace.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style type="text/css" media="screen">
        #editor {
            position: absolute;
            top: 70px;
            right: 0;
            bottom: 0;
            left: 0;
        }

        .glass-panel {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
    </style>
</head>

<body class="bg-[#0f172a] text-slate-700 overflow-hidden font-sans">

    <header class="h-[70px] glass-panel flex items-center justify-between px-6 z-50 relative">
        <div class="flex items-center gap-4">
            <?php $parent_dir = dirname($cleaned_file);
            if ($parent_dir == '.' || $parent_dir == '\\')
                $parent_dir = '/'; ?>
            <a href="files.php?domain_id=<?= $domain_id ?>&path=<?= $parent_dir ?>"
                class="p-2 hover:bg-white/10 rounded-xl text-slate-600 hover:text-slate-900 transition">
                <i data-lucide="arrow-left" class="w-5"></i>
            </a>
            <div class="flex flex-col">
                <span class="font-bold text-slate-900 text-sm"><?= basename($cleaned_file) ?></span>
                <span class="font-mono text-xs text-slate-600"><?= $cleaned_file ?></span>
            </div>

            <?php if ($msg): ?>
                <span
                    class="ml-4 text-xs bg-emerald-500/20 text-emerald-400 px-3 py-1 rounded-full animate-pulse border border-emerald-500/30">
                    <i data-lucide="check" class="w-3 inline mr-1"></i> <?= $msg ?>
                </span>
            <?php endif; ?>
        </div>
        <button onclick="saveFile()"
            class="bg-blue-600 hover:bg-blue-500 text-slate-900 px-6 py-2.5 rounded-xl font-bold flex items-center gap-2 transition shadow-lg shadow-blue-600/20 text-sm">
            <i data-lucide="save" class="w-4"></i> Save Changes
        </button>
    </header>

    <div id="editor"><?= htmlspecialchars($content) ?></div>

    <form id="save-form" method="POST" class="hidden">
        <textarea name="content" id="form-content"></textarea>
    </form>

    <script>
        lucide.createIcons();
        var editor = ace.edit("editor");
        editor.setTheme("ace/theme/one_dark"); // This matches the dark aesthetics well

        // Auto-detect mode based on extension
        var modelist = ace.require("ace/ext/modelist");
        var filePath = "<?= $cleaned_file ?>";
        // Simple fallback mapping if modelist isn't loaded (CDN issue risk, but usually fine)
        var mode = "ace/mode/php";
        if (filePath.endsWith('.js')) mode = "ace/mode/javascript";
        if (filePath.endsWith('.css')) mode = "ace/mode/css";
        if (filePath.endsWith('.html')) mode = "ace/mode/html";
        if (filePath.endsWith('.json')) mode = "ace/mode/json";

        editor.session.setMode(mode);

        editor.setShowPrintMargin(false);
        editor.setOptions({
            fontSize: "14px",
            fontFamily: "'JetBrains Mono', 'Fira Code', monospace",
            showGutter: true,
            highlightActiveLine: true,
            wrap: true
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


