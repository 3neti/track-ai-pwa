<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import { TrendingUp, Camera, Check, AlertCircle, Sparkles, Loader2, Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import SyncBadge from '@/components/app/SyncBadge.vue';
import ProjectSelector from '@/components/app/ProjectSelector.vue';
import UploadPreviewDrawer from '@/components/app/UploadPreviewDrawer.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { useGeolocation } from '@/composables/useGeolocation';
import { useActiveProject } from '@/composables/useActiveProject';
import type { Upload } from '@/composables/useProjectUploads';
import axios from 'axios';

interface Project {
    id: number;
    external_id: string;
    name: string;
    description: string | null;
}

interface ChecklistItem {
    type: string;
    label: string;
    upload: Upload | null;
    isUploading: boolean;
}

const props = defineProps<{
    projects: Project[];
}>();

const { pendingCount, syncStatus, isOnline, triggerSync, queueRequest } = useOfflineQueue();
const { state: geoState, getCurrentPosition } = useGeolocation();
const { getActiveProjectId } = useActiveProject();

const selectedProjectId = ref(getActiveProjectId(props.projects));
const selectedProject = computed(() =>
    props.projects.find(p => p.external_id === selectedProjectId.value)
);
const remarks = ref('');
const isSubmitting = ref(false);
const isLoadingPhotos = ref(false);
const message = ref<{ type: 'success' | 'error'; text: string } | null>(null);
const aiStatus = ref<string | null>(null);

const checklistItems = ref<ChecklistItem[]>([
    { type: 'progress_top_view', label: 'Top View', upload: null, isUploading: false },
    { type: 'progress_left_side', label: 'Left Side View', upload: null, isUploading: false },
    { type: 'progress_right_side', label: 'Right Side View', upload: null, isUploading: false },
    { type: 'progress_front_view', label: 'Front View', upload: null, isUploading: false },
    { type: 'progress_detail', label: 'Detail Shot', upload: null, isUploading: false },
]);

const completedCount = computed(() => checklistItems.value.filter(item => item.upload !== null).length);

// Preview drawer state
const previewDrawerOpen = ref(false);
const previewingIndex = ref<number | null>(null);
const previewingItem = computed(() => 
    previewingIndex.value !== null ? checklistItems.value[previewingIndex.value] : null
);

const canSubmit = computed(() => {
    return selectedProject.value && completedCount.value > 0 && !isSubmitting.value;
});

// Load existing progress photos for selected project
async function loadProgressPhotos() {
    if (!selectedProject.value) {
        // Clear all uploads when no project selected
        checklistItems.value.forEach(item => {
            item.upload = null;
        });
        return;
    }

    isLoadingPhotos.value = true;
    try {
        const response = await axios.get(`/api/projects/${selectedProject.value.id}/uploads`, {
            params: { 
                status: 'uploaded',
                per_page: 50,
            }
        });

        if (response.data.success) {
            // Match uploads to checklist items by document_type
            checklistItems.value.forEach(item => {
                const matchingUpload = response.data.data.find(
                    (u: Upload) => u.document_type === item.type
                );
                item.upload = matchingUpload || null;
            });
        }
    } catch (err) {
        console.error('Failed to load progress photos', err);
    } finally {
        isLoadingPhotos.value = false;
    }
}

// Watch for project changes
watch(selectedProject, () => {
    loadProgressPhotos();
}, { immediate: true });

// Handle photo capture - upload immediately
const handlePhotoCapture = async (index: number, event: Event) => {
    const target = event.target as HTMLInputElement;
    if (!target.files || !target.files[0] || !selectedProject.value) return;

    const file = target.files[0];
    const item = checklistItems.value[index];
    item.isUploading = true;
    message.value = null;

    try {
        // Generate a client_request_id for idempotency
        const clientRequestId = crypto.randomUUID();

        // Step 1: Create Upload record
        const createResponse = await axios.post(`/api/projects/${selectedProject.value.id}/uploads`, {
            contract_id: selectedProjectId.value,
            client_request_id: clientRequestId,
            title: `${item.label} - ${new Date().toLocaleDateString()}`,
            document_type: item.type,
            tags: ['progress', item.type],
            remarks: null,
        });

        if (!createResponse.data.success) {
            throw new Error(createResponse.data.message);
        }

        const uploadId = createResponse.data.upload.id;

        // Step 2: Upload the file
        const formData = new FormData();
        formData.append('file', file);

        const uploadResponse = await axios.post(
            `/api/projects/${selectedProject.value.id}/uploads/${uploadId}/file`,
            formData,
            { headers: { 'Content-Type': 'multipart/form-data' } }
        );

        if (uploadResponse.data.success) {
            // Delete old upload if exists
            if (item.upload) {
                await deleteUpload(item.upload, false);
            }
            item.upload = uploadResponse.data.upload;
            
            // Open preview after capture
            previewingIndex.value = index;
            previewDrawerOpen.value = true;
        } else {
            throw new Error(uploadResponse.data.message);
        }
    } catch (err) {
        console.error('Failed to upload photo', err);
        message.value = { type: 'error', text: 'Failed to upload photo. Please try again.' };
    } finally {
        item.isUploading = false;
        // Reset input so same file can be selected again
        target.value = '';
    }
};

const handleThumbnailClick = (index: number) => {
    if (checklistItems.value[index].upload) {
        previewingIndex.value = index;
        previewDrawerOpen.value = true;
    }
};

// Handle delete from preview
async function deleteUpload(upload: Upload, showMessage = true) {
    if (!selectedProject.value) return;

    try {
        await axios.delete(`/api/projects/${selectedProject.value.id}/uploads/${upload.id}`);
        
        // Clear from checklist item
        const item = checklistItems.value.find(i => i.upload?.id === upload.id);
        if (item) {
            item.upload = null;
        }
        
        previewDrawerOpen.value = false;
        if (showMessage) {
            message.value = { type: 'success', text: 'Photo deleted.' };
        }
    } catch (err) {
        console.error('Failed to delete upload', err);
        if (showMessage) {
            message.value = { type: 'error', text: 'Failed to delete photo.' };
        }
    }
}

const handlePreviewDelete = (upload: Upload) => {
    deleteUpload(upload);
};

// Handle retake from preview - trigger file input
const handleRetake = () => {
    if (previewingIndex.value !== null) {
        previewDrawerOpen.value = false;
        // Small delay to let drawer close before opening file picker
        setTimeout(() => {
            const fileInput = document.querySelector(`#photo-input-${previewingIndex.value}`) as HTMLInputElement;
            fileInput?.click();
        }, 100);
    }
};

const handleSubmit = async () => {
    if (!canSubmit.value) return;

    isSubmitting.value = true;
    message.value = null;
    aiStatus.value = null;

    await getCurrentPosition();

    const payload = {
        contract_id: selectedProjectId.value,
        checklist_items: checklistItems.value
            .filter(item => item.upload !== null)
            .map(item => ({
                type: item.type,
                upload_id: item.upload?.id,
                completed: true,
                notes: null,
            })),
        remarks: remarks.value || null,
        latitude: geoState.value.latitude || 0,
        longitude: geoState.value.longitude || 0,
    };

    const idempotencyKey = `progress_${selectedProjectId.value}_${Date.now()}`;

    try {
        if (isOnline.value) {
            const response = await axios.post('/api/progress/submit', payload);

            if (response.data.success) {
                message.value = { type: 'success', text: 'Progress submitted successfully!' };

                // Trigger AI analysis
                aiStatus.value = 'Analyzing...';
                const aiResponse = await axios.post('/api/progress/ai', {
                    contract_id: selectedProjectId.value,
                    entry_id: response.data.entry_id,
                });

                if (aiResponse.data.success) {
                    aiStatus.value = `AI Analysis: ${aiResponse.data.status}`;
                }

                // Don't reset - photos persist for future reference
                remarks.value = '';
            } else {
                throw new Error(response.data.message);
            }
        } else {
            await queueRequest('/api/progress/submit', 'POST', payload, idempotencyKey);
            message.value = { type: 'success', text: 'Progress saved offline. Will sync when online.' };
        }
    } catch (error) {
        if (isOnline.value) {
            await queueRequest('/api/progress/submit', 'POST', payload, idempotencyKey);
            message.value = { type: 'success', text: 'Request failed but saved offline.' };
        } else {
            message.value = { type: 'error', text: 'Failed to submit progress.' };
        }
    } finally {
        isSubmitting.value = false;
    }
};

getCurrentPosition();
</script>

<template>
    <div class="min-h-screen bg-background pb-20">
        <Head title="Progress" />

        <!-- Header -->
        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2">
                    <TrendingUp class="h-6 w-6 text-primary" />
                    <h1 class="text-lg font-semibold">Progress Update</h1>
                </div>
                <SyncBadge
                    :pending-count="pendingCount"
                    :is-syncing="syncStatus.isSyncing"
                    :is-online="isOnline"
                    @sync="triggerSync"
                />
            </div>
        </header>

        <!-- Content -->
        <main class="p-4 space-y-4">
            <!-- Message -->
            <Alert v-if="message" :variant="message.type === 'error' ? 'destructive' : 'default'">
                <AlertCircle class="h-4 w-4" />
                <AlertDescription>{{ message.text }}</AlertDescription>
            </Alert>

            <!-- AI Status -->
            <Alert v-if="aiStatus" class="border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
                <Sparkles class="h-4 w-4 text-blue-600" />
                <AlertDescription class="text-blue-800 dark:text-blue-200">{{ aiStatus }}</AlertDescription>
            </Alert>

            <Card>
                <CardHeader>
                    <CardTitle>Submit Progress</CardTitle>
                    <CardDescription>
                        Capture photos from required angles for AI analysis
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Project Selection -->
                    <ProjectSelector
                        v-model="selectedProjectId"
                        :projects="projects"
                        label="Project"
                    />

                    <!-- Photo Checklist -->
                    <div class="grid gap-2">
                        <div class="flex items-center justify-between">
                            <Label>Photo Checklist ({{ completedCount }}/{{ checklistItems.length }})</Label>
                            <Loader2 v-if="isLoadingPhotos" class="h-4 w-4 animate-spin text-muted-foreground" />
                        </div>
                        <div class="space-y-2">
                            <div
                                v-for="(item, index) in checklistItems"
                                :key="item.type"
                                class="flex items-center gap-3 rounded-lg border p-3 transition-colors"
                                :class="[
                                    item.upload ? 'border-green-500 bg-green-50 dark:bg-green-950 cursor-pointer hover:bg-green-100 dark:hover:bg-green-900' : '',
                                ]"
                                @click="item.upload ? handleThumbnailClick(index) : null"
                            >
                                <!-- Thumbnail or icon -->
                                <div
                                    v-if="item.upload"
                                    class="h-12 w-12 shrink-0 overflow-hidden rounded-lg border bg-muted"
                                >
                                    <img
                                        :src="item.upload.preview_url || `/api/uploads/${item.upload.id}/preview`"
                                        :alt="item.label"
                                        class="h-full w-full object-cover"
                                    />
                                </div>
                                <div
                                    v-else-if="item.isUploading"
                                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-muted"
                                >
                                    <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
                                </div>
                                <div
                                    v-else
                                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-muted"
                                >
                                    <Camera class="h-5 w-5 text-muted-foreground" />
                                </div>

                                <!-- Label -->
                                <div class="flex items-center gap-2 flex-1 min-w-0">
                                    <Check v-if="item.upload" class="h-4 w-4 shrink-0 text-green-600" />
                                    <span class="text-sm truncate">{{ item.label }}</span>
                                </div>

                                <!-- Capture button (only when no photo and not uploading) -->
                                <label v-if="!item.upload && !item.isUploading && selectedProject" class="cursor-pointer shrink-0" @click.stop>
                                    <Button variant="outline" size="sm" as="span">
                                        Capture
                                    </Button>
                                    <input
                                        :id="`photo-input-${index}`"
                                        type="file"
                                        accept="image/*"
                                        capture="environment"
                                        class="hidden"
                                        @change="(e) => handlePhotoCapture(index, e)"
                                    />
                                </label>

                                <!-- Hidden input for retake (used by preview drawer) -->
                                <input
                                    v-if="item.upload"
                                    :id="`photo-input-${index}`"
                                    type="file"
                                    accept="image/*"
                                    capture="environment"
                                    class="hidden"
                                    @change="(e) => handlePhotoCapture(index, e)"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div class="grid gap-2">
                        <Label for="remarks">Engineer's Comments (optional)</Label>
                        <Textarea
                            id="remarks"
                            v-model="remarks"
                            placeholder="Add your observations about the progress..."
                            rows="3"
                        />
                    </div>

                    <!-- Submit -->
                    <Button
                        @click="handleSubmit"
                        :disabled="!canSubmit"
                        class="w-full"
                    >
                        <Sparkles class="mr-2 h-4 w-4" />
                        Submit for AI Analysis
                    </Button>
                </CardContent>
            </Card>
        </main>

        <AppBottomNav />

        <!-- Upload Preview Drawer -->
        <UploadPreviewDrawer
            v-model:open="previewDrawerOpen"
            :upload="previewingItem?.upload ?? null"
            @delete="handlePreviewDelete"
            @edit="handleRetake"
        />
    </div>
</template>
