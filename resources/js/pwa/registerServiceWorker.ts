export interface ServiceWorkerRegistration {
    installing: ServiceWorker | null;
    waiting: ServiceWorker | null;
    active: ServiceWorker | null;
}

let registration: globalThis.ServiceWorkerRegistration | null = null;

export async function registerServiceWorker(): Promise<void> {
    if (!('serviceWorker' in navigator)) {
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
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
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
    if (!registration) {
        return false;
    }

    try {
        const result = await registration.unregister();
        console.log('[PWA] Service Worker unregistered:', result);
        return result;
    } catch (error) {
        console.error('[PWA] Service Worker unregistration failed:', error);
        return false;
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
    // Register when the page loads
    if (document.readyState === 'complete') {
        registerServiceWorker();
    } else {
        window.addEventListener('load', registerServiceWorker);
    }
}
