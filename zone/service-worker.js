// Liyas Delivery Zone - Offline Service Worker
const CACHE_NAME = 'liyas-zone-cache-v1';
const ASSETS_TO_CACHE = [
    'https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css',
    'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap'
];

// Install: Cache core static assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS_TO_CACHE).catch((err) => {
                console.warn('Non-critical asset cache failed:', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// Activate: Clean up old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch: Network first, fallback to cache when offline
self.addEventListener('fetch', (event) => {
    // Only handle GET requests
    if (event.request.method !== 'GET') return;

    // Do not cache API polling or sync endpoints
    const url = new URL(event.request.url);
    if (url.pathname.includes('check_new_orders') || url.pathname.includes('api_sync')) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((networkResponse) => {
                // If successful response, clone and update cache
                if (networkResponse && networkResponse.status === 200) {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return networkResponse;
            })
            .catch(async () => {
                // Network failed (offline) -> Look in cache
                const cachedResponse = await caches.match(event.request);
                if (cachedResponse) {
                    return cachedResponse;
                }
                // If navigated to a zone page while offline, fallback to cached generate-receipt or index
                if (event.request.mode === 'navigate') {
                    const fallback = await caches.match(event.request.url) || 
                                     await caches.match('./generate-receipt.php') ||
                                     await caches.match('./index.php');
                    if (fallback) return fallback;
                }
                return new Response("You are currently offline. Please reconnect to view this page.", {
                    status: 503,
                    statusText: "Service Unavailable",
                    headers: new Headers({ "Content-Type": "text/plain" })
                });
            })
    );
});
