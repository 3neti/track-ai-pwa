<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import { ClipboardCheck, Sparkles, Loader2, AlertCircle, Clock, Upload, Camera, Award, Info, ChevronDown, ChevronUp } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import ContractIndicator from '@/components/app/ContractIndicator.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { useActiveContract } from '@/composables/useActiveContract';
import { useGeolocation } from '@/composables/useGeolocation';
import {
    list as listProgressReports,
    store as storeProgressReport,
    workflowStatus,
} from '@/actions/App/Http/Controllers/App/ProjectProgressController';
import { milestoneProgress } from '@/routes/api/contracts';
import axios from 'axios';

interface Project { id: number; external_id: string; name: string; }
interface Contract {
    id: string;
    local_id: number;
    saras_process_id: string;
    name: string;
    milestones: string[];
    display_number: string;
}
interface ProgressReport {
    id: number; progress_status: string; current_milestone: string | null;
    contract_id: string | null; remarks: string | null; saras_process_id: string | null;
    saras_workflow_run_id: string | null; completion_status: string | null;
    certificate_file_id: string | null; previous_progress_file_ids: string[] | null;
    current_progress_file_ids: string[] | null; created_at: string;
}
interface UploadedFile { id: number; remote_file_id: string | null; title: string; status: string; }
interface MilestoneStatus { has_progress: boolean; has_certificate: boolean; status: string; }

const props = defineProps<{ projects: Project[]; contracts: Contract[]; defaultProjectId?: string; }>();

const { pendingCount, syncStatus, isOnline, triggerSync } = useOfflineQueue();
const { activeContractId, clearActiveContract } = useActiveContract();
const { state: geoState, getCurrentPosition } = useGeolocation();

// Resolve project internally (hidden from user)
const defaultProject = props.defaultProjectId
    ? props.projects.find(p => p.external_id === props.defaultProjectId)
    : null;
const selectedProject = computed(() => defaultProject || props.projects[0]);
const selectedContract = computed(() => props.contracts.find((contract) =>
    String(contract.id) === String(activeContractId.value) ||
    String(contract.local_id) === String(activeContractId.value) ||
    String(contract.saras_process_id) === String(activeContractId.value)
));
const selectedContractId = computed(() => selectedContract.value?.saras_process_id ?? null);
const hasStaleActiveContract = computed(() => Boolean(activeContractId.value && !selectedContract.value));

// State
const reports = ref<ProgressReport[]>([]);
const isLoadingReports = ref(false);
const milestoneStatuses = ref<Record<string, MilestoneStatus>>({});
const isPolling = ref(false);
const message = ref<{ type: 'success' | 'error'; text: string } | null>(null);

// Per-milestone upload state
const expandedMilestone = ref<string | null>(null);
const uploadFiles = ref<Record<string, UploadedFile[]>>({});
const uploadRemarks = ref<Record<string, string>>({});
const uploadTags = ref<Record<string, string[]>>({});
const uploadTagInput = ref<Record<string, string>>({});
const isUploading = ref<Record<string, boolean>>({});
const isSubmitting = ref<Record<string, boolean>>({});
const submitStep = ref<Record<string, string | null>>({});

// Load reports + milestone statuses
async function loadData() {
    if (!selectedProject.value || !selectedContractId.value) return;
    isLoadingReports.value = true;
    try {
        const [reportsRes, statusRes] = await Promise.all([
            axios.get(listProgressReports.url(selectedProject.value.id)),
            axios.get(milestoneProgress.url(selectedContractId.value)),
        ]);
        if (reportsRes.data.success) reports.value = reportsRes.data.data;
        if (statusRes.data.success) milestoneStatuses.value = statusRes.data.milestones;
    } catch (error: any) {
        console.error('[TrackAI] failed to load progress data:', {
            contractId: selectedContractId.value,
            status: error.response?.status,
            response: error.response?.data,
        });
        message.value = {
            type: 'error',
            text: error.response?.data?.message || 'Unable to load milestone progress.',
        };
    } finally {
        isLoadingReports.value = false;
    }
}

onMounted(() => {
    if (hasStaleActiveContract.value) {
        clearActiveContract();
        router.visit('/app/contracts');
        return;
    }

    if (import.meta.env.DEV) {
        console.log('[TrackAI] contracts:', props.contracts);
        console.log('[TrackAI] resolved selected contract:', selectedContract.value);
        console.log('[TrackAI] milestones/stages:', selectedContract.value?.milestones ?? []);
    }
    loadData();
    getCurrentPosition();
});
watch(activeContractId, loadData);

