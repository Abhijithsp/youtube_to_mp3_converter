/*
 * Service worker: makes the app installable and keeps the interface available
 * offline. The API and audio files always come from the network.
 */
const CACHE = 'ytmp3-shell-v1';
const SHELL = ['/', '/manifest.json', '/icon.svg', '/icon-192.png', '/icon-512.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    // Live data and audio streams (range requests) are never cached
    if (url.pathname.startsWith('/api/') || url.pathname === '/up') return;

    // Pages: network first, so updates show immediately; cached shell when offline
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put('/', copy));
                    return response;
                })
                .catch(() => caches.match('/')),
        );
        return;
    }

    // Built assets have content hashes in their names, so cached copies never go stale
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const copy = response.clone();
                            caches.open(CACHE).then((cache) => cache.put(request, copy));
                        }
                        return response;
                    }),
            ),
        );
    }
});
