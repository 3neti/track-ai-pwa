export interface ServiceWorkerRegistration {
    installing: ServiceWorker | null;
    waiting: ServiceWorker | null;
    active: ServiceWorker | null;
}

let registration: globalThis.ServiceWorkerRegistration | null = null;

export async function registerServiceWorker(): Promise<void> {
    if (
        !('serviceWorker' in navigator) ||
        import.meta.env.VITE_ENABLE_SERVICE_WORKER !== 'true'
    ) {
        console.log('[PWA] Service workers not supported');
        return;
    }

    try {
        registration = await navigator.serviceWorker.register('/sw.js', {
            scope: '/',
        });

        console.log('[PWA] Service Worker registered successfully');

        // Handle updates
        registration.addEventListener('updatefound', () => {
            const newWorker = registration?.installing;
            if (newWorker) {
                newWorker.addEventListener('statechange', () => {
                    if (
                        newWorker.state === 'installed' &&
                        navigator.serviceWorker.controller
                    ) {
                        // New content is available, notify user
                        dispatchUpdateAvailable();
                    }
                });
            }
        });

        // Listen for messages from SW
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data?.type === 'SYNC_REQUESTED') {
                // Dispatch event for Vue app to handle
                window.dispatchEvent(new CustomEvent('sw-sync-requested'));
            }
        });
    } catch (error) {
        console.error('[PWA] Service Worker registration failed:', error);
    }
}

export function skipWaiting(): void {
    if (registration?.waiting) {
        registration.waiting.postMessage({ type: 'SKIP_WAITING' });
    }
}

export async function unregisterServiceWorker(): Promise<boolean> {
    if (!('serviceWorker' in navigator)) {
        return false;
    }

    try {
        const registrations = await navigator.serviceWorker.getRegistrations();
        const results = await Promise.all(
            registrations.map((item) => item.unregister()),
        );
        registration = null;

        return results.some(Boolean);
    } catch (error) {
        console.error('[PWA] Service Worker unregistration failed:', error);
        return false;
    }
}

async function clearServiceWorkerState(): Promise<void> {
    await unregisterServiceWorker();

    if ('caches' in window) {
        const keys = await caches.keys();
        await Promise.all(keys.map((key) => caches.delete(key)));
    }
}

function dispatchUpdateAvailable(): void {
    window.dispatchEvent(new CustomEvent('sw-update-available'));
}

export function isServiceWorkerSupported(): boolean {
    return 'serviceWorker' in navigator;
}

export function getRegistration(): globalThis.ServiceWorkerRegistration | null {
    return registration;
}

// Auto-register on module import if in browser
if (typeof window !== 'undefined') {
    if (import.meta.env.VITE_ENABLE_SERVICE_WORKER === 'true') {
        if (document.readyState === 'complete') {
            registerServiceWorker();
        } else {
            window.addEventListener('load', registerServiceWorker);
        }
    } else {
        // Debug/demo cleanup. Remove after PWA caching is redesigned.
        void clearServiceWorkerState();
    }
}
