</div>
</main>

<script>
    // Init Icons
    lucide.createIcons();

    // --- TOAST SYSTEM ---
    function showToast(type, title, message) {
        const toast = document.createElement('div');
        const colors = {
            success: 'border-left-color: #10b981;',
            error: 'border-left-color: #ef4444;',
            info: 'border-left-color: #3b82f6;',
        };
        toast.style.cssText = `position: fixed; bottom: 1.25rem; right: 1.25rem; z-index: 100; width: 24rem; padding: 1rem; border-radius: 0.75rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: 1px solid var(--border-color); display: flex; align-items: flex-start; gap: 1rem; transition: all 0.5s; transform: translateX(100%); opacity: 0; border-left-width: 4px; border-left-style: solid; background: var(--bg-surface); ${colors[type] || colors.info}`;

        const iconBgs = { success: 'background-color: #d1fae5; color: #059669;', error: 'background-color: #fee2e2; color: #dc2626;', info: 'background-color: #dbeafe; color: #2563eb;' };
        const iconNames = { success: 'check-circle', error: 'x-circle', info: 'info' };
        const iconHtml = `<div style="${iconBgs[type] || iconBgs.info} padding: 0.5rem; border-radius: 0.5rem; flex-shrink: 0;"><i data-lucide="${iconNames[type] || 'info'}" style="width: 1rem; height: 1rem;"></i></div>`;

        toast.innerHTML = `
            ${iconHtml}
            <div style="flex: 1; min-width: 0;">
                <h4 style="font-weight: 500; color: var(--text-primary); font-size: 0.875rem; margin-bottom: 0.125rem;">${title}</h4>
                <p style="font-size: 0.75rem; color: var(--text-secondary); line-height: 1.625; margin: 0;">${message}</p>
            </div>
            <button onclick="this.parentElement.remove()" style="color: var(--text-muted); flex-shrink: 0; background: transparent; border: none; cursor: pointer; transition: color 0.2s;" onmouseover="this.style.color='var(--text-secondary)'" onmouseout="this.style.color='var(--text-muted)'"><i data-lucide="x" style="width: 1rem; height: 1rem;"></i></button>
        `;

        document.body.appendChild(toast);
        lucide.createIcons({ root: toast });

        requestAnimationFrame(() => {
            toast.style.transform = 'translateX(0)';
            toast.style.opacity = '1';
        });
        setTimeout(() => {
            toast.style.transform = 'translateX(100%)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 500);
        }, 5000);
    }

    async function handleGeneric(e, action) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]') || e.target.querySelector('button');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<span class="animate-pulse">Processing...</span>`;

        const fd = new FormData(e.target);
        fd.append('ajax_action', action);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (csrfToken) fd.append('csrf_token', csrfToken);

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());

            if (res.status === 'success') {
                showToast('success', 'Success', res.msg || 'Operation completed successfully.');
                if (res.redirect) setTimeout(() => location.href = res.redirect, 1000);
                else setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', 'Error', res.msg || 'Action Failed');
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        } catch (err) {
            showToast('error', 'System Error', 'Failed to communicate with server.');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    // Helper for Smart Reload
    function forceReload() {
        window.location.reload();
    }
</script>
</body>

</html>