function addTag(milestone: string) {
    if (isMilestoneLocked(milestone)) return;
    const input = (uploadTagInput.value[milestone] ?? '').trim().toLowerCase();
    if (!input) return;
    if (!uploadTags.value[milestone]) uploadTags.value[milestone] = [];
    if (!uploadTags.value[milestone].includes(input)) uploadTags.value[milestone].push(input);
    uploadTagInput.value[milestone] = '';
}

function removeTag(milestone: string, index: number) {
    if (isMilestoneLocked(milestone)) return;
    uploadTags.value[milestone]?.splice(index, 1);
}

// Reports grouped by milestone
function reportsForMilestone(milestone: string): ProgressReport[] {
    return reports.value
        .filter(r => r.current_milestone === milestone && r.contract_id === selectedContractId.value)
        .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());
}

function milestoneStatus(milestone: string): MilestoneStatus | null {
    return milestoneStatuses.value[milestone] ?? null;
}

function isMilestoneLocked(milestone: string): boolean {
    const status = milestoneStatus(milestone);

    return Boolean(status?.has_progress && !status.has_certificate);
}

function toggleMilestone(milestone: string) {
    expandedMilestone.value = expandedMilestone.value === milestone ? null : milestone;
}

// File upload per milestone
async function handleFileUpload(event: Event, milestone: string) {
    const target = event.target as HTMLInputElement;
    if (isMilestoneLocked(milestone)) {
        target.value = '';
        message.value = { type: 'error', text: 'This milestone is already in progress and cannot be edited.' };
        return;
    }
    if (!target.files?.length || !selectedProject.value) return;
    const files = Array.from(target.files);
    isUploading.value[milestone] = true;
    if (!uploadFiles.value[milestone]) uploadFiles.value[milestone] = [];

    for (const file of files) {
        try {
            const cr = await axios.post(`/api/projects/${selectedProject.value.id}/uploads`, {
                contract_id: selectedContractId.value,
                client_request_id: crypto.randomUUID(),
                title: file.name,
                document_type: 'current_progress',
                tags: ['progress', 'current_progress', milestone],
            });
            if (!cr.data.success) continue;
            const fd = new FormData(); fd.append('file', file);
            const ur = await axios.post(
                `/api/projects/${selectedProject.value.id}/uploads/${cr.data.upload.id}/file`,
                fd, { headers: { 'Content-Type': 'multipart/form-data' } }
            );
            if (ur.data.success && ur.data.upload) {
                uploadFiles.value[milestone].push({
                    id: ur.data.upload.id, remote_file_id: ur.data.upload.remote_file_id,
                    title: file.name, status: ur.data.upload.status,
                });
            }
        } catch (err) { console.error(`Failed to upload ${file.name}`, err); }
    }
    isUploading.value[milestone] = false;
    target.value = '';
}

function removeFile(milestone: string, index: number) {
    if (isMilestoneLocked(milestone)) return;
    uploadFiles.value[milestone]?.splice(index, 1);
}

// Submit progress for a milestone (no updateFiles call — backend handles previousProgressFiles)
async function handleSubmit(milestone: string) {
    if (!selectedProject.value || isSubmitting.value[milestone]) return;
    if (isMilestoneLocked(milestone)) {
        message.value = { type: 'error', text: 'This milestone is already in progress and cannot be edited.' };
        return;
    }
    isSubmitting.value[milestone] = true;
    message.value = null;

    const files = uploadFiles.value[milestone] ?? [];
    const currIds = files.filter(f => f.remote_file_id).map(f => f.remote_file_id!);

    // Capture geolocation
    await getCurrentPosition();
    const geoLocation = geoState.value.latitude && geoState.value.longitude
        ? `${geoState.value.latitude},${geoState.value.longitude}` : '';

    try {
        submitStep.value[milestone] = 'Creating progress report...';
        const r = await axios.post(storeProgressReport.url(selectedProject.value.id), {
            contract_id: selectedContractId.value,
            current_milestone: milestone,
            remarks: uploadRemarks.value[milestone] || null,
            tags: uploadTags.value[milestone] ?? [],
            geo_location: geoLocation,
            current_progress_file_ids: currIds,
        });
        if (!r.data.success) throw new Error(r.data.message);

        message.value = { type: 'success', text: `Progress report for ${milestone} submitted. AI evaluation will start automatically.` };
        uploadFiles.value[milestone] = [];
        uploadRemarks.value[milestone] = '';
        uploadTags.value[milestone] = [];
        expandedMilestone.value = null;
        await loadData();
    } catch (error: any) {
        message.value = { type: 'error', text: error.response?.data?.message || error.message || 'Failed to submit.' };
    } finally {
        isSubmitting.value[milestone] = false;
        submitStep.value[milestone] = null;
    }
}

