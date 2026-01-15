</div>
</main>

<script>
    // Init Icons
    lucide.createIcons();

    // --- TOAST SYSTEM ---
    function showToast(type, title, message) {
        const toast = document.createElement('div');
        toast.className = `fixed bottom-5 right-5 z-[100] w-96 glass-card p-4 rounded-xl shadow-2xl flex items-start gap-4 transform transition-all duration-500 translate-x-full opacity-0 border-l-4 ${type === 'success' ? 'border-l-emerald-500' : (type === 'error' ? 'border-l-red-500' : 'border-l-blue-500')}`;

        // Icon
        let iconHtml = '';
        if (type === 'success') iconHtml = `<div class="bg-emerald-500/20 text-emerald-400 p-2 rounded-lg"><i data-lucide="check-circle" class="w-5 h-5"></i></div>`;
        else if (type === 'error') iconHtml = `<div class="bg-red-500/20 text-red-400 p-2 rounded-lg"><i data-lucide="x-circle" class="w-5 h-5"></i></div>`;
        else iconHtml = `<div class="bg-blue-500/20 text-blue-400 p-2 rounded-lg"><i data-lucide="info" class="w-5 h-5"></i></div>`;

        toast.innerHTML = `
                ${iconHtml}
                <div class="flex-1">
                    <h4 class="font-bold text-white text-sm">${title}</h4>
                    <p class="text-xs text-slate-400 mt-1 leading-relaxed">${message}</p>
                </div>
                <button onclick="this.parentElement.remove()" class="text-slate-500 hover:text-white transition"><i data-lucide="x" class="w-4 h-4"></i></button>
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