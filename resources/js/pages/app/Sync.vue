<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { RefreshCw, Clock, AlertTriangle, CheckCircle, Trash2, Wifi, WifiOff } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { clearCompletedJobs, deleteJob } from '@/lib/offlineDb';
import { retrySingleJob } from '@/lib/syncManager';
import { ref } from 'vue';

const { pendingCount, jobs, syncStatus, isOnline, triggerSync, refreshJobs } = useOfflineQueue();

const retryingJob = ref<string | null>(null);

const handleRetry = async (jobId: string) => {
    retryingJob.value = jobId;
    await retrySingleJob(jobId);
    await refreshJobs();
    retryingJob.value = null;
};

const handleDelete = async (jobId: string) => {
    await deleteJob(jobId);
    await refreshJobs();
};

const handleClearCompleted = async () => {
    await clearCompletedJobs();
    await refreshJobs();
};

const formatDate = (date: Date) => {
    return new Date(date).toLocaleString();
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'pending': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-200';
        case 'syncing': return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200';
        case 'failed': return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200';
        default: return 'bg-gray-100 text-gray-800';
    }
};
</script>

<template>
    <div class="min-h-screen bg-background pb-20">
        <Head title="Sync" />

        <!-- Header -->
        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2">
                    <RefreshCw class="h-6 w-6 text-primary" />
                    <h1 class="text-lg font-semibold">Sync Queue</h1>
                </div>
                <div class="flex items-center gap-2">
                    <Wifi v-if="isOnline" class="h-5 w-5 text-green-600" />
                    <WifiOff v-else class="h-5 w-5 text-red-600" />
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="p-4 space-y-4">
            <!-- Status Card -->
            <Card>
                <CardContent class="pt-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-2xl font-bold">{{ pendingCount }}</p>
                            <p class="text-sm text-muted-foreground">Pending items</p>
                        </div>
                        <div class="text-right">
                            <p v-if="syncStatus.lastSyncAt" class="text-sm text-muted-foreground">
                                Last sync: {{ formatDate(syncStatus.lastSyncAt) }}
                            </p>
                            <p v-if="syncStatus.lastError" class="text-sm text-destructive">
                                {{ syncStatus.lastError }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <Button
                            @click="triggerSync"
                            :disabled="syncStatus.isSyncing || !isOnline || pendingCount === 0"
                            class="flex-1"
                        >
                            <RefreshCw :class="{ 'animate-spin': syncStatus.isSyncing }" class="mr-2 h-4 w-4" />
                            Sync Now
                        </Button>
                        <Button
                            variant="outline"
                            @click="handleClearCompleted"
                            :disabled="jobs.filter(j => j.status === 'failed').length === 0"
                        >
                            <Trash2 class="mr-2 h-4 w-4" />
                            Clear Failed
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Queue List -->
            <Card v-if="jobs.length > 0">
                <CardHeader>
                    <CardTitle class="text-base">Queue Items</CardTitle>
                    <CardDescription>{{ jobs.length }} total items</CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="job in jobs"
                        :key="job.id"
                        class="rounded-lg border p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <Badge :class="getStatusColor(job.status)" variant="secondary">
                                        {{ job.status }}
                                    </Badge>
                                    <span class="text-xs text-muted-foreground">
                                        {{ job.method }}
                                    </span>
                                </div>
                                <p class="mt-1 truncate text-sm font-medium">
                                    {{ job.endpoint }}
                                </p>
                                <div class="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                                    <span class="flex items-center gap-1">
                                        <Clock class="h-3 w-3" />
                                        {{ formatDate(job.createdAt) }}
                                    </span>
                                    <span v-if="job.retryCount > 0" class="flex items-center gap-1">
                                        <AlertTriangle class="h-3 w-3 text-yellow-600" />
                                        {{ job.retryCount }} retries
                                    </span>
                                </div>
                                <p v-if="job.lastError" class="mt-1 text-xs text-destructive truncate">
                                    {{ job.lastError }}
                                </p>
                            </div>
                            <div class="flex gap-1">
                                <Button
                                    v-if="job.status === 'pending' || job.status === 'failed'"
                                    variant="ghost"
                                    size="icon"
                                    @click="handleRetry(job.id)"
                                    :disabled="retryingJob === job.id || !isOnline"
                                >
                                    <RefreshCw
                                        class="h-4 w-4"
                                        :class="{ 'animate-spin': retryingJob === job.id }"
                                    />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    @click="handleDelete(job.id)"
                                >
                                    <Trash2 class="h-4 w-4 text-destructive" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Empty State -->
            <Card v-else>
                <CardContent class="py-12 text-center">
                    <CheckCircle class="mx-auto h-12 w-12 text-green-500" />
                    <h3 class="mt-4 text-lg font-medium">All synced!</h3>
                    <p class="mt-2 text-sm text-muted-foreground">
                        No pending items to sync.
                    </p>
                </CardContent>
            </Card>
        </main>

        <AppBottomNav />
    </div>
</template>
