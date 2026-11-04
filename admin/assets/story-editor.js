// Story editor: rich text, image uploads, live search/share previews, checklist and unsaved-work recovery
(function () {
    const form = document.getElementById('story-form');
    if (!form || typeof Quill === 'undefined') return;

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const $ = id => document.getElementById(id);
    const quill = new Quill('#editor', {
        theme: 'snow',
        placeholder: 'Tell the story: what happened, who was involved and what changed…',
        modules: {
            toolbar: {
                container: [
                    [{ header: 2 }, { header: 3 }],
                    ['bold', 'italic', 'underline'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    ['blockquote', 'link', 'image'],
                    ['clean']
                ],
                handlers: { image: uploadImage }
            }
        }
    });
    // Friendly tooltips on the toolbar buttons
    const tips = {
        'button.ql-header[value="2"]': 'Heading', 'button.ql-header[value="3"]': 'Subheading',
        'button.ql-bold': 'Bold (Ctrl+B)', 'button.ql-italic': 'Italic (Ctrl+I)', 'button.ql-underline': 'Underline (Ctrl+U)',
        'button.ql-list[value="ordered"]': 'Numbered list', 'button.ql-list[value="bullet"]': 'Bullet list',
        'button.ql-blockquote': 'Quote', 'button.ql-link': 'Link', 'button.ql-image': 'Add photos', 'button.ql-clean': 'Clear formatting'
    };
    Object.entries(tips).forEach(([sel, tip]) => {
        const btn = document.querySelector('.ql-toolbar ' + sel);
        if (btn) { btn.title = tip; btn.setAttribute('aria-label', tip); }
    });

    /* ---------- Images in the story ---------- */
    const UPLOADING = 'Uploading image…';
    async function uploadFile(file, index) {
        const data = new FormData();
        data.append('action', 'inline-image');
        data.append('csrf', csrf);
        data.append('image', file, file.name && file.name !== 'image.png' ? file.name : `pasted-${Date.now()}.png`);
        quill.insertText(index, UPLOADING, { italic: true }, 'user');
        try {
            const res = await fetch('story-edit', { method: 'POST', body: data });
            const json = await res.json();
            quill.deleteText(index, UPLOADING.length, 'user');
            if (!json.ok) throw new Error(json.error);
            quill.insertEmbed(index, 'image', '../' + json.url, 'user');
            quill.setSelection(index + 1, 0, 'user');
        } catch (err) {
            quill.deleteText(index, UPLOADING.length, 'user');
            window.adminToast && adminToast(err.message || 'The image could not be uploaded.', 'error');
        }
    }
    async function uploadFiles(files, index) {
        for (const file of files) { await uploadFile(file, index); index += 1; }
    }
    function uploadImage() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/webp';
        input.multiple = true;
        input.onchange = () => { if (input.files.length) uploadFiles([...input.files], quill.getSelection(true).index); };
        input.click();
    }
    const imageFiles = list => [...(list || [])].filter(f => /^image\//.test(f.type));
    quill.root.addEventListener('paste', e => {
        const files = imageFiles(e.clipboardData && e.clipboardData.files);
        if (!files.length) return;
        e.preventDefault(); e.stopPropagation();
        uploadFiles(files, quill.getSelection(true).index);
    }, true);
    quill.root.addEventListener('drop', e => {
        const files = imageFiles(e.dataTransfer && e.dataTransfer.files);
        if (!files.length) return;
        e.preventDefault(); e.stopPropagation();
        let index = quill.getSelection(true).index;
        const pos = document.caretPositionFromPoint ? document.caretPositionFromPoint(e.clientX, e.clientY) : null;
        const range = pos ? null : (document.caretRangeFromPoint && document.caretRangeFromPoint(e.clientX, e.clientY));
        const node = pos ? pos.offsetNode : range && range.startContainer;
        if (node) {
            const blot = Quill.find(node, true);
            if (blot) index = blot.offset(quill.scroll) + (pos ? pos.offset : range.startOffset);
        }
        uploadFiles(files, Math.min(index, quill.getLength() - 1));
    }, true);
    quill.root.addEventListener('dragover', e => {
        if (e.dataTransfer && [...e.dataTransfer.types].includes('Files')) e.preventDefault();
    });
    // Images pasted as embedded data would be removed by the server's safety filter, so skip them with a tip
    const Delta = Quill.import('delta');
    quill.clipboard.addMatcher('IMG', (node, delta) => {
        if (/^data:/i.test(node.getAttribute('src') || '')) {
            window.adminToast && adminToast('An embedded image was skipped. Copy the image on its own and paste it, or use the image button.', 'error');
            return new Delta();
        }
        return delta;
    });

    /* ---------- Fields ---------- */
    const title = $('title'), slug = $('slug'), excerpt = $('excerpt'), meta = $('meta_description');
    const growTitle = () => { title.style.height = 'auto'; title.style.height = title.scrollHeight + 'px'; };
    title.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); excerpt.focus(); } });
    title.addEventListener('input', growTitle);
    requestAnimationFrame(growTitle);

    let slugTouched = slug.value !== '';
    const slugify = s => s.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
    slug.addEventListener('input', () => { slugTouched = slug.value !== ''; });
    title.addEventListener('input', () => { if (!slugTouched) slug.value = slugify(title.value); });

    /* ---------- Live counters, previews and checklist ---------- */
    const clip = (s, n) => (s.length > n ? s.slice(0, n - 1).trimEnd() + '…' : s);
    const dateField = form.querySelector('input[name="published_at"]');
    function refresh() {
        const t = title.value.trim();
        const tc = $('title-count');
        tc.textContent = `${t.length}/60`;
        tc.classList.toggle('over', t.length > 60);

        const desc = meta.value.trim() || excerpt.value.trim();
        $('meta-count').textContent = meta.value.length;
        $('meta-count').parentElement.classList.toggle('over', meta.value.length > 160);

        const words = (quill.getText().trim().match(/\S+/g) || []).length;
        $('se-stats').textContent = words ? ` · ${words} words · ${Math.max(1, Math.round(words / 200))} min read` : '';

        $('g-title').textContent = clip((t || 'Story title') + ' | Judes CBO', 62);
        $('g-slug').textContent = 'blog-' + (slug.value || slugify(t) || 'your-story');
        const d = dateField && dateField.value ? new Date(dateField.value + 'T00:00') : null;
        $('g-date').textContent = d && !isNaN(d) ? d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) + ' — ' : '';
        $('g-desc').textContent = clip(desc || 'Add a summary or search description to control this text.', 158);
        $('s-title').textContent = clip(t || 'Story title', 90);
        $('s-desc').textContent = clip(excerpt.value.trim() || 'Your summary appears here when the story is shared.', 110);

        const cover = $('cover-preview');
        const checks = {
            title: t.length > 0 && t.length <= 60,
            excerpt: excerpt.value.trim().length >= 20,
            cover: !cover.hidden && !!cover.getAttribute('src'),
            length: words >= 150,
            headings: !!quill.root.querySelector('h2, h3'),
        };
        document.querySelectorAll('#se-checklist li').forEach(li => li.classList.toggle('done', !!checks[li.dataset.check]));
    }
    [title, slug, excerpt, meta, dateField].forEach(el => el && el.addEventListener('input', refresh));
    quill.on('text-change', refresh);
    refresh();

    /* ---------- Cover image: choose or drop ---------- */
    const coverInput = $('cover-input'), preview = $('cover-preview'), drop = $('cover-drop');
    coverInput.addEventListener('change', () => {
        const file = coverInput.files[0];
        if (!file) return;
        const url = URL.createObjectURL(file);
        preview.src = url;
        preview.hidden = false;
        drop.querySelector('.dropzone-text').hidden = true;
        const card = $('s-img');
        card.style.backgroundImage = `url('${url}')`;
        card.innerHTML = '';
        refresh();
    });
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, () => drop.classList.add('drag')));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, () => drop.classList.remove('drag')));

    /* ---------- Unsaved work: kept in this browser until the story is saved ---------- */
    let dirty = false;
    const key = 'judes-' + form.dataset.key;
    const store = {
        get() { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; } },
        set(v) { try { localStorage.setItem(key, JSON.stringify(v)); } catch { /* storage unavailable */ } },
        clear() { try { localStorage.removeItem(key); } catch { /* storage unavailable */ } },
    };
    const snapshot = () => ({ title: title.value, slug: slug.value, excerpt: excerpt.value, meta: meta.value, html: quill.root.innerHTML, text: quill.getText(), at: Date.now() });
    let saveTimer = null;
    function markDirty() {
        dirty = true;
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => store.set(snapshot()), 800);
    }
    quill.on('text-change', (delta, old, source) => { if (source === 'user') markDirty(); });
    form.addEventListener('input', markDirty);

    const saved = store.get();
    const differs = s => s && (s.text !== quill.getText() || s.title !== title.value || s.excerpt !== excerpt.value || s.meta !== meta.value);
    if (differs(saved)) {
        $('se-restore-time').textContent = new Date(saved.at).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
        $('se-restore').hidden = false;
        $('se-restore-yes').addEventListener('click', () => {
            title.value = saved.title; slug.value = saved.slug; excerpt.value = saved.excerpt; meta.value = saved.meta;
            quill.clipboard.dangerouslyPasteHTML(saved.html);
            slugTouched = slug.value !== '';
            $('se-restore').hidden = true;
            growTitle(); refresh(); dirty = true;
        });
        $('se-restore-no').addEventListener('click', () => { store.clear(); $('se-restore').hidden = true; });
    } else {
        store.clear(); // the saved story matches what was being written
    }

    document.querySelectorAll('[data-confirm-click]').forEach(b => b.addEventListener('click', e => { if (!confirm(b.dataset.confirmClick)) e.preventDefault(); }));

    form.addEventListener('submit', e => {
        // Store image paths relative to the website root
        const html = quill.root.innerHTML.replace(/src="\.\.\/(uploads|images)\//g, 'src="$1/');
        $('body_html').value = quill.getText().trim() ? html : '';
        dirty = false;
        store.set(snapshot()); // kept in case saving fails; cleared when the saved story loads
        if (e.submitter && e.submitter.classList.contains('btn')) e.submitter.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    });

    // Ctrl+S / Cmd+S saves, keeping the story's current status
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            const status = form.querySelector('input[name="status"]').value;
            const btn = form.querySelector(`.se-actions button[value="${status}"].btn`) || form.querySelector('.se-actions .btn-primary');
            form.requestSubmit(btn);
        }
    });

    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
