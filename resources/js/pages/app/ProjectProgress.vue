<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { ClipboardCheck, Sparkles, Loader2, AlertCircle, Clock, Upload, Camera, Award } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import SyncBadge from '@/components/app/SyncBadge.vue';
import ProjectSelector from '@/components/app/ProjectSelector.vue';
import ContractSelector from '@/components/app/ContractSelector.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { useActiveProject } from '@/composables/useActiveProject';
import { useActiveContract } from '@/composables/useActiveContract';
import axios from 'axios';

interface Project { id: number; external_id: string; name: string; }
interface Contract { id: string; name: string; milestones: string[]; display_number: string; }
interface ProgressReport {
    id: number; progress_status: string; current_milestone: string | null;
    remarks: string | null; saras_process_id: string | null;
    saras_workflow_run_id: string | null; completion_status: string | null;
    certificate_file_id: string | null; previous_progress_file_ids: string[] | null;
    current_progress_file_ids: string[] | null; created_at: string;
}
interface UploadedFile { id: number; remote_file_id: string | null; title: string; status: string; }

const props = defineProps<{ projects: Project[]; contracts: Contract[]; defaultProjectId?: string; }>();

const { pendingCount, syncStatus, isOnline, triggerSync } = useOfflineQueue();
const { getActiveProjectId } = useActiveProject();
const { getActiveContractId, setActiveContract } = useActiveContract();

// Use Track AI module from config-provided project ID
const defaultProject = props.defaultProjectId
    ? props.projects.find(p => p.external_id === props.defaultProjectId)
    : null;
const selectedProjectId = ref(defaultProject?.external_id || props.projects[0]?.external_id || '');
const selectedProject = computed(() => props.projects.find(p => p.external_id === selectedProjectId.value));
const selectedContractId = ref(getActiveContractId(props.contracts));
const selectedContract = computed(() => props.contracts.find(c => c.id === selectedContractId.value));

// Persist contract selection
watch(selectedContractId, (id) => { if (id) setActiveContract(id); });
const selectedMilestone = ref('');
const remarks = ref('');
const isSubmitting = ref(false);
const isPolling = ref(false);
const message = ref<{ type: 'success' | 'error'; text: string } | null>(null);
const submitStep = ref<string | null>(null);
const previousFiles = ref<UploadedFile[]>([]);
const currentFiles = ref<UploadedFile[]>([]);
const isUploadingPrevious = ref(false);
const isUploadingCurrent = ref(false);
const reports = ref<ProgressReport[]>([]);
const isLoadingReports = ref(false);

watch(() => props.contracts, (c) => {
    if (c.length > 0 && !selectedContractId.value) {
        selectedContractId.value = getActiveContractId(c);
    }
}, { immediate: true });

watch(selectedContractId, () => {
    selectedMilestone.value = '';
    if (selectedContract.value?.milestones.length) selectedMilestone.value = selectedContract.value.milestones[0];
});

watch(selectedProject, () => loadReports(), { immediate: true });

async function loadReports() {
    if (!selectedProject.value) { reports.value = []; return; }
    isLoadingReports.value = true;
    try {
        const r = await axios.get(`/api/projects/${selectedProject.value.id}/progress-reports`);
        if (r.data.success) reports.value = r.data.data;
    } catch { /* ignore */ } finally { isLoadingReports.value = false; }
}

async function handleFileUpload(event: Event, type: 'previous' | 'current') {
    const target = event.target as HTMLInputElement;
    if (!target.files?.length || !selectedProject.value) return;
    const files = Array.from(target.files);
    const isUploading = type === 'previous' ? isUploadingPrevious : isUploadingCurrent;
    const fileList = type === 'previous' ? previousFiles : currentFiles;
    const docType = type === 'previous' ? 'previous_progress' : 'current_progress';
    isUploading.value = true;
    for (const file of files) {
        try {
            const cr = await axios.post(`/api/projects/${selectedProject.value.id}/uploads`, {
                contract_id: selectedContractId.value || selectedProjectId.value,
                client_request_id: crypto.randomUUID(),
                title: file.name, document_type: docType, tags: ['progress', docType],
            });
            if (!cr.data.success) continue;
            const fd = new FormData(); fd.append('file', file);
            const ur = await axios.post(
                `/api/projects/${selectedProject.value.id}/uploads/${cr.data.upload.id}/file`,
                fd, { headers: { 'Content-Type': 'multipart/form-data' } }
            );
            if (ur.data.success && ur.data.upload) {
                fileList.value.push({
                    id: ur.data.upload.id, remote_file_id: ur.data.upload.remote_file_id,
                    title: file.name, status: ur.data.upload.status,
                });
            }
        } catch (err) { console.error(`Failed to upload ${file.name}`, err); }
    }
    isUploading.value = false;
    target.value = '';
}

function removeFile(type: 'previous' | 'current', index: number) {
    (type === 'previous' ? previousFiles : currentFiles).value.splice(index, 1);
}

