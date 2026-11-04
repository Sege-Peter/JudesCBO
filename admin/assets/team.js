// Team manager: add/edit dialog, photo preview, drag to reorder within a group
(function () {
    const dialog = document.getElementById('member-dialog');
    const $ = id => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    function openMember(m) {
        $('m-title').textContent = m ? 'Edit ' + m.name : 'Add person';
        $('m-id').value = m ? m.id : '';
        $('m-name').value = m ? m.name : '';
        $('m-role').value = m ? m.role : '';
        $('m-group').value = m ? m.team_group : 'Leadership';
        $('m-linkedin').value = m ? m.linkedin : '';
        $('m-bio').value = m ? m.bio : '';
        $('m-visible').checked = m ? m.visible === 1 : true;
        $('m-photo-input').value = '';
        $('m-photo').src = m && m.photo ? m.photo : '';
        $('m-photo').hidden = !(m && m.photo);
        $('m-photo-empty').hidden = !!(m && m.photo);
        $('m-remove-wrap').hidden = !(m && m.photo);
        $('m-delete-btn').hidden = !m;
        $('m-delete-id').value = m ? m.id : '';
        dialog.showModal();
        $('m-name').focus();
    }
    document.querySelectorAll('[data-member-new]').forEach(b => b.addEventListener('click', () => openMember(null)));
    document.querySelectorAll('[data-member]').forEach(b => b.addEventListener('click', () => openMember(JSON.parse(b.dataset.member))));

    $('m-photo-input').addEventListener('change', e => {
        const file = e.target.files[0];
        if (!file) return;
        $('m-photo').src = URL.createObjectURL(file);
        $('m-photo').hidden = false;
        $('m-photo-empty').hidden = true;
    });

    if (typeof Sortable !== 'undefined') {
        document.querySelectorAll('[data-sortable-team]').forEach(grid => Sortable.create(grid, {
            animation: 180,
            handle: '.drag-handle',
            ghostClass: 'photo-ghost',
            onEnd: async () => {
                const data = new FormData();
                data.append('csrf', csrf);
                data.append('action', 'reorder');
                document.querySelectorAll('.team-admin-card').forEach(c => data.append('ids[]', c.dataset.id));
                try {
                    const res = await fetch('team', { method: 'POST', body: data });
                    if (!(await res.json()).ok) throw new Error();
                    adminToast('New order saved and published.');
                } catch {
                    adminToast('Could not save the new order.', 'error');
                }
            }
        }));
    }
})();
