<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    try {
        verify_csrf();
        if ($_POST['ajax_action'] == 'execute_command') {
            $cmd = $_POST['command'];
            
            // Execute command and capture both stdout and stderr
            $output = shell_exec($cmd . ' 2>&1');
            
            echo json_encode(['status' => 'success', 'output' => $output]);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

include 'layout/header.php';
?>

<!-- Add Fira Code for terminal font -->
<link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;700&display=swap" rel="stylesheet">

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); font-family: var(--font-heading);">
        Server Terminal
    </h2>
</div>

<div class="glass-card animate-slide-up hover-glow" style="padding: 1rem; border-radius: 1rem; display: flex; flex-direction: column; height: 65vh; background: #0f172a; border: 1px solid #1e293b; box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.5);">
    
    <!-- Output Area -->
    <div id="terminal-output" style="flex: 1; overflow-y: auto; color: #a5b4fc; font-family: 'Fira Code', monospace; font-size: 0.875rem; white-space: pre-wrap; word-break: break-all; padding-bottom: 1rem; margin-bottom: 1rem; border-bottom: 1px dashed #334155; scroll-behavior: smooth;" class="custom-scrollbar"></div>
    
    <!-- Input Form -->
    <form id="terminal-form" onsubmit="executeCommand(event)" style="display: flex; gap: 0.5rem; position: relative; margin-top: auto;">
        <span style="color: #10b981; font-family: 'Fira Code', monospace; font-weight: 700; position: absolute; left: 1rem; top: 0.75rem; z-index: 10;">root@server:~#</span>
        <input type="text" id="terminal-input" required autocomplete="off" spellcheck="false" class="form-input" style="flex: 1; padding: 0.75rem 1rem 0.75rem 10.5rem; background: #1e293b; color: #f8fafc; border: 1px solid #334155; border-radius: 0.5rem; font-family: 'Fira Code', monospace; font-size: 0.875rem; transition: border-color var(--transition-fast);" onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#334155'">
        <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem; border-radius: 0.5rem; display: flex; align-items: center; gap: 0.5rem; transition: background-color var(--transition-fast);"><i data-lucide="terminal-square" style="width: 1rem; height: 1rem;"></i> Execute</button>
    </form>
</div>

<script>
    const outputDiv = document.getElementById('terminal-output');
    const inputField = document.getElementById('terminal-input');
    const cmdHistory = [];
    let historyIndex = -1;

    function appendOutput(html) {
        outputDiv.innerHTML += html;
        outputDiv.scrollTop = outputDiv.scrollHeight;
    }

    appendOutput("<div style='color:#38bdf8;margin-bottom:1rem;'><strong style='color:#7dd3fc;'>SHM Panel Web Terminal v1.0</strong><br>Type a command to execute it on the server.<br><em style='color:#94a3b8;'>Note: Interactive commands like 'nano', 'top', or 'htop' are not supported. Use 'clear' to reset.</em></div>");

    // History Nav handles Up/Down arrows
    inputField.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (historyIndex < cmdHistory.length - 1) {
                historyIndex++;
                inputField.value = cmdHistory[cmdHistory.length - 1 - historyIndex];
            }
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (historyIndex > 0) {
                historyIndex--;
                inputField.value = cmdHistory[cmdHistory.length - 1 - historyIndex];
            } else if (historyIndex === 0) {
                historyIndex = -1;
                inputField.value = '';
            }
        }
    });

    async function executeCommand(e) {
        e.preventDefault();
        const cmd = inputField.value.trim();
        if (!cmd) return;
        
        // Add to history
        if (cmdHistory[cmdHistory.length - 1] !== cmd) {
            cmdHistory.push(cmd);
        }
        historyIndex = -1;

        if (cmd.toLowerCase() === 'clear') {
            outputDiv.innerHTML = '';
            inputField.value = '';
            return;
        }

        appendOutput(`<div style="color: #10b981; margin-top: 0.75rem; display: flex; gap: 0.5rem;"><span>root@server:~#</span><span style="color: #f1f5f9;">${cmd.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</span></div>`);
        
        inputField.value = '';
        inputField.disabled = true;
        const btn = document.querySelector('#terminal-form button');
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader" class="animate-spin" style="width: 1rem; height: 1rem;"></i> Running';
        lucide.createIcons();

        const fd = new FormData();
        fd.append('ajax_action', 'execute_command');
        fd.append('command', cmd);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                const outHtml = res.output ? res.output.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : '';
                appendOutput(`<div style="color: #cbd5e1; margin-top: 0.25rem; padding-left: 0.25rem;">${outHtml || '<span style="color:#64748b;">(no output)</span>'}</div>`);
            } else {
                appendOutput(`<div style="color: #ef4444; margin-top: 0.25rem; padding-left: 0.25rem;">[Error] ${res.msg.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>`);
            }
        } catch (error) {
            appendOutput(`<div style="color: #ef4444; margin-top: 0.25rem; padding-left: 0.25rem;">[Connection Failed] The command could not be dispatched or the server timed out.</div>`);
        }
        
        inputField.disabled = false;
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="terminal-square" style="width: 1rem; height: 1rem;"></i> Execute';
        lucide.createIcons();
        inputField.focus();
    }

    // Auto focus on load
    document.addEventListener('DOMContentLoaded', () => {
        inputField.focus();
    });
</script>

<?php include 'layout/footer.php'; ?>
