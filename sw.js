const CACHE_NAME = 'plotnav-v2';
const ASSETS = [
    'mobile.css',
    'pwa.js',
    'icons/icon.svg'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Always fetch dynamic / logged-in pages from the network so the
    // landing page never shows a stale "Dashboard" button after logout.
    const isDynamic = url.pathname.endsWith('.php') || request.mode === 'navigate';

    if (isDynamic) {
        event.respondWith(
            fetch(request, { cache: 'no-store' })
                .catch(() => caches.match(request))
        );
        return;
    }

    event.respondWith(
        caches.match(request).then(cached => {
            return cached || fetch(request);
        })
    );
});
