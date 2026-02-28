<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Optional logic to handle saves via AJAX
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    try {
        verify_csrf();
        if ($_POST['ajax_action'] == 'save_config') {
            $cmd = "set-server-config " .
                escapeshellarg($_POST['nginx_client_max_body_size']) . " " .
                escapeshellarg($_POST['nginx_client_body_timeout']) . " " .
                escapeshellarg($_POST['nginx_send_timeout']) . " " .
                escapeshellarg($_POST['fcgi_read_timeout']) . " " .
                escapeshellarg($_POST['fcgi_send_timeout']) . " " .
                escapeshellarg($_POST['proxy_read_timeout']) . " " .
                escapeshellarg($_POST['php_version']) . " " .
                escapeshellarg($_POST['php_upload_max_filesize']) . " " .
                escapeshellarg($_POST['php_post_max_size']) . " " .
                escapeshellarg($_POST['php_memory_limit']) . " " .
                escapeshellarg($_POST['php_max_execution_time']) . " " .
                escapeshellarg($_POST['php_max_input_time']) . " " .
                escapeshellarg($_POST['php_default_socket_timeout']);
            cmd($cmd);
            echo json_encode(['status' => 'success', 'msg' => 'Configuration saved successfully! Please restart services to apply.']);
            exit;
        }
        if ($_POST['ajax_action'] == 'restart_services') {
            cmd("reload-all-services");
            echo json_encode(['status' => 'success', 'msg' => 'Services restarted successfully!']);
            exit;
        }
        if ($_POST['ajax_action'] == 'test_config') {
            ob_start();
            cmd("test-config");
            $out = ob_get_clean();
            $out = trim($out);
            if ($out == 'OK') {
                echo json_encode(['status' => 'success', 'msg' => 'Syntax OK']);
            } else {
                echo json_encode(['status' => 'error', 'msg' => $out ?: 'Configuration test failed.']);
            }
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

<!-- Add Fira Code for code blocks -->
<link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
<style>
    .font-fira {
        font-family: 'Fira Code', monospace;
    }

    /* Range Slider Styling */
    input[type=range] {
        -webkit-appearance: none;
        width: 100%;
        background: transparent;
    }

    input[type=range]::-webkit-slider-thumb {
        -webkit-appearance: none;
        height: 16px;
        width: 16px;
        border-radius: 50%;
        background: #3b82f6;
        cursor: pointer;
        margin-top: -6px;
        box-shadow: 0 0 10px rgba(59, 130, 246, 0.5);
    }

    input[type=range]::-webkit-slider-runnable-track {
        width: 100%;
        height: 4px;
        cursor: pointer;
        background: #334155;
        border-radius: 2px;
    }

    /* Toggle switch */
    .toggle-checkbox:checked {
        right: 0;
        border-color: #3b82f6;
    }

    .toggle-checkbox:checked+.toggle-label {
        background-color: #3b82f6;
    }

    .code-highlight {
        color: #60a5fa;
        background-color: rgba(96, 165, 250, 0.1);
        padding: 0 0.25rem;
        border-radius: 2px;
        transition: background-color 0.5s;
    }

    .code-highlight.pulse {
        background-color: rgba(96, 165, 250, 0.4);
    }
</style>

<div class="max-w-7xl mx-auto w-full flex flex-col lg:flex-row gap-8">

    <!-- Left Column: Forms -->
    <div class="flex-1 space-y-6">

        <!-- Header area -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-2xl font-bold text-white font-heading">Server Config Manager</h2>
                <p class="text-slate-400 text-sm">Manage Nginx, PHP and Upload Settings</p>
            </div>

            <div class="flex items-center gap-3">
                <div id="status-badge"
                    class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-bold uppercase tracking-wider">
                    <div class="w-2 h-2 rounded-full bg-emerald-400"></div>
                    <span id="status-text">Running</span>
                </div>

                <button onclick="saveChanges()"
                    class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-2 rounded-xl font-bold shadow-lg shadow-blue-500/20 transition flex items-center gap-2 text-sm">
                    <i data-lucide="save" class="w-4 h-4"></i> Save
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- Nginx Upload Settings -->
            <div class="glass-card p-6">
                <div class="flex items-center gap-3 mb-6 border-b border-slate-700 pb-4">
                    <div class="w-8 h-8 rounded-lg bg-blue-500/20 flex items-center justify-center text-blue-400">
                        <i data-lucide="server" class="w-4 h-4"></i>
                    </div>
                    <h3 class="font-bold text-white">Nginx Upload Settings</h3>
                </div>

                <div class="mb-5 flex items-center justify-between">
                    <label class="text-sm font-medium text-slate-300">Unlimited Upload Size</label>
                    <div
                        class="relative inline-block w-10 mr-2 align-middle select-none transition duration-200 ease-in">
                        <input type="checkbox" id="toggleUnlimited" onchange="toggleUnlimitedUploads()"
                            class="toggle-checkbox absolute block w-5 h-5 rounded-full bg-white border-4 border-slate-700 appearance-none cursor-pointer transition-transform duration-200 ease-in-out z-10" />
                        <label for="toggleUnlimited"
                            class="toggle-label block overflow-hidden h-5 rounded-full bg-slate-700 cursor-pointer"></label>
                    </div>
                </div>

                <div class="space-y-4">
                    <div id="grp-client-max-body">
                        <label class="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wide">Client Max
                            Body Size <span
                                class="text-slate-500 normal-case tracking-normal ml-1">client_max_body_size</span></label>
                        <div class="flex">
                            <input type="number" id="nginx_client_max_body_size" value="50" oninput="updatePreview()"
                                class="w-full bg-slate-900/50 border border-slate-700 border-r-0 rounded-l-xl p-2.5 outline-none focus:border-blue-500 text-white text-sm transition">
                            <div
                                class="bg-slate-800 border border-slate-700 border-l-0 rounded-r-xl px-4 flex items-center text-slate-400 text-sm font-medium">
                                MB</div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wide">Client Body
                            Timeout</label>
                        <div class="flex">
                            <input type="number" id="nginx_client_body_timeout" value="60" oninput="updatePreview()"
                                class="w-full bg-slate-900/50 border border-slate-700 border-r-0 rounded-l-xl p-2.5 outline-none focus:border-blue-500 text-white text-sm transition">
                            <div
                                class="bg-slate-800 border border-slate-700 border-l-0 rounded-r-xl px-4 flex items-center text-slate-400 text-sm font-medium">
                                s</div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wide">Send
                            Timeout</label>
                        <div class="flex">
                            <input type="number" id="nginx_send_timeout" value="60" oninput="updatePreview()"
                                class="w-full bg-slate-900/50 border border-slate-700 border-r-0 rounded-l-xl p-2.5 outline-none focus:border-blue-500 text-white text-sm transition">
                            <div
                                class="bg-slate-800 border border-slate-700 border-l-0 rounded-r-xl px-4 flex items-center text-slate-400 text-sm font-medium">
                                s</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FastCGI Timeout Settings -->
            <div class="glass-card p-6">
                <div class="flex items-center gap-3 mb-6 border-b border-slate-700 pb-4">
                    <div class="w-8 h-8 rounded-lg bg-indigo-500/20 flex items-center justify-center text-indigo-400">
                        <i data-lucide="network" class="w-4 h-4"></i>
                    </div>
                    <h3 class="font-bold text-white">FastCGI & Proxy</h3>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wide">FastCGI Read
                            Timeout</label>
                        <div class="flex">
                            <input type="number" id="fcgi_read_timeout" value="60" oninput="updatePreview()"
                                class="w-full bg-slate-900/50 border border-slate-700 border-r-0 rounded-l-xl p-2.5 outline-none focus:border-blue-500 text-white text-sm transition">
                            <div
                                class="bg-slate-800 border border-slate-700 border-l-0 rounded-r-xl px-4 flex items-center text-slate-400 text-sm font-medium">
                                s</div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wide">FastCGI Send
                            Timeout</label>
                        <div class="flex">
                            <input type="number" id="fcgi_send_timeout" value="60" oninput="updatePreview()"
                                class="w-full bg-slate-900/50 border border-slate-700 border-r-0 rounded-l-xl p-2.5 outline-none focus:border-blue-500 text-white text-sm transition">
                            <div
                                class="bg-slate-800 border border-slate-700 border-l-0 rounded-r-xl px-4 flex items-center text-slate-400 text-sm font-medium">
                                s</div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wide">Proxy Read
                            Timeout</label>
                        <div class="flex">
                            <input type="number" id="proxy_read_timeout" value="60" oninput="updatePreview()"
                                class="w-full bg-slate-900/50 border border-slate-700 border-r-0 rounded-l-xl p-2.5 outline-none focus:border-blue-500 text-white text-sm transition">
                            <div
                                class="bg-slate-800 border border-slate-700 border-l-0 rounded-r-xl px-4 flex items-center text-slate-400 text-sm font-medium">
                                s</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PHP Configuration -->
            <div class="glass-card p-6 md:col-span-2">
                <div class="flex items-center gap-3 mb-6 border-b border-slate-700 pb-4">
                    <div class="w-8 h-8 rounded-lg bg-pink-500/20 flex items-center justify-center text-pink-400">
                        <i data-lucide="file-code" class="w-4 h-4"></i>
                    </div>
                    <h3 class="font-bold text-white">PHP Configuration</h3>

                    <div class="ml-auto">
                        <select id="php_version" onchange="updatePreview()"
                            class="bg-slate-900/50 border border-slate-700 rounded-lg px-3 py-1.5 text-sm text-slate-300 outline-none focus:border-blue-500">
                            <option value="8.3">PHP 8.3 (Default)</option>
                            <option value="8.2">PHP 8.2</option>
                            <option value="8.1">PHP 8.1</option>
                            <option value="8.0">PHP 8.0</option>
                            <option value="7.4">PHP 7.4</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wide">Upload
                                Max Filesize</label>
                            <div class="flex">
                                <input type="number" id="php_upload_max_filesize" value="50" oninput="updatePreview()"
                                    class="w-full bg-slate-900/50 border border-slate-700 border-r-0 rounded-l-xl p-2.5 outline-none focus:border-blue-500 text-white text-sm transition">
                                <div
                                    class="bg-slate-800 border border-slate-700 border-l-0 rounded-r-xl px-4 flex items-center text-slate-400 text-sm font-medium">
                                    M</div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wide">Post Max
                                Size</label>
                            <div class="flex">
                                <input type="number" id="php_post_max_size" value="64" oninput="updatePreview()"
                                    class="w-full bg-slate-900/50 border border-slate-700 border-r-0 rounded-l-xl p-2.5 outline-none focus:border-blue-500 text-white text-sm transition">
                                <div
                                    class="bg-slate-800 border border-slate-700 border-l-0 rounded-r-xl px-4 flex items-center text-slate-400 text-sm font-medium">
                                    M</div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wide">Memory
                                Limit</label>
                            <div class="flex">
                                <input type="number" id="php_memory_limit" value="256" oninput="updatePreview()"
                                    class="w-full bg-slate-900/50 border border-slate-700 border-r-0 rounded-l-xl p-2.5 outline-none focus:border-blue-500 text-white text-sm transition">
                                <div
                                    class="bg-slate-800 border border-slate-700 border-l-0 rounded-r-xl px-4 flex items-center text-slate-400 text-sm font-medium">
                                    M</div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <div>
                            <div class="flex justify-between mb-1">
                                <label class="text-xs font-bold text-slate-400 uppercase tracking-wide">Max Execution
                                    Time</label>
                                <span class="text-white text-sm font-fira bg-slate-800 px-2 py-0.5 rounded"
                                    id="php_max_execution_time_val">60s</span>
                            </div>
                            <input type="range" id="php_max_execution_time" min="30" max="600" step="30" value="60"
                                oninput="updateRange('php_max_execution_time_val', this.value); updatePreview()">
                        </div>

                        <div>
                            <div class="flex justify-between mb-1">
                                <label class="text-xs font-bold text-slate-400 uppercase tracking-wide">Max Input
                                    Time</label>
                                <span class="text-white text-sm font-fira bg-slate-800 px-2 py-0.5 rounded"
                                    id="php_max_input_time_val">60s</span>
                            </div>
                            <input type="range" id="php_max_input_time" min="60" max="600" step="60" value="60"
                                oninput="updateRange('php_max_input_time_val', this.value); updatePreview()">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-400 mb-1 uppercase tracking-wide">Default
                                Socket Timeout</label>
                            <div class="flex">
                                <input type="number" id="php_default_socket_timeout" value="60"
                                    oninput="updatePreview()"
                                    class="w-full bg-slate-900/50 border border-slate-700 border-r-0 rounded-l-xl p-2.5 outline-none focus:border-blue-500 text-white text-sm transition">
                                <div
                                    class="bg-slate-800 border border-slate-700 border-l-0 rounded-r-xl px-4 flex items-center text-slate-400 text-sm font-medium">
                                    s</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Operations & Presets -->
            <div class="glass-card p-6 md:col-span-2">
                <h3 class="font-bold text-white mb-4">Operations & Presets</h3>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <button onclick="applyPreset('default', this)"
                        class="preset-btn p-4 rounded-xl border border-slate-700 bg-slate-800/30 hover:bg-slate-800 hover:border-blue-500 transition text-left group">
                        <div class="text-white font-bold text-sm mb-1 group-hover:text-blue-400">Default</div>
                        <div class="text-slate-500 text-xs">Standard (50MB)</div>
                    </button>
                    <button onclick="applyPreset('large', this)"
                        class="preset-btn p-4 rounded-xl border border-slate-700 bg-slate-800/30 hover:bg-slate-800 hover:border-blue-500 transition text-left group">
                        <div class="text-white font-bold text-sm mb-1 group-hover:text-blue-400">Large Media</div>
                        <div class="text-slate-500 text-xs">Videos (500MB)</div>
                    </button>
                    <button onclick="applyPreset('backup', this)"
                        class="preset-btn p-4 rounded-xl border border-slate-700 bg-slate-800/30 hover:bg-slate-800 hover:border-blue-500 transition text-left group">
                        <div class="text-white font-bold text-sm mb-1 group-hover:text-blue-400">Backup Mode</div>
                        <div class="text-slate-500 text-xs">Massive (2GB)</div>
                    </button>
                    <button onclick="applyPreset('unlimited', this)"
                        class="preset-btn p-4 rounded-xl border border-slate-700 bg-slate-800/30 hover:bg-slate-800 hover:border-blue-500 transition text-left group">
                        <div class="text-white font-bold text-sm mb-1 group-hover:text-blue-400">Unlimited</div>
                        <div class="text-slate-500 text-xs">No Limits</div>
                    </button>
                </div>

                <div class="flex flex-wrap gap-3 pt-6 border-t border-slate-700/50">
                    <button onclick="simulateAction('restart_services', 'Restarting Nginx & PHP-FPM...', true)"
                        class="bg-indigo-600/20 text-indigo-400 border border-indigo-500/30 hover:bg-indigo-600 hover:text-white px-4 py-2 rounded-xl text-sm font-bold transition flex items-center gap-2">
                        <i data-lucide="refresh-cw" class="w-4 h-4"></i> Restart Services
                    </button>
                    <button onclick="simulateAction('test_config', 'Testing Configuration Syntax...', false)"
                        class="bg-slate-800 text-slate-300 border border-slate-700 hover:bg-slate-700 hover:text-white px-4 py-2 rounded-xl text-sm font-bold transition flex items-center gap-2">
                        <i data-lucide="check-circle" class="w-4 h-4"></i> Test Syntax
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- Right Column: Live Preview Panels -->
    <div class="w-full lg:w-96 flex flex-col gap-4">

        <!-- Tabs -->
        <div class="flex gap-2 p-1 bg-slate-900/80 rounded-xl border border-slate-800">
            <button onclick="switchPreview('nginx', this)"
                class="preview-tab active flex-1 py-2 rounded-lg text-sm font-bold transition bg-slate-800 text-white shadow-sm">
                nginx.conf
            </button>
            <button onclick="switchPreview('php', this)"
                class="preview-tab flex-1 py-2 rounded-lg text-sm font-bold text-slate-500 hover:text-slate-300 transition">
                php.ini
            </button>
        </div>

        <!-- Code Windows -->
        <div class="glass-card flex-1 flex flex-col overflow-hidden min-h-[500px]">
            <div class="px-4 py-3 border-b border-slate-800 bg-slate-900/50 flex justify-between items-center">
                <div class="flex items-center gap-2 text-slate-300 text-xs font-fira" id="previewTitle">
                    <i data-lucide="file-code" class="w-3.5 h-3.5"></i> /etc/nginx/nginx.conf
                </div>
                <div class="text-emerald-500 text-[10px] uppercase font-bold tracking-widest flex items-center gap-1">
                    <i data-lucide="check-circle-2" class="w-3 h-3"></i> Valid
                </div>
            </div>

            <div class="flex-1 bg-slate-950/80 p-4 overflow-y-auto custom-scrollbar">
                <pre id="preview-nginx"
                    class="font-fira text-xs leading-relaxed text-slate-400 whitespace-pre-wrap word-break"></pre>
                <pre id="preview-php"
                    class="font-fira text-xs leading-relaxed text-slate-400 whitespace-pre-wrap word-break hidden"></pre>
            </div>
        </div>

    </div>

</div>

<?php include 'layout/footer.php'; ?>

<script>
    const inputs = {
        nginx_client_max_body_size: document.getElementById('nginx_client_max_body_size'),
        nginx_client_body_timeout: document.getElementById('nginx_client_body_timeout'),
        nginx_send_timeout: document.getElementById('nginx_send_timeout'),

        php_upload_max_filesize: document.getElementById('php_upload_max_filesize'),
        php_post_max_size: document.getElementById('php_post_max_size'),
        php_memory_limit: document.getElementById('php_memory_limit'),
        php_max_execution_time: document.getElementById('php_max_execution_time'),
        php_max_input_time: document.getElementById('php_max_input_time'),
        php_default_socket_timeout: document.getElementById('php_default_socket_timeout'),

        fcgi_read_timeout: document.getElementById('fcgi_read_timeout'),
        fcgi_send_timeout: document.getElementById('fcgi_send_timeout'),
        proxy_read_timeout: document.getElementById('proxy_read_timeout'),
    };

    let needRestart = false;

    // Load initial state
    document.addEventListener('DOMContentLoaded', () => {
        updatePreview();
    });

    function toggleUnlimitedUploads() {
        const isUnlimited = document.getElementById('toggleUnlimited').checked;
        const sizeInput = inputs.nginx_client_max_body_size;

        if (isUnlimited) {
            sizeInput.value = 0;
            sizeInput.disabled = true;
            document.getElementById('grp-client-max-body').style.opacity = '0.5';
        } else {
            sizeInput.value = 50;
            sizeInput.disabled = false;
            document.getElementById('grp-client-max-body').style.opacity = '1';
        }

        setRestartWarning();
        updatePreview();
    }

    function updateRange(id, val) {
        document.getElementById(id).textContent = val + 's';
    }

    function updatePreview() {
        setRestartWarning();

        let client_max = inputs.nginx_client_max_body_size.value == 0 ? "0" : inputs.nginx_client_max_body_size.value + "M";

        const nginxConfig = `http {
    # Basic Settings
    sendfile on;
    tcp_nopush on;
    types_hash_max_size 2048;

    # Client Variables
    <span class="code-highlight">client_max_body_size ${client_max};</span>

    # Timeouts
    <span class="code-highlight">client_body_timeout ${inputs.nginx_client_body_timeout.value}s;</span>
    <span class="code-highlight">send_timeout ${inputs.nginx_send_timeout.value}s;</span>

    # FastCGI Settings
    <span class="code-highlight">fastcgi_read_timeout ${inputs.fcgi_read_timeout.value}s;</span>
    <span class="code-highlight">fastcgi_send_timeout ${inputs.fcgi_send_timeout.value}s;</span>

    # Proxy Settings
    <span class="code-highlight">proxy_read_timeout ${inputs.proxy_read_timeout.value}s;</span>
}`;

        const phpConfig = `[PHP]
; Resource Limits
<span class="code-highlight">max_execution_time = ${inputs.php_max_execution_time.value}</span>
<span class="code-highlight">max_input_time = ${inputs.php_max_input_time.value}</span>
<span class="code-highlight">memory_limit = ${inputs.php_memory_limit.value}M</span>

; Data Handling
<span class="code-highlight">post_max_size = ${inputs.php_post_max_size.value}M</span>

; File Uploads
file_uploads = On
<span class="code-highlight">upload_max_filesize = ${inputs.php_upload_max_filesize.value}M</span>

; Default timeout
<span class="code-highlight">default_socket_timeout = ${inputs.php_default_socket_timeout.value}</span>`;

        document.getElementById('preview-nginx').innerHTML = nginxConfig;
        document.getElementById('preview-php').innerHTML = phpConfig;

        // Trigger pulse animation
        document.querySelectorAll('.code-highlight').forEach(el => {
            el.classList.remove('pulse');
            void el.offsetWidth;
            el.classList.add('pulse');
        });
    }

    function switchPreview(type, btnObj) {
        document.querySelectorAll('.preview-tab').forEach(b => {
            b.classList.remove('bg-slate-800', 'text-white', 'shadow-sm', 'active');
            b.classList.add('text-slate-500');
        });

        btnObj.classList.remove('text-slate-500');
        btnObj.classList.add('bg-slate-800', 'text-white', 'shadow-sm', 'active');

        const phpVer = document.getElementById('php_version').value;
        const icon = '<i data-lucide="file-code" class="w-3.5 h-3.5 inline-block mr-1"></i>';

        if (type === 'nginx') {
            document.getElementById('preview-nginx').classList.remove('hidden');
            document.getElementById('preview-php').classList.add('hidden');
            document.getElementById('previewTitle').innerHTML = icon + '/etc/nginx/nginx.conf';
        } else {
            document.getElementById('preview-nginx').classList.add('hidden');
            document.getElementById('preview-php').classList.remove('hidden');
            document.getElementById('previewTitle').innerHTML = icon + '/etc/php/' + phpVer + '/fpm/php.ini';
        }
        lucide.createIcons();
    }

    function applyPreset(type, btnElement) {
        document.querySelectorAll('.preset-btn').forEach(el => el.classList.remove('border-blue-500', 'bg-slate-800'));
        btnElement.classList.add('border-blue-500', 'bg-slate-800');

        document.getElementById('toggleUnlimited').checked = false;
        inputs.nginx_client_max_body_size.disabled = false;
        document.getElementById('grp-client-max-body').style.opacity = '1';

        switch (type) {
            case 'default':
                inputs.nginx_client_max_body_size.value = 50;
                inputs.nginx_client_body_timeout.value = 60;
                inputs.nginx_send_timeout.value = 60;
                inputs.php_upload_max_filesize.value = 50;
                inputs.php_post_max_size.value = 64;
                inputs.php_memory_limit.value = 256;
                inputs.php_max_execution_time.value = 60;
                inputs.php_max_input_time.value = 60;
                inputs.fcgi_read_timeout.value = 60;
                inputs.fcgi_send_timeout.value = 60;
                inputs.proxy_read_timeout.value = 60;
                break;
            case 'large':
                inputs.nginx_client_max_body_size.value = 500;
                inputs.nginx_client_body_timeout.value = 300;
                inputs.nginx_send_timeout.value = 300;
                inputs.php_upload_max_filesize.value = 500;
                inputs.php_post_max_size.value = 512;
                inputs.php_memory_limit.value = 1024;
                inputs.php_max_execution_time.value = 300;
                inputs.php_max_input_time.value = 300;
                inputs.fcgi_read_timeout.value = 300;
                inputs.fcgi_send_timeout.value = 300;
                inputs.proxy_read_timeout.value = 300;
                break;
            case 'backup':
                inputs.nginx_client_max_body_size.value = 2048;
                inputs.nginx_client_body_timeout.value = 600;
                inputs.nginx_send_timeout.value = 600;
                inputs.php_upload_max_filesize.value = 2048;
                inputs.php_post_max_size.value = 2048;
                inputs.php_memory_limit.value = 2048;
                inputs.php_max_execution_time.value = 600;
                inputs.php_max_input_time.value = 600;
                inputs.fcgi_read_timeout.value = 600;
                inputs.fcgi_send_timeout.value = 600;
                inputs.proxy_read_timeout.value = 600;
                break;
            case 'unlimited':
                document.getElementById('toggleUnlimited').checked = true;
                toggleUnlimitedUploads();
                inputs.nginx_client_body_timeout.value = 3600;
                inputs.nginx_send_timeout.value = 3600;
                inputs.php_upload_max_filesize.value = 9999;
                inputs.php_post_max_size.value = 9999;
                inputs.php_memory_limit.value = 4096;
                inputs.php_max_execution_time.value = 3600;
                inputs.php_max_input_time.value = 3600;
                inputs.fcgi_read_timeout.value = 3600;
                inputs.fcgi_send_timeout.value = 3600;
                inputs.proxy_read_timeout.value = 3600;
                break;
        }

        updateRange('php_max_execution_time_val', inputs.php_max_execution_time.value);
        updateRange('php_max_input_time_val', inputs.php_max_input_time.value);
        updatePreview();

        if (typeof showToast !== 'undefined') {
            showToast('success', 'Preset Applied', 'Settings updated to ' + type);
        }
    }

    function setRestartWarning() {
        needRestart = true;
        const badge = document.getElementById('status-badge');
        badge.className = 'flex items-center gap-2 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-500 text-xs font-bold uppercase tracking-wider';
        badge.innerHTML = '<div class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></div><span>Restart Needed</span>';
    }

    async function simulateAction(action, msg, needsRestartVarChanged) {
        if (typeof showToast !== 'undefined') showToast('info', 'Executing', msg);

        const fd = new FormData();
        fd.append('ajax_action', action);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                if (typeof showToast !== 'undefined') showToast('success', 'Done', res.msg);

                if (needsRestartVarChanged && action === 'restart_services') {
                    needRestart = false;
                    const badge = document.getElementById('status-badge');
                    badge.className = 'flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-bold uppercase tracking-wider';
                    badge.innerHTML = '<div class="w-2 h-2 rounded-full bg-emerald-400"></div><span>Running</span>';
                }
            } else {
                if (typeof showToast !== 'undefined') showToast('error', 'Error', res.msg);
            }
        } catch (e) {
            if (typeof showToast !== 'undefined') showToast('error', 'Error', 'Failed to communicate with server.');
        }
    }

    function saveChanges() {
        const fd = new FormData();
        fd.append('ajax_action', 'save_config');
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        for (const [key, el] of Object.entries(inputs)) {
            fd.append(key, el.value);
        }
        fd.append('php_version', document.getElementById('php_version').value);

        if (typeof showToast !== 'undefined') showToast('info', 'Executing', 'Saving configuration changes...');

        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    if (typeof showToast !== 'undefined') showToast('success', 'Done', res.msg);
                    setRestartWarning();
                } else {
                    if (typeof showToast !== 'undefined') showToast('error', 'Error', res.msg);
                }
            })
            .catch(e => showToast('error', 'Error', 'Failed to communicate with server.'));
    }
</script>