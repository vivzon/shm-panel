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

<div style="max-width: 80rem; margin: 0 auto; width: 100%; display: flex; flex-direction: column; gap: 2rem;">
    <!-- If you want a side-by-side layout on larger screens, add a custom class or inline media query logic -> for now keeping it simple block or flex-wrap -->
    <div style="display: flex; flex-wrap: wrap; gap: 2rem; width: 100%;">

        <!-- Left Column: Forms -->
        <div style="flex: 1 1 min(100%, 800px); display: flex; flex-direction: column; gap: 1.5rem;">

            <!-- Header area -->
            <div
                style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <h2
                        style="font-size: 1.5rem; font-weight: 700; color: var(--slate-900); font-family: var(--font-heading);">
                        Server Config Manager</h2>
                    <p style="color: var(--slate-700); font-size: 0.875rem;">Manage Nginx, PHP and Upload Settings</p>
                </div>

                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div id="status-badge"
                        style="display: flex; align-items: center; gap: 0.5rem; padding: 0.375rem 0.75rem; border-radius: 9999px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: #34d399; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">
                        <div style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: #34d399;"></div>
                        <span id="status-text">Running</span>
                    </div>

                    <button onclick="saveChanges()" class="btn btn-primary"
                        style="display: flex; align-items: center; gap: 0.5rem;">
                        <i data-lucide="save" style="width: 1rem; height: 1rem;"></i> Save
                    </button>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">

                <!-- Nginx Upload Settings -->
                <div class="glass-card" style="padding: 1.5rem; border-radius: 1rem;">
                    <div
                        style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
                        <div
                            style="width: 2rem; height: 2rem; border-radius: 0.5rem; background: rgba(59, 130, 246, 0.2); display: flex; align-items: center; justify-content: center; color: #60a5fa;">
                            <i data-lucide="server" style="width: 1rem; height: 1rem;"></i>
                        </div>
                        <h3 style="font-weight: 700; color: var(--slate-900);">Nginx Upload Settings</h3>
                    </div>

                    <div
                        style="margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between;">
                        <label style="font-size: 0.875rem; font-weight: 500; color: var(--slate-700);">Unlimited Upload
                            Size</label>
                        <div
                            style="position: relative; display: inline-block; width: 2.5rem; margin-right: 0.5rem; vertical-align: middle; user-select: none; transition: all 0.2s;">
                            <input type="checkbox" id="toggleUnlimited" onchange="toggleUnlimitedUploads()"
                                class="toggle-checkbox"
                                style="position: absolute; display: block; width: 1.25rem; height: 1.25rem; border-radius: 9999px; background: white; border: 4px solid var(--slate-300); appearance: none; cursor: pointer; transition: transform 0.2s; z-index: 10;" />
                            <label for="toggleUnlimited" class="toggle-label"
                                style="display: block; overflow: hidden; height: 1.25rem; border-radius: 9999px; background: var(--slate-700); cursor: pointer;"></label>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <div id="grp-client-max-body">
                            <label
                                style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--slate-700); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.025em;">Client
                                Max
                                Body Size <span
                                    style="color: var(--slate-700); text-transform: none; letter-spacing: normal; margin-left: 0.25rem;">client_max_body_size</span></label>
                            <div style="display: flex;">
                                <input type="number" id="nginx_client_max_body_size" value="50"
                                    oninput="updatePreview()" class="form-input"
                                    style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; flex: 1;">
                                <div
                                    style="background: var(--slate-50); border: 1px solid var(--border-color); border-left: none; border-top-right-radius: 0.75rem; border-bottom-right-radius: 0.75rem; padding: 0 1rem; display: flex; align-items: center; color: var(--slate-700); font-size: 0.875rem; font-weight: 500;">
                                    MB</div>
                            </div>
                        </div>

                        <div>
                            <label
                                style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--slate-700); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.025em;">Client
                                Body
                                Timeout</label>
                            <div style="display: flex;">
                                <input type="number" id="nginx_client_body_timeout" value="60" oninput="updatePreview()"
                                    class="form-input"
                                    style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; flex: 1;">
                                <div
                                    style="background: var(--slate-50); border: 1px solid var(--border-color); border-left: none; border-top-right-radius: 0.75rem; border-bottom-right-radius: 0.75rem; padding: 0 1rem; display: flex; align-items: center; color: var(--slate-700); font-size: 0.875rem; font-weight: 500;">
                                    s</div>
                            </div>
                        </div>

                        <div>
                            <label
                                style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--slate-700); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.025em;">Send
                                Timeout</label>
                            <div style="display: flex;">
                                <input type="number" id="nginx_send_timeout" value="60" oninput="updatePreview()"
                                    class="form-input"
                                    style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; flex: 1;">
                                <div
                                    style="background: var(--slate-50); border: 1px solid var(--border-color); border-left: none; border-top-right-radius: 0.75rem; border-bottom-right-radius: 0.75rem; padding: 0 1rem; display: flex; align-items: center; color: var(--slate-700); font-size: 0.875rem; font-weight: 500;">
                                    s</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FastCGI Timeout Settings -->
                <div class="glass-card" style="padding: 1.5rem; border-radius: 1rem;">
                    <div
                        style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
                        <div
                            style="width: 2rem; height: 2rem; border-radius: 0.5rem; background: rgba(99, 102, 241, 0.2); display: flex; align-items: center; justify-content: center; color: #818cf8;">
                            <i data-lucide="network" style="width: 1rem; height: 1rem;"></i>
                        </div>
                        <h3 style="font-weight: 700; color: var(--slate-900);">FastCGI & Proxy</h3>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <div>
                            <label
                                style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--slate-700); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.025em;">FastCGI
                                Read
                                Timeout</label>
                            <div style="display: flex;">
                                <input type="number" id="fcgi_read_timeout" value="60" oninput="updatePreview()"
                                    class="form-input"
                                    style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; flex: 1;">
                                <div
                                    style="background: var(--slate-50); border: 1px solid var(--border-color); border-left: none; border-top-right-radius: 0.75rem; border-bottom-right-radius: 0.75rem; padding: 0 1rem; display: flex; align-items: center; color: var(--slate-700); font-size: 0.875rem; font-weight: 500;">
                                    s</div>
                            </div>
                        </div>

                        <div>
                            <label
                                style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--slate-700); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.025em;">FastCGI
                                Send
                                Timeout</label>
                            <div style="display: flex;">
                                <input type="number" id="fcgi_send_timeout" value="60" oninput="updatePreview()"
                                    class="form-input"
                                    style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; flex: 1;">
                                <div
                                    style="background: var(--slate-50); border: 1px solid var(--border-color); border-left: none; border-top-right-radius: 0.75rem; border-bottom-right-radius: 0.75rem; padding: 0 1rem; display: flex; align-items: center; color: var(--slate-700); font-size: 0.875rem; font-weight: 500;">
                                    s</div>
                            </div>
                        </div>

                        <div>
                            <label
                                style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--slate-700); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.025em;">Proxy
                                Read
                                Timeout</label>
                            <div style="display: flex;">
                                <input type="number" id="proxy_read_timeout" value="60" oninput="updatePreview()"
                                    class="form-input"
                                    style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; flex: 1;">
                                <div
                                    style="background: var(--slate-50); border: 1px solid var(--border-color); border-left: none; border-top-right-radius: 0.75rem; border-bottom-right-radius: 0.75rem; padding: 0 1rem; display: flex; align-items: center; color: var(--slate-700); font-size: 0.875rem; font-weight: 500;">
                                    s</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PHP Configuration -->
                <div class="glass-card" style="padding: 1.5rem; border-radius: 1rem; grid-column: 1 / -1;">
                    <div
                        style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div
                                style="width: 2rem; height: 2rem; border-radius: 0.5rem; background: rgba(236, 72, 153, 0.2); display: flex; align-items: center; justify-content: center; color: #f472b6;">
                                <i data-lucide="file-code" style="width: 1rem; height: 1rem;"></i>
                            </div>
                            <h3 style="font-weight: 700; color: var(--slate-900);">PHP Configuration</h3>
                        </div>

                        <div>
                            <select id="php_version" onchange="updatePreview()" class="form-input"
                                style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem;">
                                <option value="8.3">PHP 8.3 (Default)</option>
                                <option value="8.2">PHP 8.2</option>
                                <option value="8.1">PHP 8.1</option>
                                <option value="8.0">PHP 8.0</option>
                                <option value="7.4">PHP 7.4</option>
                            </select>
                        </div>
                    </div>

                    <div
                        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div>
                                <label
                                    style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--slate-700); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.025em;">Upload
                                    Max Filesize</label>
                                <div style="display: flex;">
                                    <input type="number" id="php_upload_max_filesize" value="50"
                                        oninput="updatePreview()" class="form-input"
                                        style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; flex: 1;">
                                    <div
                                        style="background: var(--slate-50); border: 1px solid var(--border-color); border-left: none; border-top-right-radius: 0.75rem; border-bottom-right-radius: 0.75rem; padding: 0 1rem; display: flex; align-items: center; color: var(--slate-700); font-size: 0.875rem; font-weight: 500;">
                                        M</div>
                                </div>
                            </div>

                            <div>
                                <label
                                    style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--slate-700); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.025em;">Post
                                    Max
                                    Size</label>
                                <div style="display: flex;">
                                    <input type="number" id="php_post_max_size" value="64" oninput="updatePreview()"
                                        class="form-input"
                                        style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; flex: 1;">
                                    <div
                                        style="background: var(--slate-50); border: 1px solid var(--border-color); border-left: none; border-top-right-radius: 0.75rem; border-bottom-right-radius: 0.75rem; padding: 0 1rem; display: flex; align-items: center; color: var(--slate-700); font-size: 0.875rem; font-weight: 500;">
                                        M</div>
                                </div>
                            </div>

                            <div>
                                <label
                                    style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--slate-700); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.025em;">Memory
                                    Limit</label>
                                <div style="display: flex;">
                                    <input type="number" id="php_memory_limit" value="256" oninput="updatePreview()"
                                        class="form-input"
                                        style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; flex: 1;">
                                    <div
                                        style="background: var(--slate-50); border: 1px solid var(--border-color); border-left: none; border-top-right-radius: 0.75rem; border-bottom-right-radius: 0.75rem; padding: 0 1rem; display: flex; align-items: center; color: var(--slate-700); font-size: 0.875rem; font-weight: 500;">
                                        M</div>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                            <div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                                    <label
                                        style="font-size: 0.75rem; font-weight: 700; color: var(--slate-700); text-transform: uppercase; letter-spacing: 0.025em;">Max
                                        Execution
                                        Time</label>
                                    <span
                                        style="color: var(--slate-900); font-size: 0.875rem; font-family: 'Fira Code', monospace; background: var(--slate-50); padding: 0.125rem 0.5rem; border-radius: 0.25rem;"
                                        id="php_max_execution_time_val">60s</span>
                                </div>
                                <input type="range" id="php_max_execution_time" min="30" max="600" step="30" value="60"
                                    oninput="updateRange('php_max_execution_time_val', this.value); updatePreview()">
                            </div>

                            <div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                                    <label
                                        style="font-size: 0.75rem; font-weight: 700; color: var(--slate-700); text-transform: uppercase; letter-spacing: 0.025em;">Max
                                        Input
                                        Time</label>
                                    <span
                                        style="color: var(--slate-900); font-size: 0.875rem; font-family: 'Fira Code', monospace; background: var(--slate-50); padding: 0.125rem 0.5rem; border-radius: 0.25rem;"
                                        id="php_max_input_time_val">60s</span>
                                </div>
                                <input type="range" id="php_max_input_time" min="60" max="600" step="60" value="60"
                                    oninput="updateRange('php_max_input_time_val', this.value); updatePreview()">
                            </div>

                            <div>
                                <label
                                    style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--slate-700); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.025em;">Default
                                    Socket Timeout</label>
                                <div style="display: flex;">
                                    <input type="number" id="php_default_socket_timeout" value="60"
                                        oninput="updatePreview()" class="form-input"
                                        style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; flex: 1;">
                                    <div
                                        style="background: var(--slate-50); border: 1px solid var(--border-color); border-left: none; border-top-right-radius: 0.75rem; border-bottom-right-radius: 0.75rem; padding: 0 1rem; display: flex; align-items: center; color: var(--slate-700); font-size: 0.875rem; font-weight: 500;">
                                        s</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Operations & Presets -->
                <div class="glass-card" style="padding: 1.5rem; border-radius: 1rem; grid-column: 1 / -1;">
                    <h3 style="font-weight: 700; color: var(--slate-900); margin-bottom: 1rem;">Operations & Presets
                    </h3>

                    <div
                        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                        <button onclick="applyPreset('default', this)" class="preset-btn"
                            style="padding: 1rem; border-radius: 0.75rem; border: 1px solid var(--border-color); background: rgba(248, 250, 252, 0.3); text-align: left; transition: all 0.2s; cursor: pointer;"
                            onmouseover="this.style.background='var(--slate-50)'; this.style.borderColor='#3b82f6'"
                            onmouseout="this.style.background='rgba(248, 250, 252, 0.3)'; this.style.borderColor='var(--border-color)'">
                            <div
                                style="color: var(--slate-900); font-weight: 700; font-size: 0.875rem; margin-bottom: 0.25rem;">
                                Default</div>
                            <div style="color: var(--slate-700); font-size: 0.75rem;">Standard (50MB)</div>
                        </button>
                        <button onclick="applyPreset('large', this)" class="preset-btn"
                            style="padding: 1rem; border-radius: 0.75rem; border: 1px solid var(--border-color); background: rgba(248, 250, 252, 0.3); text-align: left; transition: all 0.2s; cursor: pointer;"
                            onmouseover="this.style.background='var(--slate-50)'; this.style.borderColor='#3b82f6'"
                            onmouseout="this.style.background='rgba(248, 250, 252, 0.3)'; this.style.borderColor='var(--border-color)'">
                            <div
                                style="color: var(--slate-900); font-weight: 700; font-size: 0.875rem; margin-bottom: 0.25rem;">
                                Large Media</div>
                            <div style="color: var(--slate-700); font-size: 0.75rem;">Videos (500MB)</div>
                        </button>
                        <button onclick="applyPreset('backup', this)" class="preset-btn"
                            style="padding: 1rem; border-radius: 0.75rem; border: 1px solid var(--border-color); background: rgba(248, 250, 252, 0.3); text-align: left; transition: all 0.2s; cursor: pointer;"
                            onmouseover="this.style.background='var(--slate-50)'; this.style.borderColor='#3b82f6'"
                            onmouseout="this.style.background='rgba(248, 250, 252, 0.3)'; this.style.borderColor='var(--border-color)'">
                            <div
                                style="color: var(--slate-900); font-weight: 700; font-size: 0.875rem; margin-bottom: 0.25rem;">
                                Backup Mode</div>
                            <div style="color: var(--slate-700); font-size: 0.75rem;">Massive (2GB)</div>
                        </button>
                        <button onclick="applyPreset('unlimited', this)" class="preset-btn"
                            style="padding: 1rem; border-radius: 0.75rem; border: 1px solid var(--border-color); background: rgba(248, 250, 252, 0.3); text-align: left; transition: all 0.2s; cursor: pointer;"
                            onmouseover="this.style.background='var(--slate-50)'; this.style.borderColor='#3b82f6'"
                            onmouseout="this.style.background='rgba(248, 250, 252, 0.3)'; this.style.borderColor='var(--border-color)'">
                            <div
                                style="color: var(--slate-900); font-weight: 700; font-size: 0.875rem; margin-bottom: 0.25rem;">
                                Unlimited</div>
                            <div style="color: var(--slate-700); font-size: 0.75rem;">No Limits</div>
                        </button>
                    </div>

                    <div
                        style="display: flex; flex-wrap: wrap; gap: 0.75rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
                        <button onclick="simulateAction('restart_services', 'Restarting Nginx & PHP-FPM...', true)"
                            style="background: rgba(79, 70, 229, 0.2); color: #818cf8; border: 1px solid rgba(79, 70, 229, 0.3); padding: 0.5rem 1rem; border-radius: 0.75rem; font-size: 0.875rem; font-weight: 700; transition: all 0.2s; display: flex; align-items: center; gap: 0.5rem; cursor: pointer;"
                            onmouseover="this.style.backgroundColor='#4f46e5'; this.style.color='var(--slate-900)'"
                            onmouseout="this.style.backgroundColor='rgba(79, 70, 229, 0.2)'; this.style.color='#818cf8'">
                            <i data-lucide="refresh-cw" style="width: 1rem; height: 1rem;"></i> Restart Services
                        </button>
                        <button onclick="simulateAction('test_config', 'Testing Configuration Syntax...', false)"
                            class="btn btn-outline"
                            style="padding: 0.5rem 1rem; font-size: 0.875rem; border-radius: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i data-lucide="check-circle" style="width: 1rem; height: 1rem;"></i> Test Syntax
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- Right Column: Live Preview Panels -->
        <div style="width: 100%; flex: 1 1 300px; display: flex; flex-direction: column; gap: 1rem; max-width: 400px;">

            <!-- Tabs -->
            <div
                style="display: flex; gap: 0.5rem; padding: 0.25rem; background: rgba(255, 255, 255, 0.8); border-radius: 0.75rem; border: 1px solid var(--border-color);">
                <button onclick="switchPreview('nginx', this)" class="preview-tab active"
                    style="flex: 1; padding: 0.5rem 0; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 700; transition: all 0.2s; background: var(--slate-50); color: var(--slate-900); box-shadow: var(--shadow-sm); border: none; cursor: pointer;">
                    nginx.conf
                </button>
                <button onclick="switchPreview('php', this)" class="preview-tab"
                    style="flex: 1; padding: 0.5rem 0; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 700; color: var(--slate-700); transition: all 0.2s; background: transparent; border: none; cursor: pointer;"
                    onmouseover="this.style.color='var(--slate-900)'" onmouseout="this.style.color='var(--slate-700)'">
                    php.ini
                </button>
            </div>

            <!-- Code Windows -->
            <div class="glass-card"
                style="flex: 1; display: flex; flex-direction: column; overflow: hidden; min-height: 500px; border-radius: 1rem;">
                <div
                    style="padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); background: var(--slate-50); display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; color: var(--slate-700); font-size: 0.75rem; font-family: 'Fira Code', monospace;"
                        id="previewTitle">
                        <i data-lucide="file-code" style="width: 0.875rem; height: 0.875rem;"></i> /etc/nginx/nginx.conf
                    </div>
                    <div
                        style="color: #10b981; font-size: 0.625rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; display: flex; align-items: center; gap: 0.25rem;">
                        <i data-lucide="check-circle-2" style="width: 0.75rem; height: 0.75rem;"></i> Valid
                    </div>
                </div>

                <div style="flex: 1; background: rgba(255, 255, 255, 0.8); padding: 1rem; overflow-y: auto;"
                    class="custom-scrollbar">
                    <pre id="preview-nginx"
                        style="font-family: 'Fira Code', monospace; font-size: 0.75rem; line-height: 1.625; color: var(--slate-700); white-space: pre-wrap; word-break: break-word;"></pre>
                    <pre id="preview-php" class="hidden"
                        style="font-family: 'Fira Code', monospace; font-size: 0.75rem; line-height: 1.625; color: var(--slate-700); white-space: pre-wrap; word-break: break-word;"></pre>
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
                b.style.background = 'transparent';
                b.style.color = 'var(--slate-700)';
                b.style.boxShadow = 'none';
                b.classList.remove('active');
            });

            btnObj.style.background = 'var(--slate-50)';
            btnObj.style.color = 'var(--slate-900)';
            btnObj.style.boxShadow = 'var(--shadow-sm)';
            btnObj.classList.add('active');

            const phpVer = document.getElementById('php_version').value;
            const icon = '<i data-lucide="file-code" style="width: 0.875rem; height: 0.875rem; display: inline-block; margin-right: 0.25rem;"></i>';

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
            document.querySelectorAll('.preset-btn').forEach(el => {
                el.style.borderColor = 'var(--border-color)';
                el.style.background = 'rgba(248, 250, 252, 0.3)';
            });
            btnElement.style.borderColor = '#3b82f6';
            btnElement.style.background = 'var(--slate-50)';

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
            badge.style.background = 'rgba(245, 158, 11, 0.1)';
            badge.style.borderColor = 'rgba(245, 158, 11, 0.2)';
            badge.style.color = '#f59e0b';
            badge.innerHTML = '<div style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: #f59e0b; animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;"></div><span>Restart Needed</span>';
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
                        badge.style.background = 'rgba(16, 185, 129, 0.1)';
                        badge.style.borderColor = 'rgba(16, 185, 129, 0.2)';
                        badge.style.color = '#34d399';
                        badge.innerHTML = '<div style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: #34d399;"></div><span>Running</span>';
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