import { ref, onMounted, onUnmounted } from 'vue';

export function useOnlineStatus() {
    const isOnline = ref(navigator.onLine);
    const wasOffline = ref(false);

    const handleOnline = () => {
        if (!isOnline.value) {
            wasOffline.value = true;
        }
        isOnline.value = true;
    };

    const handleOffline = () => {
        isOnline.value = false;
    };

    onMounted(() => {
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);
    });

    onUnmounted(() => {
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('offline', handleOffline);
    });

    const clearWasOffline = () => {
        wasOffline.value = false;
    };

    return {
        isOnline,
        wasOffline,
        clearWasOffline,
    };
}
