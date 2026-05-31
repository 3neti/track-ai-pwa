<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { ClipboardCheck, Sparkles, Loader2, AlertCircle, CheckCircle2, XCircle, Clock } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import SyncBadge from '@/components/app/SyncBadge.vue';
import ProjectSelector from '@/components/app/ProjectSelector.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { useActiveProject } from '@/composables/useActiveProject';
import axios from 'axios';

interface Project {
    id: number;
    external_id: string;
    name: string;
}

interface ProgressReport {
    id: number;
    progress_status: string;
    current_milestone: string | null;
    remarks: string | null;
    saras_process_id: string | null;
    saras_workflow_run_id: string | null;
    completion_status: string | null;
    certificate_file_id: string | null;
    created_at: string;
}

const props = defineProps<{
    projects: Project[];
}>();

const { pendingCount, syncStatus, isOnline, triggerSync } = useOfflineQueue();
const { getActiveProjectId } = useActiveProject();

const selectedProjectId = ref(getActiveProjectId(props.projects));
const selectedProject = computed(() =>
    props.projects.find(p => p.external_id === selectedProjectId.value)
);

const currentMilestone = ref('');
const remarks = ref('');
const isSubmitting = ref(false);
const isTriggering = ref(false);
const isPolling = ref(false);
const message = ref<{ type: 'success' | 'error'; text: string } | null>(null);

const reports = ref<ProgressReport[]>([]);
const isLoadingReports = ref(false);

async function loadReports() {
    if (!selectedProject.value) {
        reports.value = [];
        return;
    }

    isLoadingReports.value = true;
    try {
        const response = await axios.get(`/api/projects/${selectedProject.value.id}/progress-reports`);
        if (response.data.success) {
            reports.value = response.data.data;
        }
    } catch (err) {
        console.error('Failed to load progress reports', err);
    } finally {
        isLoadingReports.value = false;
    }
}

watch(selectedProject, () => {
    loadReports();
}, { immediate: true });

const handleSubmit = async () => {
    if (!selectedProject.value || isSubmitting.value) return;

    isSubmitting.value = true;
    message.value = null;

    try {
        const response = await axios.post(`/api/projects/${selectedProject.value.id}/progress-reports`, {
            current_milestone: currentMilestone.value || null,
            remarks: remarks.value || null,
            previous_progress_file_ids: [],
            current_progress_file_ids: [],
        });

        if (response.data.success) {
            message.value = { type: 'success', text: response.data.message };
            remarks.value = '';
            currentMilestone.value = '';
            await loadReports();
        } else {
            throw new Error(response.data.message);
        }
    } catch (error: any) {
        message.value = { type: 'error', text: error.response?.data?.message || 'Failed to submit progress report.' };
    } finally {
        isSubmitting.value = false;
    }
};

const handleTriggerWorkflow = async (report: ProgressReport) => {
    isTriggering.value = true;
    message.value = null;

    try {
        const response = await axios.post(`/api/progress-reports/${report.id}/workflow`);
        if (response.data.success) {
            message.value = { type: 'success', text: response.data.message };
            await loadReports();
        } else {
            throw new Error(response.data.message);
        }
    } catch (error: any) {
        message.value = { type: 'error', text: error.response?.data?.message || 'Failed to trigger workflow.' };
    } finally {
        isTriggering.value = false;
    }
};

const handlePollStatus = async (report: ProgressReport) => {
    isPolling.value = true;
    try {
        const response = await axios.get(`/api/progress-reports/${report.id}/workflow`);
        if (response.data.success) {
            await loadReports();
        }
    } catch (err) {
        console.error('Failed to poll status', err);
    } finally {
        isPolling.value = false;
    }
};

const statusConfig = computed(() => (status: string) => {
    switch (status) {
        case 'draft': return { label: 'Draft', variant: 'secondary' as const, icon: Clock };
        case 'submitted': return { label: 'Submitted', variant: 'outline' as const, icon: CheckCircle2 };
        case 'processing': return { label: 'Processing', variant: 'default' as const, icon: Loader2 };
        case 'evaluated': return { label: 'Evaluated', variant: 'default' as const, icon: CheckCircle2 };
        case 'failed': return { label: 'Failed', variant: 'destructive' as const, icon: XCircle };
        default: return { label: status, variant: 'secondary' as const, icon: Clock };
    }
});
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
                <SyncBadge
                    :pending-count="pendingCount"
                    :is-syncing="syncStatus.isSyncing"
                    :is-online="isOnline"
                    @sync="triggerSync"
                />
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
                    <CardDescription>Submit a progress update for AI evaluation</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <ProjectSelector
                        v-model="selectedProjectId"
                        :projects="projects"
                        label="Project"
                    />

                    <div class="grid gap-2">
                        <Label for="milestone">Current Milestone</Label>
                        <Input
                            id="milestone"
                            v-model="currentMilestone"
                            placeholder="e.g., Foundation, Framing, Roofing..."
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="remarks">Engineer's Remarks</Label>
                        <Textarea
                            id="remarks"
                            v-model="remarks"
                            placeholder="Describe the current progress, observations, issues..."
                            rows="4"
                        />
                    </div>

                    <Button
                        @click="handleSubmit"
                        :disabled="!selectedProject || isSubmitting"
                        class="w-full"
                    >
                        <Loader2 v-if="isSubmitting" class="mr-2 h-4 w-4 animate-spin" />
                        <ClipboardCheck v-else class="mr-2 h-4 w-4" />
                        Submit Progress Report
                    </Button>
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
                    <div v-if="reports.length === 0" class="text-center py-8 text-muted-foreground">
                        No progress reports yet.
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="report in reports"
                            :key="report.id"
                            class="rounded-lg border p-4 space-y-3"
                        >
                            <div class="flex items-center justify-between">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-sm">
                                            {{ report.current_milestone || 'No milestone' }}
                                        </span>
                                        <Badge :variant="statusConfig(report.progress_status).variant">
                                            {{ statusConfig(report.progress_status).label }}
                                        </Badge>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        {{ new Date(report.created_at).toLocaleString() }}
                                    </p>
                                </div>
                            </div>

                            <p v-if="report.remarks" class="text-sm text-muted-foreground">
                                {{ report.remarks }}
                            </p>

                            <!-- Actions -->
                            <div class="flex gap-2">
                                <Button
                                    v-if="report.progress_status === 'submitted' && report.saras_process_id"
                                    size="sm"
                                    @click="handleTriggerWorkflow(report)"
                                    :disabled="isTriggering"
                                >
                                    <Sparkles class="mr-1 h-3 w-3" />
                                    Run AI Evaluation
                                </Button>
                                <Button
                                    v-if="report.progress_status === 'processing'"
                                    size="sm"
                                    variant="outline"
                                    @click="handlePollStatus(report)"
                                    :disabled="isPolling"
                                >
                                    <Loader2 v-if="isPolling" class="mr-1 h-3 w-3 animate-spin" />
                                    <Clock v-else class="mr-1 h-3 w-3" />
                                    Check Status
                                </Button>
                                <Badge v-if="report.completion_status" variant="outline" class="text-xs">
                                    {{ report.completion_status }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </main>

        <AppBottomNav />
    </div>
</template>
