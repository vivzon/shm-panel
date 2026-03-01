</div>
</main>
</div>

<script>
    lucide.createIcons();

    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

    // --- TOAST SYSTEM (light theme) ---
    function showToast(type, msg) {
        const colors = {
            success: 'bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-emerald-100/80',
            error: 'bg-red-50 text-red-700 border border-red-200 shadow-red-100/80',
            info: 'bg-blue-50 text-blue-700 border border-blue-200 shadow-blue-100/80',
        };
        const icons = { success: 'check-circle', error: 'alert-circle', info: 'info' };
        const toast = document.createElement('div');
        toast.className = `fixed bottom-5 right-5 z-[100] px-5 py-3.5 rounded-xl shadow-lg flex items-center gap-3 transform translate-y-4 opacity-0 transition-all duration-300 ${colors[type] || colors.info}`;
        toast.innerHTML = `<i data-lucide="${icons[type] || 'info'}" class="w-4 h-4 shrink-0"></i><span class="font-semibold text-sm">${msg}</span>`;
        document.body.appendChild(toast);
        lucide.createIcons();
        requestAnimationFrame(() => toast.classList.remove('translate-y-4', 'opacity-0'));
        setTimeout(() => {
            toast.classList.add('translate-y-4', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
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