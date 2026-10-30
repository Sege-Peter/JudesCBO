// Two-step login setup: draw the QR code in the browser (the secret never leaves this page)
(function () {
    const box = document.getElementById('qr');
    if (box && typeof qrcode !== 'undefined') {
        const qr = qrcode(0, 'M');
        qr.addData(box.dataset.uri);
        qr.make();
        box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
    }
    const download = document.getElementById('download-codes');
    if (download) download.addEventListener('click', () => {
        const text = document.getElementById('recovery-text').value;
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
        a.download = 'judescbo-recovery-codes.txt';
        a.click();
        URL.revokeObjectURL(a.href);
    });
})();
