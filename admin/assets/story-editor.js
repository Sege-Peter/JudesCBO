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

    function uploadImage() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/webp';
        input.onchange = async () => {
            const file = input.files[0];
            if (!file) return;
            const data = new FormData();
            data.append('action', 'inline-image');
            data.append('csrf', csrf);
            data.append('image', file);
            const range = quill.getSelection(true);
            quill.insertText(range.index, 'Uploading image…', { italic: true });
            try {
                const res = await fetch('story-edit.php', { method: 'POST', body: data });
                const json = await res.json();
                quill.deleteText(range.index, 'Uploading image…'.length);
                if (!json.ok) throw new Error(json.error);
                quill.insertEmbed(range.index, 'image', '../' + json.url);
                quill.setSelection(range.index + 1);
            } catch (err) {
                quill.deleteText(range.index, 'Uploading image…'.length);
                window.adminToast && adminToast(err.message || 'The image could not be uploaded.', 'error');
            }
        };
        input.click();
    }

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
