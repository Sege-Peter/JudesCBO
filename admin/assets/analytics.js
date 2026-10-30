// Analytics chart: a guide line and tooltip follow the pointer (or arrow keys)
document.querySelectorAll('.line-chart').forEach(chart => {
    const points = JSON.parse(chart.dataset.points || '[]');
    const plot = chart.querySelector('.lc-plot');
    const cursor = chart.querySelector('.lc-cursor');
    const dot = chart.querySelector('.lc-dot');
    const tip = chart.querySelector('.lc-tip');
    if (!points.length) return;
    const top = Math.max(...[...chart.querySelectorAll('.lc-grid em')].map(e => Number(e.textContent.replace(/,/g, ''))));
    const fmt = n => n.toLocaleString();
    let current = -1;

    function show(i) {
        current = Math.max(0, Math.min(points.length - 1, i));
        const [label, visitors, views] = points[current];
        const x = points.length > 1 ? current / (points.length - 1) * 100 : 50;
        const y = (240 - visitors / top * 230) / 250 * 100;
        cursor.style.left = x + '%';
        dot.style.left = x + '%';
        dot.style.top = y + '%';
        tip.innerHTML = '<b></b>';
        tip.querySelector('b').textContent = label;
        tip.append(fmt(visitors) + (visitors === 1 ? ' visitor' : ' visitors'), document.createElement('br'), fmt(views) + (views === 1 ? ' page view' : ' page views'));
        cursor.hidden = dot.hidden = tip.hidden = false;
        // Keep the tooltip inside the chart
        const px = x / 100 * plot.clientWidth;
        let left = px + 12;
        if (left + tip.offsetWidth > plot.clientWidth) left = px - tip.offsetWidth - 12;
        tip.style.left = Math.max(0, left) + 'px';
    }
    function hide() {
        cursor.hidden = dot.hidden = tip.hidden = true;
    }
    function indexAt(clientX) {
        const box = plot.getBoundingClientRect();
        return Math.round((clientX - box.left) / box.width * (points.length - 1));
    }

    plot.addEventListener('pointermove', e => show(indexAt(e.clientX)));
    plot.addEventListener('pointerdown', e => show(indexAt(e.clientX)));
    plot.addEventListener('pointerleave', e => { if (e.pointerType === 'mouse') hide(); });
    plot.tabIndex = 0;
    plot.setAttribute('aria-label', 'Visitors chart. Use the arrow keys to read each point.');
    plot.addEventListener('keydown', e => {
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
            e.preventDefault();
            show(current < 0 ? points.length - 1 : current + (e.key === 'ArrowRight' ? 1 : -1));
        } else if (e.key === 'Escape') {
            hide();
        }
    });
    plot.addEventListener('blur', hide);
});
