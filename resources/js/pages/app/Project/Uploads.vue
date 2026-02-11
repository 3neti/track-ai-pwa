<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import { Upload, Search, Filter, Loader2, FolderOpen, Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import SyncBadge from '@/components/app/SyncBadge.vue';
import ProjectSelector from '@/components/app/ProjectSelector.vue';
import UploadListItem from '@/components/app/UploadListItem.vue';
import UploadEditSheet from '@/components/app/UploadEditSheet.vue';
import UploadDeleteDialog from '@/components/app/UploadDeleteDialog.vue';
import UploadPreviewDrawer from '@/components/app/UploadPreviewDrawer.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { useActiveProject } from '@/composables/useActiveProject';
import type { Upload as UploadType } from '@/composables/useProjectUploads';
import axios from 'axios';

interface Project {
    id: number;
    external_id: string;
    name: string;
    description: string | null;
    status?: string;
}

const props = defineProps<{
    projects: Project[];
}>();

const { pendingCount, syncStatus, isOnline, triggerSync } = useOfflineQueue();
const { getActiveProjectId } = useActiveProject();

// Selected project
const selectedProjectId = ref<string>(getActiveProjectId(props.projects));
const selectedProject = computed(() =>
    props.projects.find(p => p.external_id === selectedProjectId.value)
);

// Project uploads state - managed separately for reactivity
const uploads = ref<UploadType[]>([]);
const isLoading = ref(false);
const uploadsError = ref<string | null>(null);
const pagination = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
const hasMore = computed(() => pagination.value.current_page < pagination.value.last_page);

// Filters
const statusFilter = ref<string>('all');
const searchQuery = ref('');

// Fetch uploads for selected project
async function fetchUploads(page = 1, reset = true) {
    if (!selectedProject.value) return;

    isLoading.value = true;
    uploadsError.value = null;

    try {
        const params: Record<string, string | number> = { page };
        if (statusFilter.value && statusFilter.value !== 'all') params.status = statusFilter.value;
        if (searchQuery.value) params.q = searchQuery.value;

        const response = await axios.get(`/api/projects/${selectedProject.value.id}/uploads`, { params });

        if (response.data.success) {
            if (reset) {
                uploads.value = response.data.data;
            } else {
                uploads.value = [...uploads.value, ...response.data.data];
            }
            pagination.value = response.data.meta;
        }
    } catch (err) {
        uploadsError.value = 'Failed to fetch uploads';
        console.error(err);
    } finally {
        isLoading.value = false;
    }
}

// Watch for project changes
watch(selectedProject, (project) => {
    if (project) {
        fetchUploads();
    } else {
        uploads.value = [];
        pagination.value = { current_page: 1, last_page: 1, per_page: 20, total: 0 };
    }
}, { immediate: true });

// Load more
function loadMore() {
    fetchUploads(pagination.value.current_page + 1, false);
}

// Apply filters with debounce
let searchTimeout: number;
watch([statusFilter, searchQuery], () => {
    clearTimeout(searchTimeout);
    searchTimeout = window.setTimeout(() => {
        if (selectedProject.value) {
            fetchUploads();
        }
    }, 300);
});

// Edit sheet
const editSheetOpen = ref(false);
const editingUpload = ref<UploadType | null>(null);

function handleEdit(upload: UploadType) {
    editingUpload.value = upload;
    editSheetOpen.value = true;
}

async function handleSaveEdit(data: { title: string; document_type: string; tags: string[]; remarks: string }) {
    if (!editingUpload.value || !selectedProject.value) return;

    try {
        const response = await axios.patch(
            `/api/projects/${selectedProject.value.id}/uploads/${editingUpload.value.id}`,
            data
        );
        if (response.data.success) {
            const index = uploads.value.findIndex(u => u.id === editingUpload.value!.id);
            if (index !== -1) {
                uploads.value[index] = response.data.upload;
            }
            editSheetOpen.value = false;
            editingUpload.value = null;
        }
    } catch (err) {
        console.error('Failed to update upload', err);
    }
}

// Delete dialog
const deleteDialogOpen = ref(false);
const deletingUpload = ref<UploadType | null>(null);

function handleDelete(upload: UploadType) {
    deletingUpload.value = upload;
    deleteDialogOpen.value = true;
}

async function handleConfirmDelete(reason: string | undefined) {
    if (!deletingUpload.value || !selectedProject.value) return;

    try {
        const response = await axios.delete(
            `/api/projects/${selectedProject.value.id}/uploads/${deletingUpload.value.id}`,
            { data: { reason } }
        );
        if (response.data.success) {
            uploads.value = uploads.value.filter(u => u.id !== deletingUpload.value!.id);
            deleteDialogOpen.value = false;
            deletingUpload.value = null;
        }
    } catch (err) {
        console.error('Failed to delete upload', err);
    }
}

// Retry
async function handleRetry(upload: UploadType) {
    if (!selectedProject.value) return;

    try {
        const response = await axios.post(
            `/api/projects/${selectedProject.value.id}/uploads/${upload.id}/retry`
        );
        if (response.data.success) {
            const index = uploads.value.findIndex(u => u.id === upload.id);
            if (index !== -1) {
                uploads.value[index] = response.data.upload;
            }
            // Close preview drawer if open and this upload was being previewed
            if (previewingUpload.value?.id === upload.id) {
                previewingUpload.value = response.data.upload;
            }
        }
    } catch (err) {
        console.error('Failed to retry upload', err);
    }
}

// Preview drawer
const previewDrawerOpen = ref(false);
const previewingUpload = ref<UploadType | null>(null);
const pendingPreviewId = ref<number | null>(null);

function handleView(upload: UploadType) {
    previewingUpload.value = upload;
    previewDrawerOpen.value = true;
    // Clear preview param from URL without navigation
    if (window.location.search.includes('preview=')) {
        window.history.replaceState({}, '', window.location.pathname);
    }
}

// Check for preview query param on mount
onMounted(() => {
    const urlParams = new URLSearchParams(window.location.search);
    const previewId = urlParams.get('preview');
    if (previewId) {
        pendingPreviewId.value = parseInt(previewId, 10);
    }
});

// Watch for uploads to load, then open preview if pending
watch(uploads, (newUploads) => {
    if (pendingPreviewId.value && newUploads.length > 0) {
        const upload = newUploads.find(u => u.id === pendingPreviewId.value);
        if (upload) {
            handleView(upload);
            pendingPreviewId.value = null;
        }
    }
});

// Handle edit from preview drawer
function handlePreviewEdit(upload: UploadType) {
    previewDrawerOpen.value = false;
    handleEdit(upload);
}

// Handle delete from preview drawer
function handlePreviewDelete(upload: UploadType) {
    previewDrawerOpen.value = false;
    handleDelete(upload);
}

// Handle retry from preview drawer
function handlePreviewRetry(upload: UploadType) {
    handleRetry(upload);
}

// Status options
const statusOptions = [
    { value: 'all', label: 'All Status' },
    { value: 'pending', label: 'Pending' },
    { value: 'uploading', label: 'Uploading' },
    { value: 'uploaded', label: 'Uploaded' },
    { value: 'failed', label: 'Failed' },
];
</script>

<template>
    <div class="min-h-screen bg-background pb-20">
        <Head title="Project Uploads" />

        <!-- Header -->
        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2">
                    <Upload class="h-6 w-6 text-primary" />
                    <h1 class="text-lg font-semibold">Project Uploads</h1>
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
        <main class="p-4">
            <!-- Project Selector -->
            <div class="mb-4">
                <ProjectSelector
                    v-model="selectedProjectId"
                    :projects="projects"
                    label="Select Project"
                />
            </div>

            <!-- No project selected -->
            <div v-if="!selectedProject" class="py-12 text-center">
                <FolderOpen class="mx-auto h-12 w-12 text-muted-foreground/50" />
                <h3 class="mt-4 text-lg font-medium">Select a Project</h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    Choose a project to view its uploads.
                </p>
            </div>

            <!-- Project selected -->
            <template v-else>
                <!-- Filters -->
                <div class="mb-4 flex gap-2">
                    <div class="relative flex-1">
                        <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            v-model="searchQuery"
                            placeholder="Search uploads..."
                            class="pl-9"
                        />
                    </div>
                    <Select v-model="statusFilter">
                        <SelectTrigger class="w-32">
                            <Filter class="mr-2 h-4 w-4" />
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in statusOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Stats -->
                <div class="mb-4 flex items-center justify-between text-sm text-muted-foreground">
                    <span>{{ pagination.total }} upload(s)</span>
                    <Button
                        variant="outline"
                        size="sm"
                        as="a"
                        href="/app/uploads"
                    >
                        <Plus class="mr-2 h-4 w-4" />
                        New Upload
                    </Button>
                </div>

                <!-- Loading -->
                <div v-if="isLoading && !uploads.length" class="py-12 text-center">
                    <Loader2 class="mx-auto h-8 w-8 animate-spin text-muted-foreground" />
                    <p class="mt-2 text-sm text-muted-foreground">Loading uploads...</p>
                </div>

                <!-- Empty state -->
                <div v-else-if="!uploads.length" class="py-12 text-center">
                    <Upload class="mx-auto h-12 w-12 text-muted-foreground/50" />
                    <h3 class="mt-4 text-lg font-medium">No uploads yet</h3>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Upload documents and photos for this project.
                    </p>
                    <Button class="mt-4" as="a" href="/app/uploads">
                        <Plus class="mr-2 h-4 w-4" />
                        Upload Now
                    </Button>
                </div>

                <!-- Upload list -->
                <div v-else class="space-y-3">
                    <UploadListItem
                        v-for="upload in uploads"
                        :key="upload.id"
                        :upload="upload"
                        @edit="handleEdit"
                        @delete="handleDelete"
                        @retry="handleRetry"
                        @view="handleView"
                    />

                    <!-- Load more -->
                    <div v-if="hasMore" class="pt-4 text-center">
                        <Button
                            variant="outline"
                            :disabled="isLoading"
                            @click="loadMore"
                        >
                            <Loader2 v-if="isLoading" class="mr-2 h-4 w-4 animate-spin" />
                            Load More
                        </Button>
                    </div>
                </div>

                <!-- Error -->
                <div v-if="uploadsError" class="mt-4 rounded-lg bg-destructive/10 p-4 text-center text-sm text-destructive">
                    {{ uploadsError }}
                </div>
            </template>
        </main>

        <AppBottomNav />

        <!-- Edit Sheet -->
        <UploadEditSheet
            v-model:open="editSheetOpen"
            :upload="editingUpload"
            @save="handleSaveEdit"
        />

        <!-- Delete Dialog -->
        <UploadDeleteDialog
            v-model:open="deleteDialogOpen"
            :upload="deletingUpload"
            @confirm="handleConfirmDelete"
        />

        <!-- Preview Drawer -->
        <UploadPreviewDrawer
            v-model:open="previewDrawerOpen"
            :upload="previewingUpload"
            @edit="handlePreviewEdit"
            @delete="handlePreviewDelete"
            @retry="handlePreviewRetry"
        />
    </div>
</template>
