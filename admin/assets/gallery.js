// Gallery manager: upload previews, drag-to-reorder, bulk selection, edit dialog
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    // Upload previews and drag-and-drop onto the drop zone
    const input = document.getElementById('photos');
    const previews = document.getElementById('upload-previews');
    const zone = document.getElementById('dropzone');
    function showPreviews() {
        previews.innerHTML = '';
        [...input.files].slice(0, 12).forEach(file => {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.alt = '';
            previews.appendChild(img);
        });
        if (input.files.length > 12) {
            const more = document.createElement('span');
            more.textContent = `+${input.files.length - 12}`;
            previews.appendChild(more);
        }
        zone.classList.toggle('has-files', input.files.length > 0);
    }
    if (input) {
        input.addEventListener('change', showPreviews);
        ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add('drag'); }));
        ['dragleave', 'drop'].forEach(ev => zone.addEventListener(ev, () => zone.classList.remove('drag')));
        zone.addEventListener('drop', e => {
            e.preventDefault();
            input.files = e.dataTransfer.files;
            showPreviews();
        });
        document.getElementById('upload-form').addEventListener('submit', e => {
            const btn = e.target.querySelector('[type="submit"]');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…';
        });
    }

    // Drag to reorder (only when viewing all albums)
    const grid = document.getElementById('photo-grid');
    if (grid && grid.dataset.sortable === '1' && typeof Sortable !== 'undefined') {
        Sortable.create(grid, {
            animation: 180,
            ghostClass: 'photo-ghost',
            filter: '.photo-select, .photo-edit',
            preventOnFilter: false,
            onEnd: async () => {
                const data = new FormData();
                data.append('action', 'reorder');
                data.append('csrf', csrf);
                grid.querySelectorAll('.photo-card').forEach(card => data.append('ids[]', card.dataset.id));
                try {
                    const res = await fetch('gallery.php', { method: 'POST', body: data });
                    const json = await res.json();
                    if (!json.ok) throw new Error(json.error);
                    adminToast('New order saved and published.');
                } catch (err) {
                    adminToast('Could not save the new order: ' + err.message, 'error');
                }
            }
        });
    }

    // Bulk selection bar
    const bulkBar = document.getElementById('bulk-bar');
    const boxes = document.querySelectorAll('.photo-select input');
    function updateBulk() {
        const n = [...boxes].filter(b => b.checked).length;
        bulkBar.hidden = n === 0;
        document.getElementById('bulk-count').textContent = n;
        boxes.forEach(b => b.closest('.photo-card').classList.toggle('selected', b.checked));
    }
    boxes.forEach(b => b.addEventListener('change', updateBulk));
    const clear = document.getElementById('bulk-clear');
    if (clear) clear.addEventListener('click', () => { boxes.forEach(b => { b.checked = false; }); updateBulk(); });
    const bulkForm = document.getElementById('bulk-form');
    if (bulkForm) bulkForm.addEventListener('submit', e => {
        if (document.getElementById('bulk-op').value === 'delete' && !confirm('Delete the selected photos from the website? This can\'t be undone.')) e.preventDefault();
    });

    // Edit dialog
    const dialog = document.getElementById('photo-dialog');
    document.querySelectorAll('.photo-edit').forEach(btn => btn.addEventListener('click', () => {
        document.getElementById('pd-id').value = btn.dataset.id;
        document.getElementById('pd-caption').value = btn.dataset.caption;
        document.getElementById('pd-alt').value = btn.dataset.alt;
        document.getElementById('pd-album').value = btn.dataset.album;
        document.getElementById('pd-home').checked = btn.dataset.home === '1';
        document.getElementById('pd-img').src = btn.dataset.src;
        dialog.showModal();
    }));
})();
