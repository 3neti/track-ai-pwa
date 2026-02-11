const CACHE_NAME = 'track-ai-v1';
const OFFLINE_URL = '/offline.html';

// Assets to cache immediately on install
// Note: Do NOT include '/' as it may redirect or require auth, causing cache.addAll() to fail
const PRECACHE_ASSETS = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icons/icon-72x72.png',
    '/icons/icon-96x96.png',
    '/icons/icon-128x128.png',
    '/icons/icon-144x144.png',
    '/icons/icon-152x152.png',
    '/icons/icon-192x192.png',
    '/icons/icon-384x384.png',
    '/icons/icon-512x512.png',
];

// Install event - precache essential assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            console.log('[SW] Precaching app shell');
            return cache.addAll(PRECACHE_ASSETS);
        })
    );
    self.skipWaiting();
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});

// Fetch event - network-first for API, cache-first for assets
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip cross-origin requests
    if (url.origin !== location.origin) {
        return;
    }

    // Handle API requests - network first with cache fallback for GET
    if (url.pathname.startsWith('/api/')) {
        if (request.method === 'GET') {
            event.respondWith(networkFirstWithCache(request));
        }
        // POST/PUT/DELETE requests are handled by the offline queue in the app
        return;
    }

    // Handle navigation requests
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => {
                return caches.match(OFFLINE_URL);
            })
        );
        return;
    }

    // Handle static assets - cache first
    if (isStaticAsset(url.pathname)) {
        event.respondWith(cacheFirstWithNetwork(request));
        return;
    }

    // Default: network first
    event.respondWith(networkFirstWithCache(request));
});

// Network first, cache fallback strategy
async function networkFirstWithCache(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        throw error;
    }
}

// Cache first, network fallback strategy
async function cacheFirstWithNetwork(request) {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
        return cachedResponse;
    }

    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        console.error('[SW] Fetch failed:', error);
        throw error;
    }
}

// Check if request is for a static asset
function isStaticAsset(pathname) {
    const staticExtensions = [
        '.js', '.css', '.png', '.jpg', '.jpeg', '.gif', '.svg',
        '.ico', '.woff', '.woff2', '.ttf', '.eot'
    ];
    return staticExtensions.some(ext => pathname.endsWith(ext));
}

// Handle background sync for offline queue
self.addEventListener('sync', (event) => {
    if (event.tag === 'offline-sync') {
        event.waitUntil(syncOfflineQueue());
    }
});

async function syncOfflineQueue() {
    // This is triggered when the app comes back online
    // The actual sync logic is handled by the Vue app's syncManager
    const clients = await self.clients.matchAll();
    clients.forEach(client => {
        client.postMessage({ type: 'SYNC_REQUESTED' });
    });
}

// Handle messages from the app
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
