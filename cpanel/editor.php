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

// Helpers (Same as files.php — includes null-byte protection)
function shm_normalize_relative($path)
{
    // Security: Prevent null byte injection
    $path = str_replace(chr(0), '', $path);
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
$msg_type = "success";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // File size cap: 10MB
    $content_length = strlen($_POST['content'] ?? '');
    if ($content_length > 10 * 1024 * 1024) {
        $msg = "Save failed: Content exceeds 10MB limit";
        $msg_type = "error";
    } elseif (!is_writable($abs_path)) {
        $msg = "Save failed: File is not writable";
        $msg_type = "error";
    } else {
        $bytes = file_put_contents($abs_path, $_POST['content']);
        if ($bytes === false) {
            $msg = "Save failed: Could not write to file";
            $msg_type = "error";
        } else {
            $msg = "Saved successfully at " . date("H:i:s");
            $msg_type = "success";
        }
    }
}

$content = file_get_contents($abs_path);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title>Edit <?= htmlspecialchars(basename($cleaned_file)) ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/ace.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style type="text/css" media="screen">
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: .5; }
        }

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
                style="padding: 0.5rem; border-radius: 0.75rem; color: #94a3b8; transition: all 0.2s; text-decoration: none;"
                onmouseover="this.style.backgroundColor='rgba(255,255,255,0.1)'; this.style.color='#e2e8f0'"
                onmouseout="this.style.backgroundColor='transparent'; this.style.color='#94a3b8'">
                <i data-lucide="arrow-left" style="width: 1.25rem; height: 1.25rem;"></i>
            </a>
            <div style="display: flex; flex-direction: column;">
                <span
                    style="font-weight: 500; color: #e2e8f0; font-size: 0.875rem;"><?= htmlspecialchars(basename($cleaned_file)) ?></span>
                <span style="font-family: monospace; font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($cleaned_file) ?></span>
            </div>

            <?php if ($msg): ?>
                <?php if ($msg_type === 'success'): ?>
                    <span
                        style="margin-left: 1rem; font-size: 0.75rem; background-color: rgba(16, 185, 129, 0.2); color: #34d399; padding: 0.25rem 0.75rem; border-radius: 9999px; border: 1px solid rgba(16, 185, 129, 0.3); animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; display: inline-flex; align-items: center;">
                        <i data-lucide="check" style="width: 0.75rem; height: 0.75rem; margin-right: 0.25rem;"></i> <?= htmlspecialchars($msg) ?>
                    </span>
                <?php else: ?>
                    <span
                        style="margin-left: 1rem; font-size: 0.75rem; background-color: rgba(239, 68, 68, 0.2); color: #f87171; padding: 0.25rem 0.75rem; border-radius: 9999px; border: 1px solid rgba(239, 68, 68, 0.3); display: inline-flex; align-items: center;">
                        <i data-lucide="alert-circle" style="width: 0.75rem; height: 0.75rem; margin-right: 0.25rem;"></i> <?= htmlspecialchars($msg) ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <button onclick="saveFile()"
            style="background-color: #2563eb; color: white; padding: 0.625rem 1.5rem; border-radius: 0.75rem; font-weight: 500; display: flex; align-items: center; gap: 0.5rem; transition: background-color 0.2s, box-shadow 0.2s; font-size: 0.875rem; border: none; cursor: pointer; box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.2);"
            onmouseover="this.style.backgroundColor='#3b82f6'" onmouseout="this.style.backgroundColor='#2563eb'">
            <i data-lucide="save" style="width: 1rem; height: 1rem;"></i> Save Changes
        </button>
    </header>

    <div id="editor"><?= htmlspecialchars($content) ?></div>

    <form id="save-form" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <textarea name="content" id="form-content"></textarea>
    </form>

    <script>
        lucide.createIcons();
        var editor = ace.edit("editor");
        editor.setTheme("ace/theme/one_dark");

        // Auto-detect mode based on file extension
        var filePath = "<?= addslashes($cleaned_file) ?>";
        var ext = filePath.split('.').pop().toLowerCase();

        var modeMap = {
            'php': 'ace/mode/php',
            'js': 'ace/mode/javascript',
            'ts': 'ace/mode/typescript',
            'css': 'ace/mode/css',
            'html': 'ace/mode/html',
            'htm': 'ace/mode/html',
            'json': 'ace/mode/json',
            'xml': 'ace/mode/xml',
            'sql': 'ace/mode/sql',
            'md': 'ace/mode/markdown',
            'txt': 'ace/mode/text',
            'sh': 'ace/mode/sh',
            'ini': 'ace/mode/ini',
            'conf': 'ace/mode/apache_conf',
            'env': 'ace/mode/ini',
            'htaccess': 'ace/mode/apache_conf',
            'py': 'ace/mode/python',
            'yaml': 'ace/mode/yaml',
            'yml': 'ace/mode/yaml',
        };

        var mode = modeMap[ext] || 'ace/mode/text';
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