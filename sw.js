// Judes CBO service worker: pages come from the network first (always fresh),
// static files from the cache first, and the offline page when there's no connection.
const VERSION = 'judes-v7';
const CORE = [
    './', 'offline', 'css/style.css?v=20261104d', 'css/fonts.css?v=20261104d', 'js/main.js?v=20261104d',
    'fonts/raleway-latin.woff2', 'fonts/montserrat-latin.woff2', 'fonts/fa-solid.woff2', 'fonts/fa-brands.woff2',
    'favicon.ico', 'images/icons/icon-192.png', 'images/icons/favicon-32.png', 'images/jude-logo-192.webp'
];

self.addEventListener('install', event => {
    event.waitUntil(caches.open(VERSION).then(cache => cache.addAll(CORE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(k => k !== VERSION).map(k => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    // Never cache the staff dashboard or form endpoints
    if (url.pathname.includes('/admin/')) return;

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then(response => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(VERSION).then(cache => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => caches.match(request).then(hit => hit || caches.match('offline')))
        );
        return;
    }

    // Our own styles and scripts: network first so updates show straight away, cache when offline
    if (url.origin === location.origin && /\.(css|js)$/i.test(url.pathname)) {
        event.respondWith(
            fetch(request)
                .then(response => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(VERSION).then(cache => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => caches.match(request))
        );
        return;
    }

    // Images, fonts and icon libraries: serve from cache, refresh in the background
    if (/\.(png|jpe?g|webp|svg|ico|woff2?)$/i.test(url.pathname) || url.host.includes('fonts.g') || url.host === 'cdnjs.cloudflare.com') {
        event.respondWith(
            caches.match(request).then(hit => {
                const network = fetch(request).then(response => {
                    if (response.ok || response.type === 'opaque') {
                        const copy = response.clone();
                        caches.open(VERSION).then(cache => cache.put(request, copy));
                    }
                    return response;
                }).catch(() => hit);
                return hit || network;
            })
        );
    }
});