async function handleSubmit() {
    if (!selectedProject.value || isSubmitting.value) return;
    isSubmitting.value = true; message.value = null;
    const prevIds = previousFiles.value.filter(f => f.remote_file_id).map(f => f.remote_file_id!);
    const currIds = currentFiles.value.filter(f => f.remote_file_id).map(f => f.remote_file_id!);
    try {
        submitStep.value = 'Creating progress report...';
        const r = await axios.post(`/api/projects/${selectedProject.value.id}/progress-reports`, {
            contract_id: selectedContractId.value || null,
            current_milestone: selectedMilestone.value || null,
            remarks: remarks.value || null,
            previous_progress_file_ids: prevIds, current_progress_file_ids: currIds,
        });
        if (!r.data.success) throw new Error(r.data.message);
        const report = r.data.report;
        if (report.saras_process_id && (prevIds.length || currIds.length)) {
            submitStep.value = 'Attaching stage files...';
            await axios.post(`/api/progress-reports/${report.id}/stage-files`);
        }
        if (report.saras_process_id) {
            submitStep.value = 'Triggering AI evaluation...';
            await axios.post(`/api/progress-reports/${report.id}/workflow`);
        }
        message.value = { type: 'success', text: 'Progress report submitted and AI evaluation started.' };
        remarks.value = ''; previousFiles.value = []; currentFiles.value = [];
        await loadReports();
    } catch (error: any) {
        message.value = { type: 'error', text: error.response?.data?.message || error.message || 'Failed to submit.' };
    } finally { isSubmitting.value = false; submitStep.value = null; }
}

async function handlePollStatus(report: ProgressReport) {
    isPolling.value = true;
    try { await axios.get(`/api/progress-reports/${report.id}/workflow`); await loadReports(); }
    catch { /* ignore */ } finally { isPolling.value = false; }
}

const statusConfig = (s: string) => ({
    draft: { label: 'Draft', variant: 'secondary' as const },
    submitted: { label: 'Submitted', variant: 'outline' as const },
    processing: { label: 'AI Processing', variant: 'default' as const },
    evaluated: { label: 'Evaluated', variant: 'default' as const },
    failed: { label: 'Failed', variant: 'destructive' as const },
}[s] || { label: s, variant: 'secondary' as const });

const canSubmit = computed(() => selectedProject.value && !isSubmitting.value && (previousFiles.value.length > 0 || currentFiles.value.length > 0));
</script>

