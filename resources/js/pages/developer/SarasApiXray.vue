<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, watch, onMounted } from 'vue';
import { Search, X, Radio, Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import axios from 'axios';

interface Trace {
    id: number;
    trace_id: string | null;
    operation: string | null;
    method: string;
    endpoint: string;
    request_body: any;
    response_body: any;
    status_code: number;
    duration_ms: number;
    user: { id: number; name: string } | null;
    error_message: string | null;
    created_at: string;
}

const traces = ref<Trace[]>([]);
const isLoading = ref(false);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });

// Filters
const search = ref('');
const statusFilter = ref('all');
const operationFilter = ref('all');

// Detail drawer
const selectedTrace = ref<Trace | null>(null);
const drawerOpen = ref(false);
const activeTab = ref<'request' | 'response'>('request');

async function fetchTraces(page = 1) {
    isLoading.value = true;
    try {
        const params: Record<string, any> = { page };
        if (search.value) params.search = search.value;
        if (statusFilter.value !== 'all') params.status = statusFilter.value;
        if (operationFilter.value !== 'all') params.operation = operationFilter.value;

        const r = await axios.get('/developer/api/traces', { params });
        if (r.data.success) {
            traces.value = r.data.data;
            meta.value = r.data.meta;
        }
    } catch { /* ignore */ } finally { isLoading.value = false; }
}

let searchTimeout: number;
watch([search, statusFilter, operationFilter], () => {
    clearTimeout(searchTimeout);
    searchTimeout = window.setTimeout(() => fetchTraces(), 300);
});

onMounted(() => fetchTraces());

function openDetail(trace: Trace) {
    selectedTrace.value = trace;
    activeTab.value = 'request';
    drawerOpen.value = true;
}

function statusVariant(code: number): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (code >= 200 && code < 300) return 'default';
    if (code >= 400 && code < 500) return 'secondary';
    if (code >= 500) return 'destructive';
    return 'outline';
}

function statusLabel(code: number): string {
    if (code === 0) return 'ERR';
    return String(code);
}

function formatTime(dateStr: string): string {
    return new Date(dateStr).toLocaleTimeString();
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleString();
}

function formatJson(data: any): string {
    if (!data) return '—';
    return JSON.stringify(data, null, 2);
}
</script>

