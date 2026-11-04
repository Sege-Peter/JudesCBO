// Story editor: rich text, image uploads, slug and search-description helpers
(function () {
    const form = document.getElementById('story-form');
    if (!form || typeof Quill === 'undefined') return;

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const quill = new Quill('#editor', {
        theme: 'snow',
        placeholder: 'Tell the story: what happened, who was involved and what changed…',
        modules: {
            toolbar: {
                container: [
                    [{ header: [2, 3, false] }],
                    ['bold', 'italic', 'underline'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    ['blockquote', 'link', 'image'],
                    ['clean']
                ],
                handlers: { image: uploadImage }
            }
        }
    });

    const UPLOADING = 'Uploading image…';

    // Uploads one image through the dashboard (resized, converted to WebP) and places it at `index`
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
        for (const file of files) {
            await uploadFile(file, index);
            index += 1;
        }
    }

    function uploadImage() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/webp';
        input.multiple = true;
        input.onchange = () => {
            if (input.files.length) uploadFiles([...input.files], quill.getSelection(true).index);
        };
        input.click();
    }

    const imageFiles = list => [...(list || [])].filter(f => /^image\//.test(f.type));

    // Paste a screenshot or copied image straight into the story
    quill.root.addEventListener('paste', e => {
        const files = imageFiles(e.clipboardData && e.clipboardData.files);
        if (!files.length) return;
        e.preventDefault();
        e.stopPropagation();
        uploadFiles(files, quill.getSelection(true).index);
    }, true);

    // Drop images where the pointer is
    quill.root.addEventListener('drop', e => {
        const files = imageFiles(e.dataTransfer && e.dataTransfer.files);
        if (!files.length) return;
        e.preventDefault();
        e.stopPropagation();
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

    // Images pasted as embedded data (e.g. copied from a web page with the text) would be thrown away
    // by the server's safety filter, so drop them here and tell the user how to add them properly
    const Delta = Quill.import('delta');
    quill.clipboard.addMatcher('IMG', (node, delta) => {
        if (/^data:/i.test(node.getAttribute('src') || '')) {
            adminToast('An embedded image was skipped. Copy the image on its own and paste it, or use the image button.', 'error');
            return new Delta();
        }
        return delta;
    });

    form.addEventListener('submit', () => {
        // Store image paths relative to the website root
        const html = quill.root.innerHTML.replace(/src="\.\.\/(uploads|images)\//g, 'src="$1/');
        document.getElementById('body_html').value = quill.getText().trim() ? html : '';
    });

    // Web address follows the title until it's edited by hand
    const title = document.getElementById('title');
    const slug = document.getElementById('slug');
    let slugTouched = slug.value !== '';
    const slugify = s => s.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
    slug.addEventListener('input', () => { slugTouched = slug.value !== ''; });
    title.addEventListener('input', () => { if (!slugTouched) slug.value = slugify(title.value); });

    // Search description counter
    const meta = document.getElementById('meta_description');
    const count = document.getElementById('meta-count');
    const updateCount = () => {
        count.textContent = meta.value.length;
        count.parentElement.classList.toggle('over', meta.value.length > 160);
    };
    meta.addEventListener('input', updateCount);
    updateCount();

    // Cover image preview
    const coverInput = document.getElementById('cover-input');
    const preview = document.getElementById('cover-preview');
    coverInput.addEventListener('change', () => {
        const file = coverInput.files[0];
        if (!file) return;
        preview.src = URL.createObjectURL(file);
        preview.hidden = false;
        document.querySelector('#cover-drop .dropzone-text').hidden = true;
    });

    // Warn before leaving with unsaved changes
    let dirty = false;
    quill.on('text-change', () => { dirty = true; });
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
