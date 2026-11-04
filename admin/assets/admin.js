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
        fetch('messages', { method: 'POST', body: data });
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

// Image fields: drag-and-drop files onto them, or paste an image (Ctrl+V / Cmd+V) anywhere on the page
(function () {
    const zones = [...document.querySelectorAll('.dropzone, .member-photo-pick')].filter(z => z.querySelector('input[type="file"]'));
    if (!zones.length) return;
    let lastZone = null;

    const isImage = f => f && /^image\//.test(f.type);
    function named(file, i) {
        // Clipboard images arrive as "image.png"; give them a unique, readable name
        if (file.name && file.name !== 'image.png') return file;
        const ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
        return new File([file], `pasted-${Date.now()}-${i + 1}.${ext}`, { type: file.type });
    }
    function setFiles(input, files) {
        files = files.filter(isImage).map(named);
        if (!files.length) return 0;
        const dt = new DataTransfer();
        if (input.multiple) {
            [...input.files].forEach(f => dt.items.add(f));
            files.forEach(f => dt.items.add(f));
        } else {
            dt.items.add(files[files.length - 1]);
        }
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        return files.length;
    }

    zones.forEach(zone => {
        const input = zone.querySelector('input[type="file"]');
        ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, e => {
            e.preventDefault();
            zone.classList.add('drag');
        }));
        ['dragleave', 'dragend'].forEach(ev => zone.addEventListener(ev, () => zone.classList.remove('drag')));
        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('drag');
            const added = setFiles(input, [...e.dataTransfer.files]);
            if (!added && e.dataTransfer.files.length) adminToast('Only image files can be added here.', 'error');
        });
        ['mouseenter', 'focusin', 'click'].forEach(ev => zone.addEventListener(ev, () => { lastZone = zone; }));
    });

    // Stop a file dropped just outside a zone from opening in the browser and losing unsaved work
    ['dragover', 'drop'].forEach(ev => window.addEventListener(ev, e => {
        if (e.dataTransfer && [...e.dataTransfer.types].includes('Files') && !e.target.closest('.dropzone, .member-photo-pick, .ql-editor')) {
            e.preventDefault();
        }
    }));

    function targetZone() {
        const dialog = document.querySelector('dialog[open]');
        if (dialog) return zones.find(z => dialog.contains(z)) || null;
        if (lastZone && lastZone.offsetParent) return lastZone;
        return zones.find(z => z.offsetParent && !z.closest('dialog')) || null;
    }

    document.addEventListener('paste', e => {
        if (e.target.closest && e.target.closest('.ql-editor')) return; // the story editor uploads its own pastes
        const files = [...(e.clipboardData ? e.clipboardData.files : [])].filter(isImage);
        if (!files.length) return;
        const zone = targetZone();
        if (!zone) return;
        e.preventDefault();
        const count = setFiles(zone.querySelector('input[type="file"]'), files);
        zone.classList.add('pasted');
        setTimeout(() => zone.classList.remove('pasted'), 900);
        adminToast(count === 1 ? 'Image pasted.' : `${count} images pasted.`);
    });
})();

// Date and time fields: open the picker on any click, and mark empty ones so they look like placeholders
(function () {
    const fields = document.querySelectorAll('input[type="date"], input[type="time"], input[type="datetime-local"], input[type="month"]');
    const mark = el => el.classList.toggle('has-value', el.value !== '');
    fields.forEach(el => {
        mark(el);
        el.addEventListener('input', () => mark(el));
        el.addEventListener('change', () => mark(el));
        el.addEventListener('click', () => {
            if (typeof el.showPicker === 'function') {
                try { el.showPicker(); } catch (e) { /* not allowed in this context; the native control still works */ }
            }
        });
    });
})();


// Session timeout: sign out after the idle time set on the Security page. Activity in any open tab counts.
// Shortly before, a "Still there?" prompt appears (no countdown); unsaved story text is kept by the editor.
(function () {
    const meta = document.querySelector('meta[name="session-idle"]');
    const form = document.getElementById('logout-form');
    if (!meta || !form) return;
    const idle = parseInt(meta.content, 10) * 1000;
    if (!idle) return;
    const warnAt = idle - Math.min(60000, idle / 5); // ask a minute before
    const KEY = 'judes-last-active';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let last = Date.now(), lastPing = Date.now(), prompt = null, done = false;

    const store = t => { try { localStorage.setItem(KEY, String(t)); } catch (e) { /* storage blocked */ } };
    const shared = () => { try { return parseInt(localStorage.getItem(KEY) || '0', 10) || 0; } catch (e) { return 0; } };
    const lastActive = () => Math.max(last, shared());

    function ping() {
        lastPing = Date.now();
        fetch('session', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : { ok: false })
            .then(j => { if (!j.ok) location.href = 'login?idle=1'; })
            .catch(() => { /* offline: try again on the next activity */ });
    }
    function active() {
        if (prompt || done) return;
        last = Date.now();
        store(last);
        // Tell the server now and then, so it doesn't time out while someone is working on one page
        if (last - lastPing > Math.min(5 * 60000, idle / 3)) ping();
    }
    ['mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'].forEach(ev => document.addEventListener(ev, active, { passive: true, capture: true }));
    let moveTimer = 0;
    document.addEventListener('mousemove', () => { if (!moveTimer) moveTimer = setTimeout(() => { moveTimer = 0; active(); }, 5000); }, { passive: true });
    store(last);

    function signOut() {
        if (done) return;
        done = true;
        const flag = document.createElement('input');
        flag.type = 'hidden'; flag.name = 'idle'; flag.value = '1';
        form.appendChild(flag);
        window.onbeforeunload = null;
        form.submit();
    }
    function showPrompt() {
        prompt = document.createElement('dialog');
        prompt.className = 'modal idle-modal';
        prompt.innerHTML = '<div class="modal-body"><div class="idle-icon"><i class="fas fa-clock"></i></div>' +
            '<h3>Still there?</h3><p class="muted">You\'ve been inactive for a while. For your security you\'ll be signed out soon.</p>' +
            '<div class="modal-foot"><button type="button" class="btn btn-light" data-out><i class="fas fa-right-from-bracket"></i> Sign out</button>' +
            '<button type="button" class="btn btn-primary" data-stay><i class="fas fa-check"></i> Stay signed in</button></div></div>';
        document.body.appendChild(prompt);
        prompt.querySelector('[data-stay]').addEventListener('click', stay);
        prompt.querySelector('[data-out]').addEventListener('click', signOut);
        prompt.addEventListener('cancel', e => { e.preventDefault(); stay(); });
        prompt.showModal ? prompt.showModal() : prompt.setAttribute('open', '');
        prompt.querySelector('[data-stay]').focus();
    }
    function hidePrompt() { if (prompt) { prompt.close && prompt.close(); prompt.remove(); prompt = null; } }
    function stay() { hidePrompt(); last = Date.now(); store(last); ping(); }

    setInterval(() => {
        if (done) return;
        const quiet = Date.now() - lastActive();
        if (quiet >= idle) signOut();
        else if (quiet >= warnAt) { if (!prompt) showPrompt(); }
        else if (prompt) hidePrompt(); // someone was active in another tab
    }, 5000);
})();
