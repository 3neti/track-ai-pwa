import axios from 'axios';
import { deleteJob, getAllPendingJobs, updateJobStatus, type PendingJob } from './offlineDb';

const MAX_RETRIES = 3;
const RETRY_DELAY_BASE = 1000; // 1 second base, will use exponential backoff

let isSyncing = false;
let syncListeners: ((status: SyncStatus) => void)[] = [];

export interface SyncStatus {
    isSyncing: boolean;
    pendingCount: number;
    lastSyncAt: Date | null;
    lastError: string | null;
}

let lastSyncStatus: SyncStatus = {
    isSyncing: false,
    pendingCount: 0,
    lastSyncAt: null,
    lastError: null,
};

export function addSyncListener(listener: (status: SyncStatus) => void): () => void {
    syncListeners.push(listener);
    // Return unsubscribe function
    return () => {
        syncListeners = syncListeners.filter(l => l !== listener);
    };
}

function notifyListeners(status: SyncStatus): void {
    lastSyncStatus = status;
    syncListeners.forEach(listener => listener(status));
}

export function getSyncStatus(): SyncStatus {
    return lastSyncStatus;
}

export async function syncAllPendingJobs(): Promise<void> {
    if (isSyncing) {
        console.log('[Sync] Already syncing, skipping...');
        return;
    }

    if (!navigator.onLine) {
        console.log('[Sync] Offline, skipping sync...');
        return;
    }

    isSyncing = true;
    const pendingJobs = await getAllPendingJobs();

    notifyListeners({
        isSyncing: true,
        pendingCount: pendingJobs.length,
        lastSyncAt: lastSyncStatus.lastSyncAt,
        lastError: null,
    });

    console.log(`[Sync] Starting sync of ${pendingJobs.length} jobs`);

    let successCount = 0;
    let errorCount = 0;
    let lastError: string | null = null;

    for (const job of pendingJobs) {
        try {
            await syncJob(job);
            successCount++;
        } catch (error) {
            errorCount++;
            lastError = error instanceof Error ? error.message : 'Unknown error';
            console.error(`[Sync] Failed to sync job ${job.id}:`, error);
        }
    }

    isSyncing = false;

    const remainingJobs = await getAllPendingJobs();

    notifyListeners({
        isSyncing: false,
        pendingCount: remainingJobs.length,
        lastSyncAt: new Date(),
        lastError,
    });

    console.log(`[Sync] Completed: ${successCount} success, ${errorCount} errors`);
}

async function syncJob(job: PendingJob): Promise<void> {
    // Check retry limit
    if (job.retryCount >= MAX_RETRIES) {
        console.log(`[Sync] Job ${job.id} exceeded max retries, marking as failed`);
        await updateJobStatus(job.id, 'failed', 'Max retries exceeded');
        return;
    }

    // Mark as syncing
    await updateJobStatus(job.id, 'syncing');

    try {
        const response = await axios({
            method: job.method.toLowerCase() as 'post' | 'put' | 'patch' | 'delete',
            url: job.endpoint,
            data: job.payload,
            headers: {
                'X-Idempotency-Key': job.idempotencyKey,
                'Content-Type': 'application/json',
            },
        });

        if (response.data.success) {
            // Success - remove from queue
            await deleteJob(job.id);
            console.log(`[Sync] Job ${job.id} synced successfully`);
        } else {
            // API returned success: false
            throw new Error(response.data.message || 'API returned failure');
        }
    } catch (error) {
        const errorMessage = error instanceof Error ? error.message : 'Unknown error';

        // Check if it's a client error (4xx) - don't retry
        if (axios.isAxiosError(error) && error.response?.status && error.response.status >= 400 && error.response.status < 500) {
            console.log(`[Sync] Job ${job.id} got client error, marking as failed`);
            await updateJobStatus(job.id, 'failed', errorMessage);
        } else {
            // Server error or network issue - mark for retry
            await updateJobStatus(job.id, 'pending', errorMessage);
        }

        throw error;
    }
}

export async function retrySingleJob(jobId: string): Promise<boolean> {
    const jobs = await getAllPendingJobs();
    const job = jobs.find(j => j.id === jobId);

    if (!job) {
        console.error(`[Sync] Job ${jobId} not found`);
        return false;
    }

    // Reset retry count for manual retry
    job.retryCount = 0;
    await updateJobStatus(job.id, 'pending');

    try {
        await syncJob(job);
        return true;
    } catch {
        return false;
    }
}

// Auto-sync on online event
if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
        console.log('[Sync] Back online, starting sync...');
        // Small delay to ensure connection is stable
        setTimeout(() => {
            syncAllPendingJobs();
        }, 1000);
    });

    // Listen for SW sync requests
    window.addEventListener('sw-sync-requested', () => {
        console.log('[Sync] SW requested sync');
        syncAllPendingJobs();
    });

    // Auto-sync on app start if online
    if (navigator.onLine) {
        setTimeout(() => {
            syncAllPendingJobs();
        }, 2000);
    }
}