<template>
    <div class="min-h-screen bg-background pb-20">
        <Head title="Project Progress" />
        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2">
                    <ClipboardCheck class="h-6 w-6 text-primary" />
                    <h1 class="text-lg font-semibold">Project Progress</h1>
                </div>
                <SyncBadge :pending-count="pendingCount" :is-syncing="syncStatus.isSyncing" :is-online="isOnline" @sync="triggerSync" />
            </div>
        </header>

        <main class="p-4 space-y-4">
            <Alert v-if="message" :variant="message.type === 'error' ? 'destructive' : 'default'">
                <AlertCircle class="h-4 w-4" />
                <AlertDescription>{{ message.text }}</AlertDescription>
            </Alert>

            <!-- New Progress Report -->
            <Card>
                <CardHeader>
                    <CardTitle>New Progress Report</CardTitle>
                    <CardDescription>Upload photos and submit for AI evaluation</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Project auto-selected (Track AI module) -->
                    <div v-if="selectedProject" class="text-sm text-muted-foreground">
                        Module: <span class="font-medium text-foreground">{{ selectedProject.name }}</span>
                    </div>

                    <!-- Contract -->
                    <ContractSelector
                        v-if="contracts.length > 0"
                        v-model="selectedContractId"
                        :contracts="contracts"
                        label="Contract"
                    />

                    <!-- Milestone -->
                    <div class="grid gap-2" v-if="selectedContract?.milestones.length">
                        <Label>Milestone</Label>
                        <Select v-model="selectedMilestone">
                            <SelectTrigger><SelectValue placeholder="Select milestone" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="m in selectedContract.milestones" :key="m" :value="m">{{ m }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <Separator />

                    <!-- Previous Progress Photos -->
                    <div class="space-y-2">
                        <Label class="flex items-center gap-2"><Camera class="h-4 w-4" /> Previous Progress Photos</Label>
                        <p class="text-xs text-muted-foreground">Baseline or previous condition images</p>
                        <div v-if="previousFiles.length" class="space-y-1">
                            <div v-for="(file, i) in previousFiles" :key="file.id" class="flex items-center justify-between text-sm bg-muted/50 rounded px-3 py-1.5">
                                <span class="truncate">{{ file.title }}</span>
                                <div class="flex items-center gap-2">
                                    <Badge variant="outline" class="text-xs">{{ file.remote_file_id ? 'uploaded' : file.status }}</Badge>
                                    <button @click="removeFile('previous', i)" class="text-muted-foreground hover:text-destructive">×</button>
                                </div>
                            </div>
                        </div>
                        <label class="cursor-pointer">
                            <Button variant="outline" size="sm" as="span" :disabled="isUploadingPrevious">
                                <Loader2 v-if="isUploadingPrevious" class="mr-1 h-3 w-3 animate-spin" /><Upload v-else class="mr-1 h-3 w-3" /> Add Photos
                            </Button>
                            <input type="file" accept="image/*" multiple capture="environment" class="hidden" @change="(e) => handleFileUpload(e, 'previous')" />
                        </label>
                    </div>

                    <!-- Current Progress Photos -->
                    <div class="space-y-2">
                        <Label class="flex items-center gap-2"><Camera class="h-4 w-4" /> Current Progress Photos</Label>
                        <p class="text-xs text-muted-foreground">Current construction progress images</p>
                        <div v-if="currentFiles.length" class="space-y-1">
                            <div v-for="(file, i) in currentFiles" :key="file.id" class="flex items-center justify-between text-sm bg-muted/50 rounded px-3 py-1.5">
                                <span class="truncate">{{ file.title }}</span>
                                <div class="flex items-center gap-2">
                                    <Badge variant="outline" class="text-xs">{{ file.remote_file_id ? 'uploaded' : file.status }}</Badge>
                                    <button @click="removeFile('current', i)" class="text-muted-foreground hover:text-destructive">×</button>
                                </div>
                            </div>
                        </div>
                        <label class="cursor-pointer">
                            <Button variant="outline" size="sm" as="span" :disabled="isUploadingCurrent">
                                <Loader2 v-if="isUploadingCurrent" class="mr-1 h-3 w-3 animate-spin" /><Upload v-else class="mr-1 h-3 w-3" /> Add Photos
                            </Button>
                            <input type="file" accept="image/*" multiple capture="environment" class="hidden" @change="(e) => handleFileUpload(e, 'current')" />
                        </label>
                    </div>

                    <Separator />

                    <div class="grid gap-2">
                        <Label for="remarks">Engineer's Remarks</Label>
                        <Textarea id="remarks" v-model="remarks" placeholder="Describe current progress, observations, issues..." rows="3" />
                    </div>

                    <Button @click="handleSubmit" :disabled="!canSubmit" class="w-full">
                        <Loader2 v-if="isSubmitting" class="mr-2 h-4 w-4 animate-spin" />
                        <Sparkles v-else class="mr-2 h-4 w-4" />
                        {{ submitStep || 'Submit & Run AI Evaluation' }}
                    </Button>
                    <p v-if="!canSubmit && !isSubmitting" class="text-xs text-center text-muted-foreground">Upload at least one photo to submit</p>
                </CardContent>
            </Card>

            <!-- Reports List -->
            <Card v-if="selectedProject">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <CardTitle>Progress Reports</CardTitle>
                        <Loader2 v-if="isLoadingReports" class="h-4 w-4 animate-spin text-muted-foreground" />
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="reports.length === 0" class="text-center py-8 text-muted-foreground">No progress reports yet.</div>
                    <div v-else class="space-y-3">
                        <div v-for="report in reports" :key="report.id" class="rounded-lg border p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-sm">{{ report.current_milestone || 'No milestone' }}</span>
                                        <Badge :variant="statusConfig(report.progress_status).variant">{{ statusConfig(report.progress_status).label }}</Badge>
                                    </div>
                                    <p class="text-xs text-muted-foreground">{{ new Date(report.created_at).toLocaleString() }}</p>
                                </div>
                            </div>
                            <p v-if="report.remarks" class="text-sm text-muted-foreground">{{ report.remarks }}</p>
                            <div class="flex gap-4 text-xs text-muted-foreground">
                                <span v-if="report.previous_progress_file_ids?.length">📷 {{ report.previous_progress_file_ids.length }} previous</span>
                                <span v-if="report.current_progress_file_ids?.length">📷 {{ report.current_progress_file_ids.length }} current</span>
                            </div>

                            <!-- Certificate -->
                            <div v-if="report.progress_status === 'evaluated' || report.certificate_file_id" class="flex items-center gap-2 p-2 rounded bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800">
                                <Award class="h-4 w-4 text-green-600" />
                                <span class="text-sm text-green-700 dark:text-green-300">{{ report.certificate_file_id ? 'Certificate available' : 'Evaluation complete' }}</span>
                                <Badge v-if="report.certificate_file_id" variant="outline" class="text-xs ml-auto">{{ report.certificate_file_id.substring(0, 8) }}...</Badge>
                            </div>

                            <div class="flex gap-2">
                                <Button v-if="report.progress_status === 'processing'" size="sm" variant="outline" @click="handlePollStatus(report)" :disabled="isPolling">
                                    <Loader2 v-if="isPolling" class="mr-1 h-3 w-3 animate-spin" /><Clock v-else class="mr-1 h-3 w-3" /> Check Status
                                </Button>
                                <Badge v-if="report.completion_status" variant="outline" class="text-xs">{{ report.completion_status }}</Badge>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </main>
        <AppBottomNav />
    </div>
</template>
