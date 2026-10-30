// Live preview for a single-image upload field
(function () {
    const input = document.getElementById('cover-input');
    const preview = document.getElementById('cover-preview');
    if (!input || !preview) return;
    input.addEventListener('change', () => {
        const file = input.files[0];
        if (!file) return;
        preview.src = URL.createObjectURL(file);
        preview.hidden = false;
        const text = document.querySelector('#cover-drop .dropzone-text');
        if (text) text.hidden = true;
    });
})();
