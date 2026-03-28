<?php
/**
 * WHM DNS Manager — All Domains
 * Displays all DNS records from the database, plus live DNS lookup via dig/nslookup.
 */
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// ─── AJAX ACTIONS ─────────────────────────────────────────────────────────────
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    try {
        verify_csrf();
        $action = $_POST['ajax_action'];

        // List all DNS records grouped by domain
        if ($action === 'list_dns') {
            $rows = $pdo->query("
                SELECT dr.id, dr.domain_id, d.domain AS domain_name, dr.type, dr.name, dr.value, dr.ttl,
                       c.username
                FROM dns_records dr
                JOIN domains d ON dr.domain_id = d.id
                JOIN clients c ON d.client_id = c.id
                ORDER BY d.domain ASC, dr.type ASC, dr.name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $rows]);
            exit;
        }

        // Add a DNS record
        if ($action === 'add_dns') {
            $domain_id = (int)$_POST['domain_id'];
            $type  = strtoupper(trim($_POST['type']));
            $name  = trim($_POST['name'] ?? '@');
            $value = trim($_POST['value']);
            $ttl   = (int)($_POST['ttl'] ?? 3600);

            if (!in_array($type, ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'PTR', 'CAA'])) {
                throw new Exception("Invalid record type: $type");
            }
            if (empty($value)) throw new Exception("Record value cannot be empty");

            // Verify domain belongs to a real client
            $check = $pdo->prepare("SELECT id FROM domains WHERE id = ?");
            $check->execute([$domain_id]);
            if (!$check->fetch()) throw new Exception("Domain not found");

            $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value, ttl) VALUES (?,?,?,?,?)")
                ->execute([$domain_id, $type, $name, $value, $ttl]);
            echo json_encode(['status' => 'success', 'msg' => "$type record added"]);
            exit;
        }

        // Delete a DNS record
        if ($action === 'del_dns') {
            $id = (int)$_POST['id'];
            $pdo->prepare("DELETE FROM dns_records WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'msg' => 'Record deleted']);
            exit;
        }

        // Live DNS lookup via dig (Linux) or nslookup (fallback)
        if ($action === 'live_lookup') {
            $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', $_POST['domain'] ?? '');
            $type   = strtoupper(preg_replace('/[^A-Z]/', '', $_POST['type'] ?? 'A'));
            if (empty($domain)) throw new Exception("Domain required");

            $output = [];
            if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
                exec("dig +short " . escapeshellarg($type) . " " . escapeshellarg($domain) . " 2>&1", $output, $rc);
                if (empty($output) || $rc !== 0) {
                    exec("nslookup -type=" . escapeshellarg($type) . " " . escapeshellarg($domain) . " 2>&1", $output, $rc2);
                }
            } else {
                // PHP-only fallback
                $map = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'MX' => DNS_MX, 'NS' => DNS_NS, 'CNAME' => DNS_CNAME, 'TXT' => DNS_TXT];
                $records = @dns_get_record($domain, $map[$type] ?? DNS_A);
                $output = array_map(function($r) use ($type) {
                    return match($type) {
                        'A','AAAA' => $r['ip'] ?? $r['ipv6'] ?? '',
                        'MX'  => ($r['pri'] ?? '') . ' ' . ($r['target'] ?? ''),
                        'NS'  => $r['target'] ?? '',
                        'CNAME' => $r['target'] ?? '',
                        'TXT' => implode(' ', $r['entries'] ?? []),
                        default => json_encode($r)
                    };
                }, $records ?: []);
                if (empty($output)) $output = ['(No records found)'];
            }

            echo json_encode(['status' => 'success', 'result' => array_filter($output)]);
            exit;
        }

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// ─── PAGE DATA ─────────────────────────────────────────────────────────────────
$all_domains = $pdo->query("
    SELECT d.id, d.domain, c.username
    FROM domains d
    JOIN clients c ON d.client_id = c.id
    ORDER BY d.domain ASC
")->fetchAll(PDO::FETCH_ASSOC);

$current_page = 'dns.php';
include 'layout/header.php';
?>

<div style="margin-bottom: 2rem; display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 500; color: var(--text-primary); margin-bottom: 0.25rem;">DNS Manager</h2>
        <p style="color: var(--text-secondary); font-size: 0.875rem;">All DNS records across every hosted domain.</p>
    </div>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
        <select id="filter-domain" onchange="renderTable()" class="form-input" style="border-radius: 0.75rem; font-size: 0.8125rem; min-width: 180px;">
            <option value="">All Domains</option>
            <?php foreach ($all_domains as $d): ?>
                <option value="<?= htmlspecialchars($d['domain']) ?>"><?= htmlspecialchars($d['domain']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filter-type" onchange="renderTable()" class="form-input" style="border-radius: 0.75rem; font-size: 0.8125rem; min-width: 110px;">
            <option value="">All Types</option>
            <?php foreach (['A','AAAA','CNAME','MX','TXT','NS','SRV','PTR','CAA'] as $t): ?>
                <option value="<?= $t ?>"><?= $t ?></option>
            <?php endforeach; ?>
        </select>
        <button onclick="document.getElementById('modal-add').classList.remove('hidden')"
            class="btn btn-primary" style="padding: 0.625rem 1.25rem; border-radius: 0.75rem; font-size: 0.8125rem; display: inline-flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="plus" style="width: 1rem; height: 1rem;"></i> Add Record
        </button>
        <button onclick="openLookup()"
            class="btn" style="padding: 0.625rem 1.25rem; border-radius: 0.75rem; font-size: 0.8125rem; background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.25); color: #818cf8; display: inline-flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="search" style="width: 1rem; height: 1rem;"></i> Live Lookup
        </button>
    </div>
</div>

<!-- DNS TABLE -->
<div class="glass-card" style="border-radius: 1.5rem; overflow: hidden;">
    <div style="overflow-x: auto;" class="custom-scrollbar">
        <table style="width: 100%; text-align: left; border-collapse: collapse; font-size: 0.8125rem;">
            <thead id="dns-thead" style="background: var(--bg-body); font-size: 0.625rem; font-weight: 600; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.06em;">
                <tr>
                    <th style="padding: 0.875rem 1.25rem;">Domain</th>
                    <th style="padding: 0.875rem 1.25rem;">Owner</th>
                    <th style="padding: 0.875rem 1.25rem;">Type</th>
                    <th style="padding: 0.875rem 1.25rem;">Name</th>
                    <th style="padding: 0.875rem 1.25rem;">Value</th>
                    <th style="padding: 0.875rem 1.25rem;">TTL</th>
                    <th style="padding: 0.875rem 1.25rem; text-align: right;"></th>
                </tr>
            </thead>
            <tbody id="dns-list">
                <tr><td colspan="7" style="padding: 3rem; text-align: center; color: var(--text-secondary);">Loading DNS records…</td></tr>
            </tbody>
        </table>
    </div>
    <div id="dns-footer" style="padding: 0.875rem 1.25rem; border-top: 1px solid var(--border-color); font-size: 0.75rem; color: var(--text-secondary); display: flex; align-items: center; justify-content: space-between;">
        <span id="dns-count">—</span>
        <button onclick="loadDNS()" style="font-size: 0.75rem; color: var(--primary); background: none; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.375rem;">
            <i data-lucide="refresh-cw" style="width: 0.875rem; height: 0.875rem;"></i> Refresh
        </button>
    </div>
</div>

<!-- ADD RECORD MODAL -->
<div id="modal-add" class="hidden" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 1.5rem; padding: 2rem; width: 100%; max-width: 520px; box-shadow: 0 25px 60px rgba(0,0,0,0.4);">
        <h3 style="font-size: 1.125rem; font-weight: 600; color: var(--text-primary); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
            <i data-lucide="plus-circle" style="width: 1.25rem; height: 1.25rem; color: var(--primary);"></i>
            Add DNS Record
        </h3>
        <form onsubmit="submitAdd(event)" style="display: flex; flex-direction: column; gap: 1rem;">
            <div>
                <label style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 500; text-transform: uppercase; display: block; margin-bottom: 0.35rem;">Domain</label>
                <select name="domain_id" required class="form-input" style="width: 100%; border-radius: 0.75rem;">
                    <?php foreach ($all_domains as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['domain']) ?> (<?= htmlspecialchars($d['username']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 500; text-transform: uppercase; display: block; margin-bottom: 0.35rem;">Type</label>
                    <select name="type" required class="form-input" style="width: 100%; border-radius: 0.75rem;">
                        <?php foreach (['A','AAAA','CNAME','MX','TXT','NS','SRV','PTR','CAA'] as $t): ?>
                            <option><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 500; text-transform: uppercase; display: block; margin-bottom: 0.35rem;">Name / Host</label>
                    <input name="name" placeholder="@ or subdomain" class="form-input" value="@" style="width: 100%; border-radius: 0.75rem;">
                </div>
            </div>
            <div>
                <label style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 500; text-transform: uppercase; display: block; margin-bottom: 0.35rem;">Value / Content</label>
                <input name="value" required placeholder="e.g. 1.2.3.4 or mail.example.com." class="form-input" style="width: 100%; border-radius: 0.75rem;">
            </div>
            <div>
                <label style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 500; text-transform: uppercase; display: block; margin-bottom: 0.35rem;">TTL (seconds)</label>
                <input name="ttl" type="number" value="3600" min="60" class="form-input" style="width: 100%; border-radius: 0.75rem;">
            </div>
            <div style="display: flex; gap: 0.75rem; margin-top: 0.5rem;">
                <button type="button" onclick="document.getElementById('modal-add').classList.add('hidden')"
                    class="btn" style="flex: 1; padding: 0.75rem; border-radius: 0.75rem; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: var(--text-secondary);">
                    Cancel
                </button>
                <button type="submit" id="btn-add-record" class="btn btn-primary" style="flex: 1; padding: 0.75rem; border-radius: 0.75rem;">
                    Add Record
                </button>
            </div>
        </form>
    </div>
</div>

<!-- LIVE LOOKUP MODAL -->
<div id="modal-lookup" class="hidden" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 1.5rem; padding: 2rem; width: 100%; max-width: 540px; box-shadow: 0 25px 60px rgba(0,0,0,0.4);">
        <h3 style="font-size: 1.125rem; font-weight: 600; color: var(--text-primary); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
            <i data-lucide="search" style="width: 1.25rem; height: 1.25rem; color: #818cf8;"></i>
            Live DNS Lookup
        </h3>
        <div style="display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap;">
            <input id="lookup-domain" placeholder="example.com" class="form-input" style="flex: 1; border-radius: 0.75rem; min-width: 180px;">
            <select id="lookup-type" class="form-input" style="border-radius: 0.75rem; min-width: 90px;">
                <?php foreach (['A','AAAA','MX','NS','CNAME','TXT'] as $t): ?>
                    <option><?= $t ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="runLookup()" class="btn btn-primary" style="padding: 0.625rem 1.25rem; border-radius: 0.75rem; white-space: nowrap;">
                Lookup
            </button>
        </div>
        <div id="lookup-result" style="font-family: monospace; font-size: 0.8125rem; background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); border-radius: 0.75rem; padding: 1rem; min-height: 80px; color: var(--text-primary); white-space: pre-wrap; line-height: 1.7;">
            Enter a domain and click Lookup.
        </div>
        <button onclick="document.getElementById('modal-lookup').classList.add('hidden')"
            style="margin-top: 1rem; width: 100%; padding: 0.75rem; border-radius: 0.75rem; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: var(--text-secondary); cursor: pointer;">
            Close
        </button>
    </div>
</div>

<?php include 'layout/footer.php'; ?>
<script>
    let allRecords = [];

    const typeColors = {
        A:     ['rgba(59,130,246,0.12)',  '#3b82f6'],
        AAAA:  ['rgba(99,102,241,0.12)',  '#6366f1'],
        CNAME: ['rgba(16,185,129,0.12)', '#10b981'],
        MX:    ['rgba(245,158,11,0.12)', '#f59e0b'],
        TXT:   ['rgba(168,85,247,0.12)', '#a855f7'],
        NS:    ['rgba(234,88,12,0.12)',  '#ea580c'],
        SRV:   ['rgba(20,184,166,0.12)', '#14b8a6'],
        PTR:   ['rgba(236,72,153,0.12)', '#ec4899'],
        CAA:   ['rgba(132,204,22,0.12)', '#84cc16'],
    };

    async function loadDNS() {
        const list = document.getElementById('dns-list');
        list.innerHTML = '<tr><td colspan="7" style="padding:3rem;text-align:center;color:var(--text-secondary);">Loading…</td></tr>';
        const fd = new FormData();
        fd.append('ajax_action', 'list_dns');
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                allRecords = res.data;
                renderTable();
            } else {
                list.innerHTML = `<tr><td colspan="7" style="padding:2rem;text-align:center;color:#ef4444;">${res.msg}</td></tr>`;
            }
        } catch (e) {
            list.innerHTML = '<tr><td colspan="7" style="padding:2rem;text-align:center;color:#ef4444;">Failed to load records.</td></tr>';
        }
    }

    function renderTable() {
        const list    = document.getElementById('dns-list');
        const fDomain = document.getElementById('filter-domain').value;
        const fType   = document.getElementById('filter-type').value;

        const filtered = allRecords.filter(r =>
            (!fDomain || r.domain_name === fDomain) &&
            (!fType   || r.type === fType)
        );

        document.getElementById('dns-count').textContent = `${filtered.length} record${filtered.length !== 1 ? 's' : ''} shown`;

        if (filtered.length === 0) {
            list.innerHTML = '<tr><td colspan="7" style="padding:3rem;text-align:center;color:var(--text-secondary);font-style:italic;">No DNS records found.</td></tr>';
            lucide.createIcons();
            return;
        }

        list.innerHTML = filtered.map(r => {
            const [bg, color] = typeColors[r.type] || ['rgba(100,116,139,0.1)', 'var(--text-secondary)'];
            const shortVal = r.value.length > 60 ? r.value.substring(0, 57) + '…' : r.value;
            return `
                <tr style="border-bottom:1px solid var(--border-color);transition:background 0.15s;" onmouseover="this.style.background='var(--bg-body)'" onmouseout="this.style.background='transparent'">
                    <td style="padding:0.875rem 1.25rem;font-weight:500;color:var(--text-primary);">${r.domain_name}</td>
                    <td style="padding:0.875rem 1.25rem;font-family:monospace;font-size:0.75rem;color:var(--text-secondary);">${r.username}</td>
                    <td style="padding:0.875rem 1.25rem;">
                        <span style="font-size:0.6875rem;font-weight:700;letter-spacing:0.06em;padding:0.2rem 0.6rem;border-radius:9999px;background:${bg};color:${color};border:1px solid ${color}33;">${r.type}</span>
                    </td>
                    <td style="padding:0.875rem 1.25rem;font-family:monospace;font-size:0.8125rem;color:var(--text-primary);">${r.name}</td>
                    <td style="padding:0.875rem 1.25rem;font-family:monospace;font-size:0.75rem;color:var(--text-secondary);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${r.value}">${shortVal}</td>
                    <td style="padding:0.875rem 1.25rem;color:var(--text-secondary);font-size:0.8125rem;">${r.ttl}s</td>
                    <td style="padding:0.875rem 1.25rem;text-align:right;">
                        <button onclick="delRecord(${r.id}, '${r.type}', '${r.domain_name}')" style="padding:0.375rem;border-radius:0.5rem;color:var(--accent-red);background:none;border:none;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.background='rgba(239,68,68,0.1)'" onmouseout="this.style.background='none'">
                            <i data-lucide="trash-2" style="width:0.9rem;height:0.9rem;"></i>
                        </button>
                    </td>
                </tr>`;
        }).join('');
        lucide.createIcons();
    }

    async function delRecord(id, type, domain) {
        if (!confirm(`Delete ${type} record for ${domain}?`)) return;
        const fd = new FormData();
        fd.append('ajax_action', 'del_dns');
        fd.append('id', id);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        if (res.status === 'success') {
            showToast('success', res.msg);
            loadDNS();
        } else {
            showToast('error', res.msg);
        }
    }

    async function submitAdd(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-add-record');
        btn.disabled = true;
        btn.textContent = 'Adding…';
        const fd = new FormData(e.target);
        fd.append('ajax_action', 'add_dns');
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', res.msg);
                e.target.reset();
                document.getElementById('modal-add').classList.add('hidden');
                loadDNS();
            } else {
                showToast('error', res.msg);
            }
        } catch (ex) {
            showToast('error', 'Communication error');
        }
        btn.disabled = false;
        btn.textContent = 'Add Record';
    }

    function openLookup() {
        document.getElementById('modal-lookup').classList.remove('hidden');
    }

    async function runLookup() {
        const domain = document.getElementById('lookup-domain').value.trim();
        const type   = document.getElementById('lookup-type').value;
        const result = document.getElementById('lookup-result');
        if (!domain) { result.textContent = 'Please enter a domain name.'; return; }
        result.textContent = 'Looking up ' + type + ' records for ' + domain + '…';

        const fd = new FormData();
        fd.append('ajax_action', 'live_lookup');
        fd.append('domain', domain);
        fd.append('type', type);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                const lines = Object.values(res.result);
                result.textContent = lines.length ? '→ ' + lines.join('\n→ ') : '(No records found)';
            } else {
                result.textContent = 'Error: ' + res.msg;
            }
        } catch (ex) {
            result.textContent = 'Lookup failed: ' + ex.message;
        }
    }

    // Close modals on backdrop click
    ['modal-add', 'modal-lookup'].forEach(id => {
        document.getElementById(id).addEventListener('click', e => {
            if (e.target === e.currentTarget) e.currentTarget.classList.add('hidden');
        });
    });

    document.addEventListener('DOMContentLoaded', loadDNS);
</script>