<template>
    <div class="min-h-screen bg-background">
        <Head title="Saras API X-Ray" />

        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="flex items-center justify-between px-6 py-4">
                <div>
                    <div class="flex items-center gap-2">
                        <Radio class="h-6 w-6 text-primary" />
                        <h1 class="text-xl font-semibold">Saras API X-Ray</h1>
                    </div>
                    <p class="text-sm text-muted-foreground mt-1">Every API call Track AI sent to Saras AI</p>
                </div>
                <Badge variant="outline">{{ meta.total }} traces</Badge>
            </div>
        </header>

        <main class="p-6 space-y-4">
            <!-- Filters -->
            <div class="flex gap-3 flex-wrap">
                <div class="relative flex-1 min-w-[200px]">
                    <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" placeholder="Search endpoints, operations, errors..." class="pl-9" />
                </div>
                <Select v-model="statusFilter">
                    <SelectTrigger class="w-32"><SelectValue placeholder="Status" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Status</SelectItem>
                        <SelectItem value="success">Success</SelectItem>
                        <SelectItem value="error">Errors</SelectItem>
                    </SelectContent>
                </Select>
                <Select v-model="operationFilter">
                    <SelectTrigger class="w-44"><SelectValue placeholder="Operation" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Operations</SelectItem>
                        <SelectItem value="createProcess">createProcess</SelectItem>
                        <SelectItem value="getProcess">getProcess</SelectItem>
                        <SelectItem value="uploadFiles">uploadFiles</SelectItem>
                        <SelectItem value="updateFiles">updateFiles</SelectItem>
                        <SelectItem value="executeWorkflow">executeWorkflow</SelectItem>
                        <SelectItem value="getWorkflowRuns">getWorkflowRuns</SelectItem>
                        <SelectItem value="getProjectsForUser">getProjectsForUser</SelectItem>
                    </SelectContent>
                </Select>
                <Button variant="outline" size="sm" @click="fetchTraces()">
                    <Loader2 v-if="isLoading" class="h-4 w-4 animate-spin" />
                    <span v-else>Refresh</span>
                </Button>
            </div>

            <!-- Table -->
            <Card>
                <CardContent class="p-0">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-muted/50">
                                    <th class="text-left px-4 py-3 font-medium">Time</th>
                                    <th class="text-left px-4 py-3 font-medium">Operation</th>
                                    <th class="text-left px-4 py-3 font-medium">Method</th>
                                    <th class="text-left px-4 py-3 font-medium">Endpoint</th>
                                    <th class="text-left px-4 py-3 font-medium">Status</th>
                                    <th class="text-right px-4 py-3 font-medium">Duration</th>
                                    <th class="text-left px-4 py-3 font-medium">Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="isLoading && !traces.length">
                                    <td colspan="7" class="text-center py-12 text-muted-foreground">
                                        <Loader2 class="h-6 w-6 animate-spin mx-auto mb-2" />
                                        Loading traces...
                                    </td>
                                </tr>
                                <tr v-else-if="!traces.length">
                                    <td colspan="7" class="text-center py-12 text-muted-foreground">
                                        No API traces found.
                                    </td>
                                </tr>
                                <tr
                                    v-for="trace in traces"
                                    :key="trace.id"
                                    class="border-b hover:bg-muted/30 cursor-pointer transition-colors"
                                    @click="openDetail(trace)"
                                >
                                    <td class="px-4 py-3 font-mono text-xs text-muted-foreground whitespace-nowrap">{{ formatTime(trace.created_at) }}</td>
                                    <td class="px-4 py-3">
                                        <Badge variant="outline" class="font-mono text-xs">{{ trace.operation || '—' }}</Badge>
                                    </td>
                                    <td class="px-4 py-3">
                                        <Badge :variant="trace.method === 'POST' ? 'default' : 'secondary'" class="text-xs">{{ trace.method }}</Badge>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs max-w-[300px] truncate">{{ trace.endpoint }}</td>
                                    <td class="px-4 py-3">
                                        <Badge :variant="statusVariant(trace.status_code)" class="text-xs">{{ statusLabel(trace.status_code) }}</Badge>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono text-xs text-muted-foreground">{{ Math.round(trace.duration_ms) }}ms</td>
                                    <td class="px-4 py-3 text-xs text-destructive max-w-[200px] truncate">{{ trace.error_message || '' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="meta.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t">
                        <span class="text-xs text-muted-foreground">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                        <div class="flex gap-2">
                            <Button size="sm" variant="outline" :disabled="meta.current_page <= 1" @click="fetchTraces(meta.current_page - 1)">Previous</Button>
                            <Button size="sm" variant="outline" :disabled="meta.current_page >= meta.last_page" @click="fetchTraces(meta.current_page + 1)">Next</Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </main>

        <!-- Detail Drawer -->
        <Sheet v-model:open="drawerOpen">
            <SheetContent class="w-full sm:max-w-xl overflow-y-auto">
                <SheetHeader>
                    <SheetTitle class="flex items-center gap-2">
                        <Badge :variant="statusVariant(selectedTrace?.status_code ?? 0)" class="text-xs">{{ selectedTrace?.status_code }}</Badge>
                        <span class="font-mono text-sm">{{ selectedTrace?.operation }}</span>
                    </SheetTitle>
                </SheetHeader>

                <div v-if="selectedTrace" class="mt-4 space-y-4">
                    <!-- Summary -->
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div class="text-muted-foreground">Method</div>
                        <div class="font-mono">{{ selectedTrace.method }}</div>
                        <div class="text-muted-foreground">Endpoint</div>
                        <div class="font-mono text-xs break-all">{{ selectedTrace.endpoint }}</div>
                        <div class="text-muted-foreground">Duration</div>
                        <div class="font-mono">{{ Math.round(selectedTrace.duration_ms) }}ms</div>
                        <div class="text-muted-foreground">Time</div>
                        <div>{{ formatDate(selectedTrace.created_at) }}</div>
                        <div class="text-muted-foreground">Trace ID</div>
                        <div class="font-mono text-xs break-all">{{ selectedTrace.trace_id || '—' }}</div>
                        <div class="text-muted-foreground">User</div>
                        <div>{{ selectedTrace.user?.name || '—' }}</div>
                    </div>

                    <!-- Error -->
                    <div v-if="selectedTrace.error_message" class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                        {{ selectedTrace.error_message }}
                    </div>

                    <!-- Tabs -->
                    <div class="flex gap-1 border-b">
                        <button
                            v-for="tab in ['request', 'response'] as const"
                            :key="tab"
                            class="px-4 py-2 text-sm font-medium border-b-2 transition-colors"
                            :class="activeTab === tab ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                            @click="activeTab = tab"
                        >
                            {{ tab === 'request' ? 'Request' : 'Response' }}
                        </button>
                    </div>

                    <!-- JSON -->
                    <pre class="rounded-lg bg-muted p-4 text-xs font-mono overflow-x-auto max-h-[60vh] whitespace-pre-wrap break-words">{{ activeTab === 'request' ? formatJson(selectedTrace.request_body) : formatJson(selectedTrace.response_body) }}</pre>
                </div>
            </SheetContent>
        </Sheet>
    </div>
</template>
