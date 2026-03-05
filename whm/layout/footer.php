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
        toast.style.cssText = [
            'position:fixed', 'bottom:1.5rem', 'right:1.5rem', 'z-index:9999',
            'min-width:18rem', 'max-width:24rem',
            'padding:.875rem 1.125rem',
            'border-radius:.875rem',
            `box-shadow:0 20px 40px -10px rgba(0,0,0,.25)`,
            `background:var(--bg-surface)`,
            `border:1px solid ${c.border}`,
            `border-left:4px solid ${c.accent}`,
            'display:flex', 'align-items:center', 'gap:.75rem',
            'transform:translateX(120%)', 'opacity:0',
            'transition:all .35s cubic-bezier(.4,0,.2,1)',
            'font-family:inherit',
            `backdrop-filter:blur(16px)`,
        ].join(';');

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