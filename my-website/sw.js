const CACHE_NAME = 'gembok-isp-v1';
const SCOPE_URL = self.registration && self.registration.scope ? self.registration.scope : self.location.origin + '/';
const urlsToCache = [
    new URL('./', SCOPE_URL).toString(),
    new URL('index.php', SCOPE_URL).toString(),
    new URL('portal/login.php', SCOPE_URL).toString(),
    new URL('admin/login.php', SCOPE_URL).toString(),
    new URL('sales/login.php', SCOPE_URL).toString(),
    new URL('technician/login.php', SCOPE_URL).toString(),
    new URL('assets/icons/icon-192x192.png', SCOPE_URL).toString(),
    new URL('assets/icons/icon-512x512.png', SCOPE_URL).toString(),
    new URL('manifest.json', SCOPE_URL).toString()
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                return Promise.allSettled(urlsToCache.map(url => cache.add(url)));
            })
    );
});

self.addEventListener('fetch', event => {
    event.respondWith(
        caches.match(event.request)
            .then(response => {
                if (response) {
                    return response;
                }
                return fetch(event.request);
            })
    );
});

self.addEventListener('activate', event => {
    const cacheWhitelist = [CACHE_NAME];
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheWhitelist.indexOf(cacheName) === -1) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
});
