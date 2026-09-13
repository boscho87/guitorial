/**
 * Deliberately minimal: no fixed precache list (AssetMapper fingerprints
 * filenames, so a hardcoded list would go stale on every deploy), no
 * caching of HTML navigations (dynamic Twig pages must always be fresh,
 * never served stale/offline). Only runtime-caches same-origin static
 * assets it actually observes - a cache-first strategy just for those,
 * so a repeat visit still loads its CSS/JS/fonts instantly. No offline
 * fallback page, no background sync - not part of this app's scope.
 */
const CACHE_NAME = 'guitorial-static-v1';
const CACHEABLE_EXTENSIONS = /\.(?:css|js|svg|woff2?|png|jpg|jpeg|webp)$/;

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)),
        )),
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin || !CACHEABLE_EXTENSIONS.test(url.pathname)) {
        return;
    }

    event.respondWith(
        caches.open(CACHE_NAME).then((cache) => cache.match(request).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(request).then((response) => {
                if (response.ok) {
                    cache.put(request, response.clone());
                }

                return response;
            });
        })),
    );
});
