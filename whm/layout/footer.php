</div>
</main>
</div>

<script>
    lucide.createIcons();

    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

    // --- TOAST SYSTEM (Vanilla CSS) ---
    function showToast(type, msg) {
        const colors = {
            success: { bg: '#f0fdf4', border: '#bbf7d0', text: '#15803d', icon: 'check-circle', left: '#10b981' },
            error: { bg: '#fef2f2', border: '#fecaca', text: '#b91c1c', icon: 'alert-circle', left: '#ef4444' },
            info: { bg: '#eff6ff', border: '#bfdbfe', text: '#1d4ed8', icon: 'info', left: '#3b82f6' },
            warning: { bg: '#fffbeb', border: '#fde68a', text: '#92400e', icon: 'triangle-alert', left: '#f59e0b' },
        };
        const c = colors[type] || colors.info;
        const toast = document.createElement('div');
        toast.style.cssText = `position:fixed;bottom:1.25rem;right:1.25rem;z-index:9999;min-width:18rem;max-width:22rem;padding:.875rem 1rem;border-radius:.75rem;box-shadow:0 10px 25px -5px rgba(0,0,0,.15);border:1px solid ${c.border};border-left:4px solid ${c.left};background:${c.bg};display:flex;align-items:center;gap:.75rem;transform:translateX(110%);opacity:0;transition:all .35s cubic-bezier(.4,0,.2,1);font-family:inherit;`;
        toast.innerHTML = `<i data-lucide="${c.icon}" style="width:1rem;height:1rem;color:${c.left};flex-shrink:0;"></i><span style="font-size:.875rem;font-weight:600;color:${c.text};line-height:1.4;">${msg}</span>`;
        document.body.appendChild(toast);
        if (window.lucide) lucide.createIcons({ nodes: [toast] });
        requestAnimationFrame(() => { toast.style.transform = 'translateX(0)'; toast.style.opacity = '1'; });
        setTimeout(() => {
            toast.style.transform = 'translateX(110%)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 350);
        }, 3500);
    }

    async function handleGeneric(e, action) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<span class="animate-pulse">Processing...</span>`;

        const fd = new FormData(e.target);
        fd.append('ajax_action', action);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (csrfToken) fd.append('csrf_token', csrfToken);

        try {
            const res = await fetch('', { method: 'POST', body: fd });
            if ([502, 504].includes(res.status)) {
                btn.innerHTML = "Reloading...";
                showToast('success', 'Service Reload Triggered');
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
                btn.innerHTML = originalText;
            }
        } catch (err) {
            showToast('error', 'Server error — retrying...');
            setTimeout(() => location.reload(), 2000);
        }
    }
</script>
</body>

</html>