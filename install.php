<?php
/**
 * SHM PANEL - PREMIUM WEB INSTALLER
 * =================================
 * A sophisticated, multi-step installation wizard for the SHM Panel.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('INSTALLER_RUNNING', true);

$config_file = __DIR__ . '/shared/config.local.php';
$is_installed = file_exists($config_file);

// Handle AJAX Requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'check_db') {
        $host = $_POST['host'] ?? 'localhost';
        $user = $_POST['user'] ?? 'root';
        $pass = $_POST['pass'] ?? '';
        
        try {
            $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            echo json_encode(['success' => true, 'message' => 'Database connection successful!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'install') {
        $host = $_POST['db_host'] ?? 'localhost';
        $name = $_POST['db_name'] ?? 'shm_panel';
        $user = $_POST['db_user'] ?? 'root';
        $pass = $_POST['db_pass'] ?? '';
        $admin_user = $_POST['admin_user'] ?? 'admin';
        $admin_pass = $_POST['admin_pass'] ?? 'admin123';

        try {
            // 1. Create DB
            $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$name`");

            // 2. Import Schema
            $schema_file = __DIR__ . '/installer/schema.sql';
            if (!file_exists($schema_file)) {
                throw new Exception("Schema file not found at $schema_file");
            }
            $sql = file_get_contents($schema_file);
            $pdo->exec($sql);

            // 3. Create Admin
            $hash = password_hash($admin_pass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO admins (username, password, email, role) VALUES (?, ?, ?, 'superadmin')");
            $stmt->execute([$admin_user, $hash, $admin_user . "@localhost", $hash]);

            // 4. Write Config
            $config_content = "<?php\n";
            $config_content .= "\$db_host = '$host';\n";
            $config_content .= "\$db_name = '$name';\n";
            $config_content .= "\$db_user = '$user';\n";
            $config_content .= "\$db_pass = '$pass';\n";
            $config_content .= "\$MAIN_DOMAIN = \$_SERVER['HTTP_HOST'] ?? 'localhost';\n";
            $config_content .= "ob_start();\n?>";
            
            file_put_contents($config_file, $config_content);

            echo json_encode(['success' => true, 'message' => 'Installation completed successfully!']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Requirements Check
$requirements = [
    'PHP Version >= 8.1' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO Extension' => extension_loaded('pdo_mysql'),
    'JSON Extension' => extension_loaded('json'),
    'OpenSSL Extension' => extension_loaded('openssl'),
    'Mbstring Extension' => extension_loaded('mbstring'),
    'Config Writable' => is_writable(__DIR__ . '/shared') || is_writable(__DIR__),
];
$all_passed = !in_array(false, $requirements, true);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SHM Panel - Installation Wizard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', sans-serif; background: #0b0f19; color: #fff; overflow-x: hidden; }
        .glass { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.05); }
        .gradient-text { background: linear-gradient(135deg, #60a5fa 0%, #a855f7 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .step-node.active { background: #3b82f6; box-shadow: 0 0 15px #3b82f6; }
        .step-node.completed { background: #10b981; }
        .hidden { display: none; }
        input { background: rgba(255, 255, 255, 0.05) !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; color: white !important; }
        input:focus { border-color: #3b82f6 !important; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade { animation: fadeIn 0.4s ease-out forwards; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">

    <div class="fixed top-0 left-0 w-full h-full pointer-events-none opacity-20">
        <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] bg-blue-600 rounded-full blur-[120px]"></div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-purple-600 rounded-full blur-[120px]"></div>
    </div>

    <div class="w-full max-w-4xl relative z-10">
        <div class="text-center mb-10 animate-fade">
            <h1 class="text-6xl font-black mb-2 tracking-tighter gradient-text">SHM PANEL</h1>
            <p class="text-slate-400 text-lg">Premium Infrastructure Cloud Management</p>
        </div>

        <div class="glass flex mb-8 rounded-2xl p-4 justify-between items-center px-12">
            <div class="flex items-center gap-3">
                <div id="node-1" class="step-node active w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm bg-slate-700">1</div>
                <span class="text-sm font-semibold hidden md:block text-slate-300">Welcome</span>
            </div>
            <div class="h-px flex-1 mx-4 bg-slate-700"></div>
            <div class="flex items-center gap-3">
                <div id="node-2" class="step-node w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm bg-slate-700">2</div>
                <span class="text-sm font-semibold hidden md:block text-slate-500">Database</span>
            </div>
            <div class="h-px flex-1 mx-4 bg-slate-700"></div>
            <div class="flex items-center gap-3">
                <div id="node-3" class="step-node w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm bg-slate-700">3</div>
                <span class="text-sm font-semibold hidden md:block text-slate-500">Admin</span>
            </div>
            <div class="h-px flex-1 mx-4 bg-slate-700"></div>
            <div class="flex items-center gap-3">
                <div id="node-4" class="step-node w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm bg-slate-700">4</div>
                <span class="text-sm font-semibold hidden md:block text-slate-500">Finish</span>
            </div>
        </div>

        <div class="glass rounded-3xl p-8 md:p-12 shadow-2xl relative overflow-hidden min-h-[500px]">
            
            <!-- Step 1: Welcome -->
            <div id="step-1" class="animate-fade">
                <h2 class="text-3xl font-bold mb-6">Welcome to SHM Panel</h2>
                <p class="text-slate-400 mb-8 leading-relaxed">
                    Setting up your premium cloud hosting environment. We'll start with a quick system health check to ensure your server is ready for deployment.
                </p>
                
                <div class="space-y-4 mb-10">
                    <?php foreach ($requirements as $label => $passed): ?>
                    <div class="flex items-center justify-between p-4 rounded-xl bg-white/5 border border-white/5">
                        <span class="text-slate-300"><?= $label ?></span>
                        <span class="<?= $passed ? 'text-emerald-400' : 'text-red-400' ?> font-bold">
                            <?= $passed ? '✓ PASSED' : '× FAILED' ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex justify-end">
                    <button onclick="nextStep(2)" <?= !$all_passed ? 'disabled' : '' ?> 
                        class="bg-blue-600 hover:bg-blue-500 disabled:bg-slate-800 disabled:text-slate-500 px-8 py-4 rounded-xl font-bold transition-all transform hover:scale-105 active:scale-95 shadow-xl shadow-blue-600/20">
                        Continue to Database Setup
                    </button>
                </div>
            </div>

            <!-- Step 2: Database -->
            <div id="step-2" class="hidden animate-fade">
                <h2 class="text-3xl font-bold mb-6">Database Connectivity</h2>
                <p class="text-slate-400 mb-8">Enter your MariaDB/MySQL credentials. We'll verify the connection before proceeding.</p>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1 tracking-widest">Hostname</label>
                        <input type="text" id="db_host" value="localhost" class="w-full px-5 py-4 rounded-2xl focus:outline-none transition">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1 tracking-widest">Database Name</label>
                        <input type="text" id="db_name" value="shm_panel" class="w-full px-5 py-4 rounded-2xl focus:outline-none transition">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1 tracking-widest">Username</label>
                        <input type="text" id="db_user" value="root" class="w-full px-5 py-4 rounded-2xl focus:outline-none transition">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1 tracking-widest">Password</label>
                        <input type="password" id="db_pass" class="w-full px-5 py-4 rounded-2xl focus:outline-none transition" placeholder="••••••••">
                    </div>
                </div>

                <div id="db-status" class="mb-8 hidden"></div>

                <div class="flex justify-between items-center">
                    <button onclick="testConnection()" class="text-blue-400 font-bold hover:text-blue-300 transition px-2">Test Connection</button>
                    <button onclick="nextStep(3)" class="bg-blue-600 hover:bg-blue-500 px-8 py-4 rounded-xl font-bold transition-all shadow-xl shadow-blue-600/20">
                        Proceed to Admin Setup
                    </button>
                </div>
            </div>

            <!-- Step 3: Admin -->
            <div id="step-3" class="hidden animate-fade">
                <h2 class="text-3xl font-bold mb-6">Administrative Account</h2>
                <p class="text-slate-400 mb-8">Define the super-administrator credentials. You will use these to manage servers, accounts, and security from the WHM interface.</p>
                
                <div class="space-y-6 mb-10 max-w-lg">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1 tracking-widest">Master Username</label>
                        <input type="text" id="admin_user" value="admin" class="w-full px-5 py-4 rounded-2xl focus:outline-none transition">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1 tracking-widest">Master Password</label>
                        <input type="password" id="admin_pass" value="admin123" class="w-full px-5 py-4 rounded-2xl focus:outline-none transition">
                        <p class="text-xs text-slate-500 mt-3 italic">Security Note: Password will be hashed using BCrypt.</p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button onclick="startInstallation()" id="install-btn" class="bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 px-12 py-5 rounded-2xl font-black text-lg tracking-tight transition-all shadow-2xl shadow-blue-600/30">
                        Launch Complete Setup
                    </button>
                </div>

                <div id="install-progress" class="hidden mt-10">
                    <div class="w-full h-3 bg-white/5 rounded-full overflow-hidden mb-4">
                        <div id="progress-fill" class="h-full bg-blue-500 transition-all duration-1000" style="width: 0%"></div>
                    </div>
                    <p id="progress-text" class="text-center text-sm font-bold text-blue-400 animate-pulse">Initializing System Architecture...</p>
                </div>
            </div>

            <!-- Step 4: Finish -->
            <div id="step-4" class="hidden animate-fade text-center">
                <div class="w-24 h-24 bg-emerald-500/20 rounded-full flex items-center justify-center mx-auto mb-8 border border-emerald-500/30">
                    <span class="text-5xl text-emerald-400">✓</span>
                </div>
                <h2 class="text-4xl font-black mb-4 tracking-tighter">System Synchronized</h2>
                <p class="text-slate-400 mb-10 max-w-md mx-auto">SHM Panel has been successfully deployed. Your hosting stack is now isolated and secure.</p>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
                    <a href="/whm/" class="p-6 rounded-2xl bg-white/5 border border-white/5 hover:bg-white/10 transition-all group">
                        <h3 class="font-bold text-lg mb-1 group-hover:text-blue-400">WHM Panel</h3>
                        <p class="text-xs text-slate-500">Reseller & Administrator Login</p>
                    </a>
                    <a href="/cpanel/" class="p-6 rounded-2xl bg-white/5 border border-white/5 hover:bg-white/10 transition-all group">
                        <h3 class="font-bold text-lg mb-1 group-hover:text-purple-400">cPanel</h3>
                        <p class="text-xs text-slate-500">Client Control Interface</p>
                    </a>
                </div>

                <div class="bg-yellow-500/10 border border-yellow-500/30 p-4 rounded-xl text-yellow-300 text-sm mb-6 text-left">
                    <strong>Critical Security Warning:</strong> Please delete the <code class="bg-slate-900 px-1 rounded">install.php</code> file immediately to prevent unauthorized reconfiguration.
                </div>
            </div>
        </div>
        
        <p class="text-center text-slate-600 text-xs mt-8">SHM Infrastructure v1.2 &bull; Developed with Precision &bull; &copy; 2026 Vivzon Cloud</p>
    </div>

    <script>
        let currentStep = 1;

        function nextStep(step) {
            document.getElementById(`step-${currentStep}`).classList.add('hidden');
            document.getElementById(`step-${step}`).classList.remove('hidden');
            
            // Update Nodes
            document.getElementById(`node-${currentStep}`).classList.remove('active');
            document.getElementById(`node-${currentStep}`).classList.add('completed');
            document.getElementById(`node-${step}`).classList.add('active');
            
            // Change status text colors etc
            currentStep = step;
        }

        async function testConnection() {
            const host = document.getElementById('db_host').value;
            const user = document.getElementById('db_user').value;
            const pass = document.getElementById('db_pass').value;
            const statusDiv = document.getElementById('db-status');
            
            statusDiv.classList.remove('hidden');
            statusDiv.innerHTML = '<p class="text-blue-400 animate-pulse text-sm font-bold">Connecting to MariaDB...</p>';

            const formData = new FormData();
            formData.append('host', host);
            formData.append('user', user);
            formData.append('pass', pass);

            try {
                const response = await fetch('?action=check_db', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    statusDiv.innerHTML = '<div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm font-bold">✓ Connection Validated</div>';
                } else {
                    statusDiv.innerHTML = `<div class="p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm">Error: ${data.message}</div>`;
                }
            } catch (e) {
                statusDiv.innerHTML = `<div class="p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm">Request Failed: See Console</div>`;
            }
        }

        async function startInstallation() {
            document.getElementById('install-btn').disabled = true;
            document.getElementById('install-btn').classList.add('opacity-50');
            document.getElementById('install-progress').classList.remove('hidden');
            
            const fill = document.getElementById('progress-fill');
            const text = document.getElementById('progress-text');

            const steps = [
                { p: 20, t: 'Synchronizing Core Repositories...' },
                { p: 40, t: 'Injecting Database Schema...' },
                { p: 60, t: 'Hardening Security Loggers...' },
                { p: 80, t: 'Generating Master Configuration...' },
                { p: 100, t: 'Finalizing Deployment Hooks...' }
            ];

            // Start actual process
            const formData = new FormData();
            formData.append('db_host', document.getElementById('db_host').value);
            formData.append('db_name', document.getElementById('db_name').value);
            formData.append('db_user', document.getElementById('db_user').value);
            formData.append('db_pass', document.getElementById('db_pass').value);
            formData.append('admin_user', document.getElementById('admin_user').value);
            formData.append('admin_pass', document.getElementById('admin_pass').value);

            // Visual Progress (Fake intervals to make it feel premium, then wait for real result)
            let currentIdx = 0;
            const interval = setInterval(() => {
                if (currentIdx < steps.length) {
                    fill.style.width = steps[currentIdx].p + '%';
                    text.innerText = steps[currentIdx].t;
                    currentIdx++;
                } else {
                    clearInterval(interval);
                }
            }, 1000);

            try {
                const response = await fetch('?action=install', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    setTimeout(() => nextStep(4), 1500);
                } else {
                    alert('Installation Error: ' + data.message);
                    document.getElementById('install-btn').disabled = false;
                    document.getElementById('install-btn').classList.remove('opacity-50');
                }
            } catch (e) {
                alert('Fatal Request Error. Check PHP logs.');
            }
        }
    </script>
</body>
</html>
