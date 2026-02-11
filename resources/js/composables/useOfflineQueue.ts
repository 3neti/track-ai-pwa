import { onMounted, onUnmounted, ref } from 'vue';
import { addJob, getAllJobs, getPendingJobCount, type PendingJob } from '@/lib/offlineDb';
import { addSyncListener, getSyncStatus, syncAllPendingJobs, type SyncStatus } from '@/lib/syncManager';

export function useOfflineQueue() {
    const pendingCount = ref(0);
    const jobs = ref<PendingJob[]>([]);
    const syncStatus = ref<SyncStatus>(getSyncStatus());
    const isOnline = ref(navigator.onLine);

    let unsubscribe: (() => void) | null = null;

    const refreshJobs = async () => {
        jobs.value = await getAllJobs();
        pendingCount.value = await getPendingJobCount();
    };

    const queueRequest = async (
        endpoint: string,
        method: PendingJob['method'],
        payload: Record<string, unknown>,
        idempotencyKey: string
    ): Promise<PendingJob> => {
        const job = await addJob({
            endpoint,
            method,
            payload,
            idempotencyKey,
        });

        await refreshJobs();
        return job;
    };

    const triggerSync = async () => {
        await syncAllPendingJobs();
        await refreshJobs();
    };

    const handleOnline = () => {
        isOnline.value = true;
    };

    const handleOffline = () => {
        isOnline.value = false;
    };

    onMounted(async () => {
        // Initial load
        await refreshJobs();

        // Subscribe to sync status updates
        unsubscribe = addSyncListener((status) => {
            syncStatus.value = status;
            pendingCount.value = status.pendingCount;
        });

        // Listen for online/offline events
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);
    });

    onUnmounted(() => {
        if (unsubscribe) {
            unsubscribe();
        }
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('offline', handleOffline);
    });

    return {
        pendingCount,
        jobs,
        syncStatus,
        isOnline,
        queueRequest,
        triggerSync,
        refreshJobs,
    };
}
