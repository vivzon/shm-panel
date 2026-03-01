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
    @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: .5; }
    }
    </style>
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

<body style="background-color: #0f172a; color: #334155; overflow: hidden; font-family: sans-serif; margin: 0;">

    <header class="glass-panel"
        style="height: 70px; display: flex; align-items: center; justify-content: space-between; padding: 0 1.5rem; z-index: 50; position: relative;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <?php $parent_dir = dirname($cleaned_file);
            if ($parent_dir == '.' || $parent_dir == '\\')
                $parent_dir = '/'; ?>
            <a href="files.php?domain_id=<?= $domain_id ?>&path=<?= $parent_dir ?>"
                style="padding: 0.5rem; border-radius: 0.75rem; color: #334155; transition: all 0.2s; text-decoration: none;"
                onmouseover="this.style.backgroundColor='rgba(255,255,255,0.1)'; this.style.color='#0f172a'"
                onmouseout="this.style.backgroundColor='transparent'; this.style.color='#334155'">
                <i data-lucide="arrow-left" style="width: 1.25rem; height: 1.25rem;"></i>
            </a>
            <div style="display: flex; flex-direction: column;">
                <span
                    style="font-weight: 700; color: #0f172a; font-size: 0.875rem;"><?= basename($cleaned_file) ?></span>
                <span style="font-family: monospace; font-size: 0.75rem; color: #334155;"><?= $cleaned_file ?></span>
            </div>

            <?php if ($msg): ?>
                <span
                    style="margin-left: 1rem; font-size: 0.75rem; background-color: rgba(16, 185, 129, 0.2); color: #34d399; padding: 0.25rem 0.75rem; border-radius: 9999px; border: 1px solid rgba(16, 185, 129, 0.3); animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; display: inline-flex; align-items: center;">
                    <i data-lucide="check" style="width: 0.75rem; height: 0.75rem; margin-right: 0.25rem;"></i> <?= $msg ?>
                </span>
            <?php endif; ?>
        </div>
        <button onclick="saveFile()"
            style="background-color: #2563eb; color: #0f172a; padding: 0.625rem 1.5rem; border-radius: 0.75rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; transition: background-color 0.2s, box-shadow 0.2s; font-size: 0.875rem; border: none; cursor: pointer; box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.2);"
            onmouseover="this.style.backgroundColor='#3b82f6'" onmouseout="this.style.backgroundColor='#2563eb'">
            <i data-lucide="save" style="width: 1rem; height: 1rem;"></i> Save Changes
        </button>
    </header>

    <div id="editor"><?= htmlspecialchars($content) ?></div>

    <form id="save-form" method="POST" style="display: none;">
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