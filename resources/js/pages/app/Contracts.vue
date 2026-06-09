<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Briefcase, RefreshCw, Loader2, AlertCircle, Award, Download, ChevronRight, Info } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import SyncBadge from '@/components/app/SyncBadge.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import axios from 'axios';

interface ContractItem {
    id: number;
    saras_process_id: string;
    name: string;
    display_number: string | null;
    milestones: string[];
    certificate_status: string;
    certificate_file_id: string | null;
    last_synced_at: string | null;
}

const props = defineProps<{ contracts: ContractItem[] }>();

const { pendingCount, syncStatus, isOnline, triggerSync } = useOfflineQueue();

const contractList = ref<ContractItem[]>(props.contracts);
const isRefreshing = ref(false);
const error = ref<string | null>(null);
const downloadingId = ref<number | null>(null);
const infoMessage = ref<string | null>(null);

async function handleRefresh() {
    isRefreshing.value = true;
    error.value = null;
    try {
        const r = await axios.post('/api/contracts/refresh');
        if (r.data.success) {
            contractList.value = r.data.contracts;
        } else {
            error.value = r.data.message || 'Unable to load contracts. Please try again.';
        }
    } catch {
        error.value = 'Unable to load contracts. Please try again.';
    } finally {
        isRefreshing.value = false;
    }
}

async function handleDownloadCertificate(contract: ContractItem) {
    downloadingId.value = contract.id;
    infoMessage.value = null;
    try {
        const r = await axios.get(`/api/contracts/${contract.id}/certificate`);
        if (r.data.success && r.data.download_url) {
            window.open(r.data.download_url, '_blank');
        } else if (r.data.success && r.data.certificate_file_id) {
            infoMessage.value = r.data.message || `Certificate file: ${r.data.certificate_file_id}`;
        } else {
            error.value = r.data.message || 'Certificate download not available.';
        }
    } catch (err: any) {
        error.value = err.response?.data?.message || 'Certificate download not available.';
    } finally {
        downloadingId.value = null;
    }
}

const certificateConfig = (status: string) => ({
    available: { label: 'Available', variant: 'default' as const, color: 'text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-950' },
    pending: { label: 'Pending', variant: 'outline' as const, color: 'text-yellow-700 dark:text-yellow-300 bg-yellow-50 dark:bg-yellow-950' },
    not_started: { label: 'Not Started', variant: 'secondary' as const, color: '' },
    unknown: { label: 'Unknown', variant: 'secondary' as const, color: '' },
}[status] || { label: status, variant: 'secondary' as const, color: '' });

const lastSyncTime = computed(() => {
    const synced = contractList.value.find(c => c.last_synced_at);
    if (!synced?.last_synced_at) return null;
    return new Date(synced.last_synced_at).toLocaleString();
});
</script>

<template>
    <div class="min-h-screen bg-background pb-20">
        <Head title="Contracts" />
        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2">
                    <Briefcase class="h-6 w-6 text-primary" />
                    <h1 class="text-lg font-semibold">Contracts</h1>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" @click="handleRefresh" :disabled="isRefreshing">
                        <Loader2 v-if="isRefreshing" class="mr-1 h-3 w-3 animate-spin" />
                        <RefreshCw v-else class="mr-1 h-3 w-3" />
                        Refresh
                    </Button>
                    <SyncBadge :pending-count="pendingCount" :is-syncing="syncStatus.isSyncing" :is-online="isOnline" @sync="triggerSync" />
                </div>
            </div>
        </header>

        <main class="p-4 space-y-4">
            <Alert v-if="error" variant="destructive">
                <AlertCircle class="h-4 w-4" />
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <Alert v-if="infoMessage" variant="default">
                <Info class="h-4 w-4" />
                <AlertDescription>{{ infoMessage }}</AlertDescription>
            </Alert>

            <p v-if="lastSyncTime" class="text-xs text-muted-foreground text-right">
                Last synced: {{ lastSyncTime }}
            </p>

            <!-- Loading skeleton -->
            <div v-if="isRefreshing && contractList.length === 0" class="space-y-3">
                <div v-for="i in 3" :key="i" class="rounded-lg border p-4 space-y-3 animate-pulse">
                    <div class="h-5 bg-muted rounded w-3/4"></div>
                    <div class="h-3 bg-muted rounded w-1/2"></div>
                    <div class="h-3 bg-muted rounded w-2/3"></div>
                </div>
            </div>

            <!-- Empty state -->
            <Card v-else-if="contractList.length === 0 && !isRefreshing">
                <CardContent class="py-12 text-center">
                    <Briefcase class="h-12 w-12 mx-auto text-muted-foreground/50 mb-4" />
                    <p class="text-muted-foreground">No contracts available for this account.</p>
                    <Button variant="outline" size="sm" class="mt-4" @click="handleRefresh">
                        <RefreshCw class="mr-1 h-3 w-3" /> Try Refreshing
                    </Button>
                </CardContent>
            </Card>

            <!-- Contract cards -->
            <div v-else class="space-y-3">
                <Card v-for="contract in contractList" :key="contract.id">
                    <CardHeader class="pb-2">
                        <div class="flex items-start justify-between">
                            <div class="space-y-1 flex-1 min-w-0">
                                <CardTitle class="text-base leading-tight">{{ contract.name }}</CardTitle>
                                <p class="text-xs text-muted-foreground font-mono">
                                    {{ contract.saras_process_id.substring(0, 8) }}...
                                    <span v-if="contract.display_number" class="ml-1">#{{ contract.display_number }}</span>
                                </p>
                            </div>
                            <Badge :variant="certificateConfig(contract.certificate_status).variant">
                                {{ certificateConfig(contract.certificate_status).label }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <!-- Milestones -->
                        <div v-if="contract.milestones.length">
                            <p class="text-xs font-medium text-muted-foreground mb-1.5">Milestones</p>
                            <div class="flex flex-wrap gap-1">
                                <Badge v-for="m in contract.milestones" :key="m" variant="outline" class="text-xs">
                                    {{ m }}
                                </Badge>
                            </div>
                        </div>
                        <div v-else>
                            <p class="text-xs text-muted-foreground">No milestones defined</p>
                        </div>

                        <!-- Certificate section -->
                        <div v-if="contract.certificate_status === 'available'" class="space-y-2">
                            <div class="flex items-center gap-2 p-2 rounded border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950">
                                <Award class="h-4 w-4 text-green-600 flex-shrink-0" />
                                <span class="text-sm text-green-700 dark:text-green-300 flex-1">Certificate of Completion</span>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="h-7 text-xs"
                                    @click="handleDownloadCertificate(contract)"
                                    :disabled="downloadingId === contract.id"
                                >
                                    <Loader2 v-if="downloadingId === contract.id" class="mr-1 h-3 w-3 animate-spin" />
                                    <Download v-else class="mr-1 h-3 w-3" />
                                    Download
                                </Button>
                            </div>
                            <p v-if="contract.certificate_file_id" class="text-xs text-muted-foreground font-mono px-2">
                                File: {{ contract.certificate_file_id.substring(0, 8) }}...
                            </p>
                        </div>
                        <div v-else-if="contract.certificate_status === 'pending'" class="flex items-center gap-2 p-2 rounded border border-yellow-200 dark:border-yellow-800 bg-yellow-50 dark:bg-yellow-950">
                            <Award class="h-4 w-4 text-yellow-600 flex-shrink-0" />
                            <span class="text-sm text-yellow-700 dark:text-yellow-300">Certificate pending AI evaluation</span>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </main>
        <AppBottomNav />
    </div>
</template>
