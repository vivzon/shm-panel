</div>
</main>

<script>
    // Init Icons
    lucide.createIcons();

    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

    // --- TOAST SYSTEM ---
    function showToast(type, msg) {
        const toast = document.createElement('div');
        toast.className = `fixed bottom-5 right-5 z-[100] px-6 py-4 rounded-xl shadow-2xl flex items-center gap-3 transform translate-y-10 opacity-0 transition-all duration-300 ${type === 'success' ? 'bg-emerald-600 text-white shadow-emerald-900/50' : 'bg-red-600 text-white shadow-red-900/50'}`;
        toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'alert-circle'}" class="w-5 h-5"></i> <span class="font-bold">${msg}</span>`;
        document.body.appendChild(toast);
        lucide.createIcons();

        requestAnimationFrame(() => toast.classList.remove('translate-y-10', 'opacity-0'));
        setTimeout(() => {
            toast.classList.add('translate-y-10', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    async function handleGeneric(e, action) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<span class="animate-pulse">Processing...</span>`;

        const fd = new FormData(e.target);
        fd.append('ajax_action', action);

        try {
            const res = await fetch('', { method: 'POST', body: fd });

            // Handle 502/504 Service Reloads
            if ([502, 504].includes(res.status)) {
                btn.innerHTML = "Reloading Node...";
                showToast('success', 'Service Reload Triggered');
                setTimeout(() => location.reload(), 2000);
                return;
            }

            const data = await res.json();

            if (data.status === 'success') {
                showToast('success', 'Operation Successful');
                if (data.redirect) setTimeout(() => location.href = data.redirect, 1000);
                else setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', data.msg || 'Action Failed');
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        } catch (err) {
            showToast('error', 'Server Error or Service Restarting...');
            setTimeout(() => location.reload(), 2000);
        }
    }
</script>
</body>

</html>