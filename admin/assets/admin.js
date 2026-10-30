// Shared dashboard behaviour
(function () {
    // Sidebar on phones
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const menuBtn = document.getElementById('menu-btn');
    function setSidebar(open) {
        if (!sidebar) return;
        sidebar.classList.toggle('open', open);
        backdrop.classList.toggle('open', open);
    }
    if (menuBtn) menuBtn.addEventListener('click', () => setSidebar(!sidebar.classList.contains('open')));
    if (backdrop) backdrop.addEventListener('click', () => setSidebar(false));

    // User dropdown
    const userBtn = document.getElementById('user-btn');
    const dropdown = document.getElementById('user-dropdown');
    if (userBtn) {
        userBtn.addEventListener('click', e => {
            e.stopPropagation();
            const open = !dropdown.classList.contains('open');
            dropdown.classList.toggle('open', open);
            userBtn.setAttribute('aria-expanded', open);
        });
        document.addEventListener('click', () => { dropdown.classList.remove('open'); userBtn.setAttribute('aria-expanded', 'false'); });
    }

    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        setSidebar(false);
        if (dropdown) dropdown.classList.remove('open');
        document.querySelectorAll('details.menu-details[open]').forEach(d => d.removeAttribute('open'));
    });

    // Close other "…" menus when one opens, and when clicking elsewhere
    document.querySelectorAll('details.menu-details').forEach(d => d.addEventListener('toggle', () => {
        if (d.open) document.querySelectorAll('details.menu-details[open]').forEach(o => { if (o !== d) o.removeAttribute('open'); });
    }));
    document.addEventListener('click', e => {
        document.querySelectorAll('details.menu-details[open]').forEach(d => { if (!d.contains(e.target)) d.removeAttribute('open'); });
    });

    // Dismissable alerts; success messages fade on their own
    document.querySelectorAll('.alert').forEach(alert => {
        const close = alert.querySelector('.alert-close');
        if (close) close.addEventListener('click', () => alert.remove());
        if (alert.classList.contains('alert-success')) setTimeout(() => {
            alert.style.transition = 'opacity .5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 6000);
    });

    // Confirm before destructive form submissions
    document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', e => {
        if (!confirm(form.dataset.confirm)) e.preventDefault();
    }));

    // Show / hide password
    document.querySelectorAll('.toggle-pass').forEach(btn => btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.target);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.innerHTML = `<i class="fas ${show ? 'fa-eye-slash' : 'fa-eye'}"></i>`;
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    }));

    // Strong password generator (users page)
    const gen = document.getElementById('gen-pass');
    if (gen) gen.addEventListener('click', () => {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%*?';
        const values = crypto.getRandomValues(new Uint32Array(16));
        const input = document.getElementById('new-pass');
        input.value = [...values].map(v => chars[v % chars.length]).join('');
        input.type = 'text';
        input.select();
    });

    // Copy buttons
    document.querySelectorAll('[data-copy-target]').forEach(btn => btn.addEventListener('click', async () => {
        const text = document.getElementById(btn.dataset.copyTarget).value;
        try {
            await navigator.clipboard.writeText(text);
            adminToast('Copied to clipboard.');
        } catch {
            document.getElementById(btn.dataset.copyTarget).select();
            adminToast('Press Ctrl+C to copy.', 'error');
        }
    }));

    // Mark a message as replied when the reply link is used
    const csrf = document.querySelector('meta[name="csrf-token"]');
    document.querySelectorAll('[data-mark-replied]').forEach(link => link.addEventListener('click', () => {
        const data = new FormData();
        data.append('csrf', csrf.content);
        data.append('action', 'status');
        data.append('status', 'replied');
        data.append('ajax', '1');
        data.append('id', link.dataset.markReplied);
        fetch('messages.php', { method: 'POST', body: data });
    }));

    // Show the M-Pesa code field only for M-Pesa donations
    const method = document.getElementById('method');
    const codeField = document.getElementById('code-field');
    if (method && codeField) {
        const sync = () => { codeField.hidden = method.value !== 'mpesa'; };
        method.addEventListener('change', sync);
        sync();
    }

    // Radio cards highlight
    document.querySelectorAll('.radio-card input').forEach(r => r.addEventListener('change', () => {
        document.querySelectorAll(`.radio-card input[name="${r.name}"]`).forEach(o => o.closest('.radio-card').classList.toggle('checked', o.checked));
    }));

    // Close dialogs from [data-close] buttons
    document.querySelectorAll('dialog [data-close]').forEach(btn => btn.addEventListener('click', () => btn.closest('dialog').close()));
})();

function adminToast(message, type) {
    let stack = document.querySelector('.toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'toast-stack';
        document.body.appendChild(stack);
    }
    const toast = document.createElement('div');
    toast.className = 'toast' + (type === 'error' ? ' error' : '');
    toast.setAttribute('role', 'status');
    toast.textContent = message;
    stack.appendChild(toast);
    setTimeout(() => {
        toast.style.transition = 'opacity .4s';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 400);
    }, 3500);
}
