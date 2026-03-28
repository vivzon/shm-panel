<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    try {
        verify_csrf();
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }

    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        $limits = $pdo->query("SELECT p.* FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = $cid")->fetch();

        if ($action == 'add_email') {
            $curr = $pdo->query("SELECT COUNT(*) FROM mail_users WHERE domain_id IN (SELECT id FROM mail_domains WHERE domain IN (SELECT domain FROM domains WHERE client_id = $cid))")->fetchColumn();
            if ($curr >= $limits['max_emails'])
                throw new Exception("Email limit reached.");

            $client_user = $pdo->query("SELECT username FROM clients WHERE id = $cid")->fetchColumn();
            $email = $_POST['user'] . "@" . $_POST['domain'];
            cmd("mail-tool add-user " . escapeshellarg($client_user) . " " . escapeshellarg($email) . " " . escapeshellarg($_POST['pass']));
            echo json_encode($res);
            exit;
        }

        if ($action == 'delete_email') {
            $email = $_POST['email'];
            $check = $pdo->prepare("SELECT m.id FROM mail_users m JOIN mail_domains md ON m.domain_id = md.id JOIN domains d ON md.domain = d.domain WHERE m.email = ? AND d.client_id = ?");
            $check->execute([$email, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");
            cmd("mail-tool delete-user " . escapeshellarg($email));
            echo json_encode($res);
            exit;
        }

        if ($action == 'reset_mail_pass') {
            $email = $_POST['email'];
            $pass  = $_POST['new_pass'];
            $check = $pdo->prepare("SELECT m.id FROM mail_users m JOIN mail_domains md ON m.domain_id = md.id JOIN domains d ON md.domain = d.domain WHERE m.email = ? AND d.client_id = ?");
            $check->execute([$email, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");
            cmd("mail-tool reset-pass " . escapeshellarg($email) . " " . escapeshellarg($pass));
            echo json_encode($res);
            exit;
        }

        if ($action == 'get_dns_records') {
            $domain = $_POST['domain'];
            // Security check: ensure domain belongs to client
            $check = $pdo->prepare("SELECT id FROM domains WHERE domain = ? AND client_id = ?");
            $check->execute([$domain, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");
            
            $output = cmd("dns-tool get-records " . escapeshellarg($domain));
            echo json_encode(['status' => 'success', 'data' => $output]);
            exit;
        }

        if ($action == 'generate_dkim') {
            $domain = $_POST['domain'];
            $check = $pdo->prepare("SELECT id FROM domains WHERE domain = ? AND client_id = ?");
            $check->execute([$domain, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");
            $output = cmd("dns-tool gen-dkim " . escapeshellarg($domain));
            echo json_encode(['status' => 'success', 'msg' => $output]);
            exit;
        }

        if ($action == 'get_mail_config') {
            $domain = $_POST['domain'];
            $check = $pdo->prepare("SELECT id FROM domains WHERE domain = ? AND client_id = ?");
            $check->execute([$domain, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");

            $server_ip = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());

            // Live DNS checks
            $mx_recs  = @dns_get_record($domain, DNS_MX)  ?: [];
            $txt_recs = @dns_get_record($domain, DNS_TXT) ?: [];
            $dkim_rec = @dns_get_record('mail._domainkey.' . $domain, DNS_TXT) ?: [];

            $spf_current = $dmarc_current = $dkim_current = null;
            foreach ($txt_recs as $r) {
                if (str_starts_with($r['txt'] ?? '', 'v=spf1'))   $spf_current   = $r['txt'];
                if (str_starts_with($r['txt'] ?? '', 'v=DMARC1')) $dmarc_current = $r['txt'];
            }
            if ($dkim_rec) $dkim_current = $dkim_rec[0]['txt'] ?? null;

            // Try to get DKIM public key from shm-manage
            $dkim_pubkey = '';
            try { $dkim_pubkey = cmd("dns-tool get-dkim " . escapeshellarg($domain)); } catch(Throwable $e) {}

            echo json_encode([
                'status'      => 'success',
                'domain'      => $domain,
                'server_ip'   => $server_ip,
                'mx'          => ['records' => array_column($mx_recs, 'target'), 'suggested' => 'mail.' . $domain, 'ok' => !empty($mx_recs)],
                'spf'         => ['current' => $spf_current,   'suggested' => "v=spf1 ip4:$server_ip a mx ~all", 'ok' => $spf_current !== null],
                'dkim'        => ['current' => $dkim_current,  'pubkey' => trim($dkim_pubkey),                      'ok' => $dkim_current !== null],
                'dmarc'       => ['current' => $dmarc_current, 'suggested' => "v=DMARC1; p=quarantine; rua=mailto:dmarc@$domain; fo=1", 'ok' => $dmarc_current !== null],
            ]);
            exit;
        }

        if ($action == 'apply_spf') {
            $domain = $_POST['domain']; $value = trim($_POST['value']);
            $check = $pdo->prepare("SELECT id FROM domains WHERE domain = ? AND client_id = ?");
            $check->execute([$domain, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");
            cmd("dns-tool set-txt " . escapeshellarg($domain) . " " . escapeshellarg($value));
            echo json_encode(['status' => 'success', 'msg' => 'SPF record applied.']);
            exit;
        }

        if ($action == 'apply_dmarc') {
            $domain = $_POST['domain']; $value = trim($_POST['value']);
            $check = $pdo->prepare("SELECT id FROM domains WHERE domain = ? AND client_id = ?");
            $check->execute([$domain, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");
            cmd("dns-tool set-txt _dmarc." . escapeshellarg($domain) . " " . escapeshellarg($value));
            echo json_encode(['status' => 'success', 'msg' => 'DMARC record applied.']);
            exit;
        }

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// Pagination
$page     = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$per_page = 10;
$offset   = ($page - 1) * $per_page;

$total_emails = $pdo->query("SELECT COUNT(*) FROM mail_users mu JOIN mail_domains md ON mu.domain_id = md.id WHERE md.domain IN (SELECT domain FROM domains WHERE client_id = $cid)")->fetchColumn();
$total_pages  = ceil($total_emails / $per_page);

$domains   = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();
$my_emails = $pdo->query("SELECT mu.* FROM mail_users mu JOIN mail_domains md ON mu.domain_id = md.id WHERE md.domain IN (SELECT domain FROM domains WHERE client_id = $cid) LIMIT $per_page OFFSET $offset")->fetchAll();

$server_host = $_SERVER['HTTP_HOST'];
$parts = explode('.', $server_host);
$base_domain = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $server_host;

include 'layout/header.php';
?>

<div style="display:flex;flex-direction:column;gap:2.5rem;">

    <!-- Page Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:1rem;border-bottom:1px solid var(--border-color);padding-bottom:1.5rem;">
        <div>
            <h2 style="font-size:1.875rem;font-weight:500;color:var(--text-primary);font-family:'Lexend',sans-serif;letter-spacing:-0.025em;margin-bottom:0.5rem;">Email Accounts</h2>
            <p style="color:var(--text-secondary);">Create and manage mailboxes for your domains.</p>
        </div>
        <div style="font-size:0.8125rem;color:var(--text-muted);">
            <span style="font-weight:600;color:var(--text-primary);"><?= $total_emails ?></span> / <?= $limits['max_emails'] ?? '∞' ?> accounts used
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:2rem;">
        <!-- CREATE EMAIL -->
        <div class="glass-card" style="padding:2rem;">
            <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);font-family:var(--font-heading);margin-bottom:1.5rem;display:flex;align-items:center;gap:0.5rem;">
                <i data-lucide="mail-plus" style="width:18px;height:18px;color:var(--primary);"></i>
                Create Mailbox
            </h3>
            <form onsubmit="handleAddEmail(event)" style="display:flex;flex-direction:column;gap:1rem;" id="form-add-email">
                <?= csrf_field() ?>
                <div>
                    <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.375rem;">Mailbox Name</label>
                    <input name="user" required placeholder="e.g. info, support" class="form-input">
                </div>
                <div>
                    <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.375rem;">Domain</label>
                    <select name="domain" class="form-input form-select" style="appearance:none;">
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= $d['domain'] ?>">@<?= htmlspecialchars($d['domain']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.375rem;">Password</label>
                    <input name="pass" type="password" required placeholder="••••••••" class="form-input">
                </div>
                <button class="btn btn-primary" style="width:100%;height:var(--input-height-md);gap:0.5rem;">
                    <i data-lucide="plus-circle" style="width:1rem;height:1rem;"></i> Create Mailbox
                </button>
            </form>
        </div>

        <!-- MAIL CONFIG CARD -->
        <div class="glass-card" style="padding:2rem;display:flex;flex-direction:column;gap:1.25rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);font-family:var(--font-heading);display:flex;align-items:center;gap:0.5rem;">
                    <i data-lucide="shield-check" style="width:18px;height:18px;color:#10b981;"></i> Mail Configuration
                </h3>
                <span style="font-size:0.6875rem;color:var(--text-muted);">MX · SPF · DKIM · DMARC</span>
            </div>
            <div>
                <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.4rem;">Domain</label>
                <select id="dns-domain-select" class="form-input form-select">
                    <?php foreach ($domains as $d): ?>
                        <option value="<?= $d['domain'] ?>"><?= htmlspecialchars($d['domain']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Quick status row -->
            <div id="mail-status-row" style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.5rem;">
                <?php foreach (['MX','SPF','DKIM','DMARC'] as $t): ?>
                <div style="text-align:center;padding:0.75rem 0.25rem;background:var(--bg-body);border-radius:0.5rem;border:1px solid var(--border-color);">
                    <div style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;margin-bottom:0.35rem;"><?= $t ?></div>
                    <div id="status-<?= strtolower($t) ?>" style="width:10px;height:10px;border-radius:50%;background:#cbd5e1;margin:0 auto;"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
                <button onclick="openMailConfig()" class="btn btn-primary" style="font-size:0.8125rem;">
                    <i data-lucide="settings-2" style="width:14px;height:14px;"></i> Configure
                </button>
                <button onclick="generateDkim()" class="btn btn-secondary" style="font-size:0.8125rem;">
                    <i data-lucide="key" style="width:14px;height:14px;"></i> Gen DKIM
                </button>
            </div>
            <p style="font-size:0.7rem;color:var(--text-muted);background:var(--bg-body);padding:0.6rem 0.75rem;border-radius:var(--radius-md);border:1px solid var(--border-color);margin:0;">
                <strong>Tip:</strong> If using external DNS (Cloudflare etc.), copy the values and add them manually.
            </p>
        </div>
    </div>

    <!-- LIST -->
    <div class="glass-card table-card" style="padding:0;overflow:hidden;">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border-color);background:var(--bg-body);display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-weight:700;color:var(--text-primary);font-family:var(--font-heading);">Active Email Accounts</h3>
            <button onclick="location.reload()" style="color:var(--text-muted);background:transparent;border:none;cursor:pointer;padding:0.5rem;border-radius:var(--radius-md);transition:all 0.2s;" onmouseover="this.style.color='var(--text-primary)';this.style.backgroundColor='var(--bg-body)';" onmouseout="this.style.color='var(--text-muted)';this.style.backgroundColor='transparent';">
                <i data-lucide="refresh-cw" style="width:1rem;height:1rem;"></i>
            </button>
        </div>
        <div class="table-container custom-scrollbar">
            <table style="width:100%;border-collapse:collapse;">
                <thead style="background:var(--bg-body);font-size:0.75rem;text-transform:uppercase;color:var(--text-secondary);font-weight:700;letter-spacing:0.05em;border-bottom:1px solid var(--border-color);">
                    <tr>
                        <th style="padding:1rem 1.5rem;text-align:left;">Email Address</th>
                        <th style="padding:1rem 1.5rem;text-align:right;">Webmail / Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($my_emails)): ?>
                        <tr>
                            <td colspan="2" style="text-align:center;padding:3rem 1.5rem;color:var(--text-muted);">
                                <div style="display:flex;flex-direction:column;align-items:center;gap:0.5rem;">
                                    <i data-lucide="mail" style="width:48px;height:48px;opacity:0.4;"></i>
                                    <span>No email accounts yet. Create your first mailbox above.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: foreach ($my_emails as $mail): ?>
                        <tr style="border-bottom:1px solid var(--border-color);transition:background-color 0.2s;" onmouseover="this.style.backgroundColor='var(--bg-body)'" onmouseout="this.style.backgroundColor='transparent'">
                            <td style="padding:1rem 1.5rem;">
                                <div style="font-weight:600;color:var(--text-primary);font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;">
                                    <i data-lucide="mail" style="width:1rem;height:1rem;color:var(--text-muted);"></i>
                                    <?= htmlspecialchars($mail['email']) ?>
                                </div>
                            </td>
                            <td style="padding:1rem 1.5rem;text-align:right;">
                                <div style="display:flex;align-items:center;justify-content:flex-end;gap:0.5rem;">
                                    <a href="http://webmail.<?= $base_domain ?>" target="_blank"
                                        style="font-size:0.75rem;font-weight:600;color:var(--primary);text-decoration:none;display:flex;align-items:center;gap:0.25rem;padding:0.375rem 0.75rem;border:1px solid rgba(37,99,235,0.25);border-radius:var(--radius-md);background:rgba(37,99,235,0.05);transition:all 0.2s;"
                                        onmouseover="this.style.backgroundColor='rgba(37,99,235,0.15)';" onmouseout="this.style.backgroundColor='rgba(37,99,235,0.05)';">
                                        <i data-lucide="external-link" style="width:12px;height:12px;"></i> Webmail
                                    </a>
                                    <button onclick="resetMailPass('<?= htmlspecialchars($mail['email'], ENT_QUOTES) ?>')"
                                        style="padding:0.5rem;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);border-radius:var(--radius-md);cursor:pointer;color:var(--accent-amber);transition:all 0.2s;display:flex;align-items:center;"
                                        title="Reset Password"
                                        onmouseover="this.style.backgroundColor='rgba(245,158,11,0.2)';" onmouseout="this.style.backgroundColor='rgba(245,158,11,0.1)';">
                                        <i data-lucide="key" style="width:16px;height:16px;"></i>
                                    </button>
                                    <button onclick="deleteEmail('<?= htmlspecialchars($mail['email'], ENT_QUOTES) ?>')"
                                        style="padding:0.5rem;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);border-radius:var(--radius-md);cursor:pointer;color:var(--accent-red);transition:all 0.2s;display:flex;align-items:center;"
                                        title="Delete Mailbox"
                                        onmouseover="this.style.backgroundColor='rgba(239,68,68,0.2)';" onmouseout="this.style.backgroundColor='rgba(239,68,68,0.1)';">
                                        <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:0.75rem;color:var(--text-secondary);">Page <?= $page ?> of <?= $total_pages ?></div>
        <div style="display:flex;gap:0.5rem;">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>" class="btn btn-secondary" style="font-size:0.75rem;">Previous</a>
            <?php endif; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?>" class="btn btn-secondary" style="font-size:0.75rem;">Next</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'layout/footer.php'; ?>

<!-- Mail Config Modal (tabbed) -->
<div id="mailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(6px);">
  <div class="glass-card" style="width:100%;max-width:720px;max-height:92vh;overflow:hidden;display:flex;flex-direction:column;padding:0;">

    <!-- Header -->
    <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;background:var(--bg-body);">
      <h3 style="font-weight:700;color:var(--text-primary);font-family:var(--font-heading);display:flex;align-items:center;gap:0.5rem;">
        <i data-lucide="mail-check" style="width:18px;height:18px;color:#6366f1;"></i>
        Mail Config — <span id="mc-domain" style="color:#6366f1;"></span>
      </h3>
      <button onclick="document.getElementById('mailModal').style.display='none'" style="background:transparent;border:none;color:var(--text-secondary);cursor:pointer;"><i data-lucide="x"></i></button>
    </div>

    <!-- Tabs -->
    <div style="display:flex;gap:0;border-bottom:1px solid var(--border-color);background:var(--bg-body);">
      <?php foreach (['mx'=>'Server (MX)','spf'=>'SPF','dkim'=>'DKIM','dmarc'=>'DMARC'] as $tid=>$tlabel): ?>
      <button id="tab-<?= $tid ?>" onclick="switchTab('<?= $tid ?>')" style="padding:0.75rem 1.25rem;border:none;background:transparent;font-size:0.875rem;font-weight:600;color:var(--text-secondary);cursor:pointer;border-bottom:2px solid transparent;transition:all 0.2s;"
        onmouseover="this.style.color='var(--text-primary)'" onmouseout="if(currentTab!='<?= $tid ?>')this.style.color='var(--text-secondary)'">
        <?= $tlabel ?>
      </button>
      <?php endforeach; ?>
    </div>

    <!-- Body -->
    <div style="flex:1;overflow-y:auto;padding:1.5rem;" id="mc-body">
      <div style="text-align:center;padding:3rem;color:var(--text-muted);">
        <i data-lucide="loader-2" style="width:32px;height:32px;animation:spin 1s linear infinite;"></i>
        <p style="margin-top:1rem;">Loading DNS records…</p>
      </div>
    </div>

    <!-- Footer -->
    <div style="padding:1rem 1.5rem;border-top:1px solid var(--border-color);background:var(--bg-body);display:flex;justify-content:space-between;align-items:center;">
      <span style="font-size:0.75rem;color:var(--text-muted);">✅ = record found in live DNS &nbsp; ⚠️ = missing or incorrect</span>
      <button class="btn btn-secondary" style="font-size:0.8125rem;" onclick="document.getElementById('mailModal').style.display='none'">Close</button>
    </div>
  </div>
</div>

<script>
function getCsrf(){return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||document.querySelector('input[name="csrf_token"]')?.value||'';}

let currentTab='mx', mailData=null;

async function openMailConfig(){
    const dom=document.getElementById('dns-domain-select').value;
    document.getElementById('mc-domain').textContent=dom;
    document.getElementById('mailModal').style.display='flex';
    document.getElementById('mc-body').innerHTML='<div style="text-align:center;padding:3rem;color:var(--text-muted);"><i data-lucide="loader-2" style="width:32px;height:32px;animation:spin 1s linear infinite;"></i><p style="margin-top:1rem;">Checking DNS…</p></div>';
    lucide.createIcons();
    const fd=new FormData();
    fd.append('ajax_action','get_mail_config');
    fd.append('domain',dom);
    fd.append('csrf_token',getCsrf());
    try{
        const res=await fetch('',{method:'POST',body:fd}).then(r=>r.json());
        if(res.status==='success'){
            mailData=res;
            updateStatusDots(res);
            switchTab('mx');
        } else {
            document.getElementById('mc-body').innerHTML='<div style="color:var(--accent-red);padding:1rem;">'+res.msg+'</div>';
        }
    }catch(e){document.getElementById('mc-body').innerHTML='<div style="color:var(--accent-red);padding:1rem;">Failed to load config.</div>';}
}

function updateStatusDots(d){
    const map={mx:d.mx.ok,spf:d.spf.ok,dkim:d.dkim.ok,dmarc:d.dmarc.ok};
    for(const[k,ok] of Object.entries(map)){
        const el=document.getElementById('status-'+k);
        if(el) el.style.background= ok ? '#10b981' : '#ef4444';
    }
}

function switchTab(tab){
    currentTab=tab;
    ['mx','spf','dkim','dmarc'].forEach(t=>{
        const btn=document.getElementById('tab-'+t);
        if(btn){btn.style.borderBottomColor=t===tab?'#6366f1':'transparent';btn.style.color=t===tab?'#6366f1':'';}
    });
    renderTab(tab);
}

function badge(ok){return ok?'<span style="background:#dcfce7;color:#166534;font-size:0.65rem;font-weight:700;padding:0.15rem 0.5rem;border-radius:9999px;">✓ Found</span>':'<span style="background:#fee2e2;color:#991b1b;font-size:0.65rem;font-weight:700;padding:0.15rem 0.5rem;border-radius:9999px;">⚠ Missing</span>';}
function mono(v){return v?`<pre style="background:var(--bg-body);border:1px solid var(--border-color);border-radius:0.5rem;padding:0.75rem;font-size:0.75rem;overflow-x:auto;white-space:pre-wrap;word-break:break-all;">${escH(v)}</pre>`:'<em style="color:var(--text-muted);font-size:0.8125rem;">None detected</em>';}
function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function copyText(id){const el=document.getElementById(id);if(!el)return;navigator.clipboard.writeText(el.value||el.textContent).then(()=>showToast('success','Copied','Value copied to clipboard.'));}

function renderTab(tab){
    if(!mailData){return;}
    const d=mailData;
    const body=document.getElementById('mc-body');
    let h='';

    if(tab==='mx'){
        const mxList=d.mx.records.length?d.mx.records.map(r=>'<div style="padding:0.4rem 0.75rem;background:var(--bg-body);border-radius:0.4rem;font-family:monospace;font-size:0.8125rem;">'+escH(r)+'</div>').join(''):'<em style="color:var(--text-muted);">No MX records found</em>';
        h=`<div style="display:flex;flex-direction:column;gap:1.25rem;">
          <div style="display:flex;align-items:center;gap:0.75rem;"><h4 style="font-weight:700;color:var(--text-primary);">MX Records</h4>${badge(d.mx.ok)}</div>
          <div><div style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.5rem;text-transform:uppercase;">Current Records</div>${mxList}</div>
          <div style="background:var(--bg-body);border:1px solid var(--border-color);border-radius:0.75rem;padding:1rem;">
            <div style="font-size:0.75rem;font-weight:700;color:var(--text-secondary);margin-bottom:0.5rem;">📋 Suggested Record</div>
            <div style="display:flex;gap:0.5rem;align-items:center;">
              <input id="mx-val" class="form-input" style="flex:1;font-family:monospace;font-size:0.8125rem;" value="mail.${escH(d.domain)}" readonly>
              <button onclick="copyText('mx-val')" class="btn btn-secondary" style="font-size:0.75rem;">Copy</button>
            </div>
            <p style="font-size:0.7rem;color:var(--text-muted);margin-top:0.5rem;">Host: @ (or your domain) → Priority: 10 → Value: mail.${escH(d.domain)}</p>
          </div>
        </div>`;
    }

    if(tab==='spf'){
        h=`<div style="display:flex;flex-direction:column;gap:1.25rem;">
          <div style="display:flex;align-items:center;gap:0.75rem;"><h4 style="font-weight:700;color:var(--text-primary);">SPF Record</h4>${badge(d.spf.ok)}</div>
          <div><div style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.5rem;text-transform:uppercase;">Current</div>${mono(d.spf.current)}</div>
          <div style="background:var(--bg-body);border:1px solid var(--border-color);border-radius:0.75rem;padding:1rem;">
            <div style="font-size:0.75rem;font-weight:700;color:var(--text-secondary);margin-bottom:0.5rem;">✏️ Suggested Value (editable)</div>
            <textarea id="spf-val" class="form-input" style="width:100%;font-family:monospace;font-size:0.8125rem;min-height:60px;">${escH(d.spf.suggested)}</textarea>
            <div style="display:flex;gap:0.5rem;margin-top:0.75rem;">
              <button onclick="copyText('spf-val')" class="btn btn-secondary" style="font-size:0.75rem;">Copy</button>
              <button onclick="applyRecord('spf')" class="btn btn-primary" style="font-size:0.75rem;">Apply via Panel</button>
            </div>
            <p style="font-size:0.7rem;color:var(--text-muted);margin-top:0.5rem;">Host: @ · Type: TXT</p>
          </div>
        </div>`;
    }

    if(tab==='dkim'){
        const dkimHost=`mail._domainkey.${d.domain}`;
        h=`<div style="display:flex;flex-direction:column;gap:1.25rem;">
          <div style="display:flex;align-items:center;gap:0.75rem;"><h4 style="font-weight:700;color:var(--text-primary);">DKIM Key</h4>${badge(d.dkim.ok)}</div>
          <div><div style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.5rem;text-transform:uppercase;">Current Key in DNS</div>${mono(d.dkim.current)}</div>
          ${d.dkim.pubkey?`<div style="background:var(--bg-body);border:1px solid var(--border-color);border-radius:0.75rem;padding:1rem;">
            <div style="font-size:0.75rem;font-weight:700;color:var(--text-secondary);margin-bottom:0.5rem;">📋 Public Key (add to DNS)</div>
            <textarea id="dkim-val" class="form-input" style="width:100%;font-family:monospace;font-size:0.75rem;min-height:80px;" readonly>${escH(d.dkim.pubkey)}</textarea>
            <div style="display:flex;gap:0.5rem;margin-top:0.75rem;">
              <button onclick="copyText('dkim-val')" class="btn btn-secondary" style="font-size:0.75rem;">Copy</button>
            </div>
            <p style="font-size:0.7rem;color:var(--text-muted);margin-top:0.5rem;">Host: <strong>${escH(dkimHost)}</strong> · Type: TXT</p>
          </div>`:''}
          <button onclick="generateDkim()" class="btn btn-secondary" style="font-size:0.875rem;">
            <i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Regenerate DKIM Keys
          </button>
        </div>`;
    }

    if(tab==='dmarc'){
        h=`<div style="display:flex;flex-direction:column;gap:1.25rem;">
          <div style="display:flex;align-items:center;gap:0.75rem;"><h4 style="font-weight:700;color:var(--text-primary);">DMARC Policy</h4>${badge(d.dmarc.ok)}</div>
          <div><div style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.5rem;text-transform:uppercase;">Current</div>${mono(d.dmarc.current)}</div>
          <div style="background:var(--bg-body);border:1px solid var(--border-color);border-radius:0.75rem;padding:1rem;">
            <div style="font-size:0.75rem;font-weight:700;color:var(--text-secondary);margin-bottom:0.5rem;">✏️ Suggested Value (editable)</div>
            <textarea id="dmarc-val" class="form-input" style="width:100%;font-family:monospace;font-size:0.8125rem;min-height:60px;">${escH(d.dmarc.suggested)}</textarea>
            <div style="display:flex;gap:0.5rem;margin-top:0.75rem;">
              <button onclick="copyText('dmarc-val')" class="btn btn-secondary" style="font-size:0.75rem;">Copy</button>
              <button onclick="applyRecord('dmarc')" class="btn btn-primary" style="font-size:0.75rem;">Apply via Panel</button>
            </div>
            <p style="font-size:0.7rem;color:var(--text-muted);margin-top:0.5rem;">Host: <strong>_dmarc.${escH(d.domain)}</strong> · Type: TXT</p>
          </div>
        </div>`;
    }

    body.innerHTML=h; lucide.createIcons();
}

async function applyRecord(type){
    const val=document.getElementById(type+'-val')?.value;
    if(!val)return;
    const fd=new FormData();
    fd.append('ajax_action','apply_'+type);
    fd.append('domain',mailData.domain);
    fd.append('value',val);
    fd.append('csrf_token',getCsrf());
    try{
        const res=await fetch('',{method:'POST',body:fd}).then(r=>r.json());
        showToast(res.status==='success'?'success':'error',res.status==='success'?'Applied':'Failed',res.msg);
        if(res.status==='success') openMailConfig();
    }catch(e){showToast('error','Error','Request failed.');}
}

async function generateDkim(){
    const dom=document.getElementById('dns-domain-select').value;
    if(!confirm('Generate new DKIM keys for '+dom+'? This overwrites existing keys.'))return;
    const fd=new FormData();
    fd.append('ajax_action','generate_dkim');
    fd.append('domain',dom);
    fd.append('csrf_token',getCsrf());
    try{
        const res=await fetch('',{method:'POST',body:fd}).then(r=>r.json());
        showToast(res.status==='success'?'success':'error',res.status==='success'?'DKIM Generated':'Failed',res.msg);
        if(mailData) openMailConfig();
    }catch(e){showToast('error','Error','Request failed.');}
}

async function handleAddEmail(e){
    e.preventDefault();
    const form=e.target,btn=form.querySelector('button'),orig=btn.innerHTML;
    btn.disabled=true;
    btn.innerHTML='<i data-lucide="loader-2" style="width:1rem;height:1rem;animation:spin 1s linear infinite;display:inline-block;margin-right:0.3rem;"></i>Creating…';
    lucide.createIcons();
    const fd=new FormData(form);
    fd.append('ajax_action','add_email');
    try{
        const res=await fetch('',{method:'POST',body:fd}).then(r=>r.json());
        if(res.status==='success'){showToast('success','Mailbox Created',res.msg||'Done');setTimeout(()=>location.reload(),1000);}
        else showToast('error','Failed',res.msg||'Could not create mailbox.');
    }catch(err){showToast('error','Error','Server connection failed.');}
    finally{btn.disabled=false;btn.innerHTML=orig;}
}

async function deleteEmail(email){
    if(!confirm('Delete '+email+'? This is permanent.'))return;
    const fd=new FormData();
    fd.append('ajax_action','delete_email');fd.append('email',email);fd.append('csrf_token',getCsrf());
    try{
        const res=await fetch('',{method:'POST',body:fd}).then(r=>r.json());
        if(res.status==='success'){showToast('success','Deleted','Mailbox removed.');setTimeout(()=>location.reload(),800);}
        else showToast('error','Failed',res.msg);
    }catch(e){showToast('error','Error','Deletion failed.');}
}

async function resetMailPass(email){
    const newPass=prompt('New password for '+email+':');
    if(!newPass)return;
    const fd=new FormData();
    fd.append('ajax_action','reset_mail_pass');fd.append('email',email);fd.append('new_pass',newPass);fd.append('csrf_token',getCsrf());
    try{
        const res=await fetch('',{method:'POST',body:fd}).then(r=>r.json());
        showToast(res.status==='success'?'success':'error',res.status==='success'?'Password Updated':'Failed',res.msg||'Done');
    }catch(e){showToast('error','Error','Reset failed.');}
}
</script>