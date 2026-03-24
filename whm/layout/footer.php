</div><!-- /.main-scroll-area -->
</main>
</div><!-- /.flex body wrapper -->

<script>
    lucide.createIcons();

    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.add('hidden');
    }

    // ── Toast Notification System ─────────────────────────────
    function showToast(type, msg) {
        const cfg = {
            success: { bg: 'rgba(16, 185, 129, 0.1)', border: 'rgba(16, 185, 129, 0.3)', accent: 'var(--accent-emerald)', icon: 'check-circle' },
            error: { bg: 'rgba(239, 68, 68, 0.1)', border: 'rgba(239, 68, 68, 0.3)', accent: 'var(--accent-red)', icon: 'alert-circle' },
            info: { bg: 'rgba(59, 130, 246, 0.1)', border: 'rgba(59, 130, 246, 0.3)', accent: 'var(--primary)', icon: 'info' },
            warning: { bg: 'rgba(245, 158, 11, 0.1)', border: 'rgba(245, 158, 11, 0.3)', accent: 'var(--accent-amber)', icon: 'triangle-alert' },
        };
        const c = cfg[type] || cfg.info;

        const toast = document.createElement('div');
        const colors = {
            success: 'border-left-color: #10b981;',
            error: 'border-left-color: #ef4444;',
            info: 'border-left-color: #3b82f6;',
            warning: 'border-left-color: #f59e0b;', // Added warning color
        };
        toast.style.cssText = `position: fixed; bottom: 1.25rem; right: 1.25rem; z-index: 100; width: 24rem; padding: 1rem; border-radius: 0.75rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border: 1px solid var(--border-color); display: flex; align-items: flex-start; gap: 1rem; transition: all 0.5s cubic-bezier(.4,0,.2,1); transform: translateX(100%); opacity: 0; border-left-width: 4px; border-left-style: solid; background: var(--bg-surface); font-family:inherit; backdrop-filter:blur(16px); ${colors[type] || colors.info}`;

        toast.innerHTML = `
            <div style="width:2rem;height:2rem;border-radius:.5rem;background:${c.bg};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i data-lucide="${c.icon}" style="width:1rem;height:1rem;color:${c.accent};"></i>
            </div>
            <span style="font-size:.8125rem;font-weight:600;color:var(--text-primary);line-height:1.4;flex:1;">${msg}</span>
            <button onclick="this.closest('div[style]').style.transform='translateX(120%)';this.closest('div[style]').style.opacity='0';" style="background:none;border:none;color:var(--text-muted);cursor:pointer;padding:.25rem;border-radius:.25rem;display:flex;align-items:center;" title="Dismiss">
                <i data-lucide="x" style="width:.875rem;height:.875rem;"></i>
            </button>
        `;

        document.body.appendChild(toast);
        if (window.lucide) lucide.createIcons({ nodes: [toast] });

        // Animate in
        requestAnimationFrame(() => {
            toast.style.transform = 'translateX(0)';
            toast.style.opacity = '1';
        });

        // Auto-dismiss after 4s
        setTimeout(() => {
            toast.style.transform = 'translateX(120%)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 400);
        }, 4000);
    }

    // ── Generic AJAX form handler (used on many pages) ─────────
    async function handleGeneric(e, action) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader" style="width:1rem;height:1rem;animation:spin 1s linear infinite;"></i> Processing…`;
        lucide.createIcons({ nodes: [btn] });

        const fd = new FormData(e.target);
        fd.append('ajax_action', action);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (csrfToken) fd.append('csrf_token', csrfToken);

        try {
            const res = await fetch('', { method: 'POST', body: fd });
            if ([502, 504].includes(res.status)) {
                showToast('success', 'Service reload triggered — refreshing…');
                setTimeout(() => location.reload(), 2000);
                return;
            }
            const data = await res.json();
            if (data.status === 'success') {
                showToast('success', data.msg || 'Operation Successful');
                if (data.redirect) setTimeout(() => location.href = data.redirect, 1000);
                else setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', data.msg || 'Action Failed');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        } catch (err) {
            showToast('error', 'Server error — retrying…');
            setTimeout(() => location.reload(), 2000);
        }
    }
</script>
</body>

</html>