// Partners manager: add/edit dialog, logo preview, drag to reorder
(function () {
    const dialog = document.getElementById('partner-dialog');
    const $ = id => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    function openPartner(p) {
        $('p-title').textContent = p ? 'Edit ' + p.name : 'Add partner';
        $('p-id').value = p ? p.id : '';
        $('p-name').value = p ? p.name : '';
        $('p-url').value = p ? p.url : '';
        $('p-visible').checked = p ? p.visible === 1 : true;
        $('p-logo-input').value = '';
        $('p-logo').src = p && p.logo ? p.logo : '';
        $('p-logo').hidden = !(p && p.logo);
        $('p-logo-empty').hidden = !!(p && p.logo);
        $('p-remove-wrap').hidden = !(p && p.logo);
        $('p-delete-btn').hidden = !p;
        $('p-delete-id').value = p ? p.id : '';
        dialog.showModal();
        $('p-name').focus();
    }
    document.querySelectorAll('[data-partner-new]').forEach(b => b.addEventListener('click', () => openPartner(null)));
    document.querySelectorAll('[data-partner]').forEach(b => b.addEventListener('click', () => openPartner(JSON.parse(b.dataset.partner))));

    $('p-logo-input').addEventListener('change', e => {
        const file = e.target.files[0];
        if (!file) return;
        $('p-logo').src = URL.createObjectURL(file);
        $('p-logo').hidden = false;
        $('p-logo-empty').hidden = true;
    });

    const grid = $('partner-grid');
    if (grid && typeof Sortable !== 'undefined') {
        Sortable.create(grid, {
            animation: 180,
            handle: '.drag-handle',
            ghostClass: 'photo-ghost',
            onEnd: async () => {
                const data = new FormData();
                data.append('csrf', csrf);
                data.append('action', 'reorder');
                grid.querySelectorAll('.partner-admin-card').forEach(c => data.append('ids[]', c.dataset.id));
                try {
                    const res = await fetch('partners', { method: 'POST', body: data });
                    if (!(await res.json()).ok) throw new Error();
                    adminToast('New order saved and published.');
                } catch {
                    adminToast('Could not save the new order.', 'error');
                }
            }
        });
    }
})();