async function handlePollStatus(report: ProgressReport) {
    isPolling.value = true;
    try { await axios.get(workflowStatus.url(report.id)); await loadData(); }
    catch { /* ignore */ } finally { isPolling.value = false; }
}

const statusConfig = (s: string) => ({
    draft: { label: 'Draft', variant: 'secondary' as const },
    submitted: { label: 'Submitted', variant: 'outline' as const },
    processing: { label: 'AI Processing', variant: 'default' as const },
    evaluated: { label: 'Evaluated', variant: 'default' as const },
    failed: { label: 'Failed', variant: 'destructive' as const },
}[s] || { label: s, variant: 'secondary' as const });

const canSubmitMilestone = (milestone: string) => {
    return !isMilestoneLocked(milestone) && !isSubmitting.value[milestone] && (uploadFiles.value[milestone]?.length ?? 0) > 0;
};
</script>

<template>
    <div class="min-h-screen bg-background pb-20">
        <Head title="Progress" />
        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2">
                    <ClipboardCheck class="h-6 w-6 text-primary" />
                    <h1 class="text-lg font-semibold">Progress</h1>
                </div>
            </div>
        </header>

        <ContractIndicator />

        <main class="p-4 space-y-4">
            <Alert v-if="message" :variant="message.type === 'error' ? 'destructive' : 'default'">
                <AlertCircle class="h-4 w-4" />
                <AlertDescription>{{ message.text }}</AlertDescription>
            </Alert>

            <!-- Loading -->
            <div v-if="isLoadingReports" class="space-y-3">
                <div v-for="i in 3" :key="i" class="rounded-lg border p-4 space-y-3 animate-pulse">
                    <div class="h-5 bg-muted rounded w-1/3"></div>
                    <div class="h-3 bg-muted rounded w-2/3"></div>
                </div>
            </div>

            <!-- No contract -->
            <Card v-else-if="!selectedContract">
                <CardContent class="py-12 text-center">
                    <ClipboardCheck class="h-12 w-12 mx-auto text-muted-foreground/50 mb-4" />
                    <p class="text-muted-foreground">
                        {{ hasStaleActiveContract ? 'The previously selected contract is no longer available from Saras. Please select a current contract.' : 'Select a contract to view milestones.' }}
                    </p>
                </CardContent>
            </Card>

            <!-- Milestone cards -->
            <template v-else>
                <Card v-for="milestone in selectedContract.milestones" :key="milestone">
                    <CardHeader class="pb-2 cursor-pointer" @click="toggleMilestone(milestone)">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <CardTitle class="text-base">{{ milestone }}</CardTitle>
                                <Badge v-if="milestoneStatus(milestone)?.has_certificate" variant="default" class="text-xs bg-green-600">Certificate</Badge>
                                <Badge v-else-if="milestoneStatus(milestone)?.has_progress" variant="outline" class="text-xs">In Progress</Badge>
                                <Badge v-else variant="secondary" class="text-xs">Not Started</Badge>
                            </div>
                            <ChevronDown v-if="expandedMilestone !== milestone" class="h-4 w-4 text-muted-foreground" />
                            <ChevronUp v-else class="h-4 w-4 text-muted-foreground" />
                        </div>
                        <p class="text-xs text-muted-foreground">{{ reportsForMilestone(milestone).length }} update(s)</p>
                    </CardHeader>

                    <CardContent v-if="expandedMilestone === milestone" class="space-y-4">
                        <!-- Progress update history -->
                        <div v-if="reportsForMilestone(milestone).length" class="space-y-2">
                            <p class="text-xs font-medium text-muted-foreground">Progress History</p>
                            <div v-for="report in reportsForMilestone(milestone)" :key="report.id" class="rounded-md border p-3 space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-muted-foreground">{{ new Date(report.created_at).toLocaleString() }}</span>
                                    <Badge :variant="statusConfig(report.progress_status).variant" class="text-xs">{{ statusConfig(report.progress_status).label }}</Badge>
                                </div>
                                <p v-if="report.remarks" class="text-sm text-muted-foreground">{{ report.remarks }}</p>
                                <div class="flex gap-3 text-xs text-muted-foreground">
                                    <span v-if="report.previous_progress_file_ids?.length">{{ report.previous_progress_file_ids.length }} prev</span>
                                    <span v-if="report.current_progress_file_ids?.length">{{ report.current_progress_file_ids.length }} current</span>
                                </div>
                                <div v-if="report.certificate_file_id" class="flex items-center gap-2 p-2 rounded bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800">
                                    <Award class="h-3 w-3 text-green-600" />
                                    <span class="text-xs text-green-700 dark:text-green-300">Certificate available</span>
                                </div>
                                <Button v-if="report.saras_process_id && ['submitted', 'processing'].includes(report.progress_status)" size="sm" variant="outline" class="h-7 text-xs" @click.stop="handlePollStatus(report)" :disabled="isPolling">
                                    <Loader2 v-if="isPolling" class="mr-1 h-3 w-3 animate-spin" /><Clock v-else class="mr-1 h-3 w-3" /> Check Status
                                </Button>
                            </div>
                        </div>

                        <Separator />

                        <Alert v-if="isMilestoneLocked(milestone)" variant="default" class="border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                            <Info class="h-4 w-4" />
                            <AlertDescription>
                                This milestone is already in progress. Remarks, tags, uploads, and resubmission are locked until the workflow completes.
                            </AlertDescription>
                        </Alert>

                        <!-- Previous progress info -->
                        <div class="flex items-center gap-2 p-3 rounded-md border border-dashed bg-muted/30">
                            <Info class="h-4 w-4 text-muted-foreground flex-shrink-0" />
                            <p v-if="!reportsForMilestone(milestone).length" class="text-sm text-muted-foreground">No previous progress photos yet. This is the first report for this milestone.</p>
                            <p v-else class="text-sm text-muted-foreground">Previous progress photos will be auto-filled from the last progress update.</p>
                        </div>

                        <!-- Upload current progress -->
                        <div class="space-y-2">
                            <Label class="flex items-center gap-2"><Camera class="h-4 w-4" /> Current Progress Photos</Label>
                            <div v-if="(uploadFiles[milestone] ?? []).length" class="space-y-1">
                                <div v-for="(file, i) in uploadFiles[milestone]" :key="file.id" class="flex items-center justify-between text-sm bg-muted/50 rounded px-3 py-1.5">
                                    <span class="truncate">{{ file.title }}</span>
                                    <div class="flex items-center gap-2">
                                        <Badge variant="outline" class="text-xs">{{ file.remote_file_id ? 'uploaded' : file.status }}</Badge>
                                        <button @click="removeFile(milestone, i)" class="text-muted-foreground hover:text-destructive disabled:cursor-not-allowed disabled:opacity-50" :disabled="isMilestoneLocked(milestone)">×</button>
                                    </div>
                                </div>
                            </div>
                            <label :class="isMilestoneLocked(milestone) ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'">
                                <Button variant="outline" size="sm" as="span" :disabled="isUploading[milestone] || isMilestoneLocked(milestone)">
                                    <Loader2 v-if="isUploading[milestone]" class="mr-1 h-3 w-3 animate-spin" /><Upload v-else class="mr-1 h-3 w-3" /> Add Photos
                                </Button>
                                <input type="file" accept="image/*" multiple capture="environment" class="hidden" :disabled="isMilestoneLocked(milestone)" @change="(e) => handleFileUpload(e, milestone)" />
                            </label>
                        </div>

                        <!-- Remarks -->
                        <div class="grid gap-2">
                            <Label>Engineer's Remarks</Label>
                            <Textarea v-model="uploadRemarks[milestone]" placeholder="Describe current progress..." rows="2" :disabled="isMilestoneLocked(milestone)" />
                        </div>

                        <!-- Tags -->
                        <div class="grid gap-2">
                            <Label>Tags</Label>
                            <div class="flex flex-wrap gap-1.5 min-h-[1.5rem]">
                                <span v-for="(tag, i) in (uploadTags[milestone] ?? [])" :key="tag" class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                                    {{ tag }}
                                    <button @click="removeTag(milestone, i)" class="ml-0.5 rounded-full hover:bg-primary/20 p-0.5 disabled:cursor-not-allowed disabled:opacity-50" type="button" :disabled="isMilestoneLocked(milestone)">
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </span>
                            </div>
                            <div class="flex gap-2">
                                <Input v-model="uploadTagInput[milestone]" placeholder="Add a tag..." class="flex-1" :disabled="isMilestoneLocked(milestone)" @keydown.enter.prevent="addTag(milestone)" />
                                <Button variant="outline" size="sm" type="button" @click="addTag(milestone)" :disabled="isMilestoneLocked(milestone) || !(uploadTagInput[milestone] ?? '').trim()">Add</Button>
                            </div>
                        </div>

                        <!-- Submit -->
                        <Button @click="handleSubmit(milestone)" :disabled="!canSubmitMilestone(milestone)" class="w-full">
                            <Loader2 v-if="isSubmitting[milestone]" class="mr-2 h-4 w-4 animate-spin" />
                            <Sparkles v-else class="mr-2 h-4 w-4" />
                            {{ isMilestoneLocked(milestone) ? 'Milestone In Progress' : (submitStep[milestone] || 'Submit & Run AI Evaluation') }}
                        </Button>
                    </CardContent>
                </Card>
            </template>
        </main>
        <AppBottomNav />
    </div>
</template>
