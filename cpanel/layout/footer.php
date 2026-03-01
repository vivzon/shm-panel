</div>
</main>

<script>
    // Init Icons
    lucide.createIcons();

    // --- TOAST SYSTEM ---
    function showToast(type, title, message) {
        const toast = document.createElement('div');
        const colors = {
            success: 'border-l-emerald-500 bg-white',
            error: 'border-l-red-500 bg-white',
            info: 'border-l-blue-500 bg-white',
        };
        toast.className = `fixed bottom-5 right-5 z-[100] w-96 p-4 rounded-xl shadow-lg border border-slate-200 flex items-start gap-4 transform transition-all duration-500 translate-x-full opacity-0 border-l-4 ${colors[type] || colors.info}`;

        const iconBgs = { success: 'bg-emerald-100 text-emerald-600', error: 'bg-red-100 text-red-600', info: 'bg-blue-100 text-blue-600' };
        const iconNames = { success: 'check-circle', error: 'x-circle', info: 'info' };
        const iconHtml = `<div class="${iconBgs[type] || iconBgs.info} p-2 rounded-lg shrink-0"><i data-lucide="${iconNames[type] || 'info'}" class="w-4 h-4"></i></div>`;

        toast.innerHTML = `
            ${iconHtml}
            <div class="flex-1 min-w-0">
                <h4 class="font-bold text-slate-800 text-sm">${title}</h4>
                <p class="text-xs text-slate-500 mt-0.5 leading-relaxed">${message}</p>
            </div>
            <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600 transition shrink-0"><i data-lucide="x" class="w-4 h-4"></i></button>
        `;

        document.body.appendChild(toast);
        lucide.createIcons({ root: toast });

        requestAnimationFrame(() => toast.classList.remove('translate-x-full', 'opacity-0'));
        setTimeout(() => {
            toast.classList.add('translate-x-full', 'opacity-0');
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