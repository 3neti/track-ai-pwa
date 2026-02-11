import { openDB, type DBSchema, type IDBPDatabase } from 'idb';

export interface PendingJob {
    id: string;
    endpoint: string;
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    payload: Record<string, unknown>;
    /** Stable idempotency key - generated once when job is created, used for offline replay safety */
    clientRequestId: string;
    /** @deprecated Use clientRequestId instead. Kept for backwards compatibility. */
    idempotencyKey: string;
    createdAt: Date;
    retryCount: number;
    lastError: string | null;
    status: 'pending' | 'syncing' | 'failed';
}

interface TrackAIDB extends DBSchema {
    pendingJobs: {
        key: string;
        value: PendingJob;
        indexes: {
            'by-status': string;
            'by-created': Date;
        };
    };
    cachedProjects: {
        key: string;
        value: {
            externalId: string;
            name: string;
            description: string | null;
            cachedAt: Date;
        };
    };
}

const DB_NAME = 'track-ai-offline';
const DB_VERSION = 1;

let dbInstance: IDBPDatabase<TrackAIDB> | null = null;

export async function getDb(): Promise<IDBPDatabase<TrackAIDB>> {
    if (dbInstance) {
        return dbInstance;
    }

    dbInstance = await openDB<TrackAIDB>(DB_NAME, DB_VERSION, {
        upgrade(db) {
            // Pending jobs store
            if (!db.objectStoreNames.contains('pendingJobs')) {
                const jobStore = db.createObjectStore('pendingJobs', {
                    keyPath: 'id',
                });
                jobStore.createIndex('by-status', 'status');
                jobStore.createIndex('by-created', 'createdAt');
            }

            // Cached projects store
            if (!db.objectStoreNames.contains('cachedProjects')) {
                db.createObjectStore('cachedProjects', {
                    keyPath: 'externalId',
                });
            }
        },
    });

    return dbInstance;
}

// Job operations
export async function addJob(job: Omit<PendingJob, 'id' | 'clientRequestId' | 'createdAt' | 'retryCount' | 'lastError' | 'status'>): Promise<PendingJob> {
    const db = await getDb();
    // Generate a stable client_request_id that will be used for idempotency on replay
    const clientRequestId = crypto.randomUUID();
    const newJob: PendingJob = {
        ...job,
        id: crypto.randomUUID(),
        clientRequestId,
        // Include client_request_id in payload for backend idempotency
        payload: {
            ...job.payload,
            client_request_id: clientRequestId,
        },
        createdAt: new Date(),
        retryCount: 0,
        lastError: null,
        status: 'pending',
    };
    await db.put('pendingJobs', newJob);
    return newJob;
}

export async function getJob(id: string): Promise<PendingJob | undefined> {
    const db = await getDb();
    return db.get('pendingJobs', id);
}

export async function getAllPendingJobs(): Promise<PendingJob[]> {
    const db = await getDb();
    return db.getAllFromIndex('pendingJobs', 'by-status', 'pending');
}

export async function getAllJobs(): Promise<PendingJob[]> {
    const db = await getDb();
    return db.getAll('pendingJobs');
}

export async function updateJobStatus(id: string, status: PendingJob['status'], error?: string): Promise<void> {
    const db = await getDb();
    const job = await db.get('pendingJobs', id);
    if (job) {
        job.status = status;
        if (error) {
            job.lastError = error;
            job.retryCount += 1;
        }
        await db.put('pendingJobs', job);
    }
}

export async function deleteJob(id: string): Promise<void> {
    const db = await getDb();
    await db.delete('pendingJobs', id);
}

export async function clearCompletedJobs(): Promise<void> {
    const db = await getDb();
    const jobs = await db.getAll('pendingJobs');
    const tx = db.transaction('pendingJobs', 'readwrite');
    for (const job of jobs) {
        if (job.status !== 'pending' && job.status !== 'syncing') {
            await tx.store.delete(job.id);
        }
    }
    await tx.done;
}

export async function getPendingJobCount(): Promise<number> {
    const db = await getDb();
    return db.countFromIndex('pendingJobs', 'by-status', 'pending');
}

// Project cache operations
export async function cacheProject(project: TrackAIDB['cachedProjects']['value']): Promise<void> {
    const db = await getDb();
    await db.put('cachedProjects', project);
}

export async function getCachedProjects(): Promise<TrackAIDB['cachedProjects']['value'][]> {
    const db = await getDb();
    return db.getAll('cachedProjects');
}

export async function clearProjectCache(): Promise<void> {
    const db = await getDb();
    await db.clear('cachedProjects');
}
