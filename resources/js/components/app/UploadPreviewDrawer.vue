<script setup lang="ts">
import { computed, ref } from 'vue';
import { Check, Edit, Trash2, RefreshCw, Lock, FileImage, FileText, File, ZoomIn, ZoomOut, AlertCircle, Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Sheet,
    SheetContent,
} from '@/components/ui/sheet';
import UploadStatusBadge from './UploadStatusBadge.vue';
import type { Upload } from '@/composables/useProjectUploads';

const props = defineProps<{
    open: boolean;
    upload: Upload | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    edit: [upload: Upload];
    delete: [upload: Upload];
    retry: [upload: Upload];
}>();

// Preview state
const isPreviewLoading = ref(true);
const previewError = ref<string | null>(null);
const zoomLevel = ref(1);

// Computed properties - use server-provided values with fallback
const previewType = computed(() => {
    // Use server-provided value if available
    if (props.upload?.preview_type) return props.upload.preview_type;
    // Fallback computation
    if (!props.upload?.mime) return 'unknown';
    if (props.upload.mime.startsWith('image/')) return 'image';
    if (props.upload.mime === 'application/pdf') return 'pdf';
    return 'unknown';
});

const isPreviewable = computed(() => {
    // Use server-provided value if available
    if (props.upload?.is_previewable !== undefined) return props.upload.is_previewable;
    // Fallback computation
    return previewType.value === 'image' || previewType.value === 'pdf';
});

const previewUrl = computed(() => {
    if (!props.upload) return null;
    // Use server-provided URL if available, otherwise construct it
    return props.upload.preview_url || `/api/uploads/${props.upload.id}/preview`;
});

const fileIcon = computed(() => {
    if (previewType.value === 'image') return FileImage;
    if (previewType.value === 'pdf') return FileText;
    return File;
});

const formattedSize = computed(() => {
    if (!props.upload?.size) return null;
    const kb = props.upload.size / 1024;
    if (kb < 1024) return `${kb.toFixed(1)} KB`;
    return `${(kb / 1024).toFixed(1)} MB`;
});

const formattedDate = computed(() => {
    if (!props.upload) return '';
    return new Date(props.upload.created_at).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
});

const isLocked = computed(() => props.upload?.locked_at !== null);

const isEditable = computed(() => {
    return !isLocked.value && props.upload?.status !== 'deleted';
});

const isRetryable = computed(() => {
    return props.upload?.status === 'failed' && !isLocked.value;
});

// Event handlers
function handleClose() {
    emit('update:open', false);
    resetPreviewState();
}

function handleEdit() {
    if (props.upload && isEditable.value) {
        emit('edit', props.upload);
    }
}

function handleDelete() {
    if (props.upload && isEditable.value) {
        emit('delete', props.upload);
    }
}

function handleRetry() {
    if (props.upload && isRetryable.value) {
        emit('retry', props.upload);
    }
}

function handleZoomIn() {
    zoomLevel.value = Math.min(zoomLevel.value + 0.25, 3);
}

function handleZoomOut() {
    zoomLevel.value = Math.max(zoomLevel.value - 0.25, 0.5);
}

function handlePreviewLoad() {
    isPreviewLoading.value = false;
    previewError.value = null;
}

function handlePreviewError() {
    isPreviewLoading.value = false;
    previewError.value = 'Failed to load preview';
}

function resetPreviewState() {
    isPreviewLoading.value = true;
    previewError.value = null;
    zoomLevel.value = 1;
}
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent
            side="bottom"
            class="flex h-[95vh] flex-col rounded-t-xl p-0"
        >
            <!-- Header -->
            <div class="flex shrink-0 items-center justify-between border-b px-4 py-3">
                <div class="flex items-center gap-2">
                    <component :is="fileIcon" class="h-5 w-5 text-muted-foreground" />
                    <h2 class="text-lg font-semibold truncate max-w-[200px]">
                        {{ upload?.title }}
                    </h2>
                </div>
                <div class="flex items-center gap-2">
                    <UploadStatusBadge v-if="upload" :status="upload.status" />
                    <Button variant="ghost" size="icon" class="h-8 w-8" @click="handleClose">
                        <X class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <!-- Lock Banner -->
            <Alert v-if="isLocked && upload" variant="destructive" class="mx-4 mt-4 shrink-0">
                <Lock class="h-4 w-4" />
                <AlertDescription>
                    This upload is locked: {{ upload.locked_reason || 'No reason provided' }}
                </AlertDescription>
            </Alert>

            <!-- Preview Area -->
            <div class="relative flex-1 overflow-auto bg-muted/30">
                <!-- Loading state -->
                <div
                    v-if="isPreviewLoading && isPreviewable"
                    class="absolute inset-0 flex items-center justify-center"
                >
                    <Loader2 class="h-8 w-8 animate-spin text-muted-foreground" />
                </div>

                <!-- Preview error -->
                <div
                    v-if="previewError"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-center"
                >
                    <AlertCircle class="h-8 w-8 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">{{ previewError }}</p>
                </div>

                <!-- Image preview -->
                <div
                    v-if="previewType === 'image' && previewUrl && !previewError"
                    class="flex h-full items-center justify-center p-4"
                >
                    <img
                        :src="previewUrl"
                        :alt="upload?.title"
                        class="max-h-full max-w-full object-contain transition-transform"
                        :style="{ transform: `scale(${zoomLevel})` }"
                        @load="handlePreviewLoad"
                        @error="handlePreviewError"
                    />
                </div>

                <!-- PDF preview -->
                <div
                    v-else-if="previewType === 'pdf' && previewUrl && !previewError"
                    class="h-full w-full p-4"
                >
                    <iframe
                        :src="previewUrl"
                        class="h-full w-full rounded-lg border bg-white"
                        @load="handlePreviewLoad"
                        @error="handlePreviewError"
                    />
                </div>

                <!-- Non-previewable file -->
                <div
                    v-else-if="!isPreviewable"
                    class="flex h-full flex-col items-center justify-center gap-4 text-center"
                >
                    <component :is="fileIcon" class="h-16 w-16 text-muted-foreground/50" />
                    <div>
                        <p class="font-medium">Preview not available</p>
                        <p class="text-sm text-muted-foreground">
                            {{ upload?.mime || 'Unknown file type' }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Zoom controls (image only) -->
            <div
                v-if="previewType === 'image' && !previewError && !isPreviewLoading"
                class="absolute right-4 top-1/2 flex -translate-y-1/2 flex-col gap-2"
            >
                <Button variant="secondary" size="icon" class="h-8 w-8" @click="handleZoomIn">
                    <ZoomIn class="h-4 w-4" />
                </Button>
                <Button variant="secondary" size="icon" class="h-8 w-8" @click="handleZoomOut">
                    <ZoomOut class="h-4 w-4" />
                </Button>
            </div>

            <!-- Metadata Section -->
            <div class="shrink-0 border-t bg-background p-4 space-y-3">
                <!-- File info row -->
                <div class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <span>{{ upload?.document_type }}</span>
                    <span v-if="formattedSize">• {{ formattedSize }}</span>
                    <span>• {{ formattedDate }}</span>
                </div>

                <!-- Tags -->
                <div v-if="upload?.tags?.length" class="flex flex-wrap gap-1">
                    <Badge v-for="tag in upload.tags" :key="tag" variant="outline" class="text-xs">
                        {{ tag }}
                    </Badge>
                </div>

                <!-- Remarks -->
                <p v-if="upload?.remarks" class="text-sm text-muted-foreground">
                    {{ upload.remarks }}
                </p>

                <!-- Uploader -->
                <div v-if="upload?.user" class="text-xs text-muted-foreground">
                    Uploaded by {{ upload.user.name }}
                </div>

                <!-- Error message for failed uploads -->
                <div v-if="upload?.last_error" class="text-sm text-destructive">
                    Error: {{ upload.last_error }}
                </div>
            </div>

            <!-- Action Bar -->
            <div class="shrink-0 border-t bg-background p-4">
                <div class="flex gap-2">
                    <!-- Done/Close button - always visible, primary action -->
                    <Button
                        class="flex-1"
                        @click="handleClose"
                    >
                        <Check class="mr-2 h-4 w-4" />
                        Done
                    </Button>

                    <!-- Edit button -->
                    <Button
                        v-if="isEditable"
                        variant="outline"
                        class="flex-1"
                        @click="handleEdit"
                    >
                        <Edit class="mr-2 h-4 w-4" />
                        Edit
                    </Button>

                    <!-- Delete button -->
                    <Button
                        v-if="isEditable"
                        variant="outline"
                        class="flex-1 text-destructive hover:bg-destructive hover:text-destructive-foreground"
                        @click="handleDelete"
                    >
                        <Trash2 class="mr-2 h-4 w-4" />
                        Delete
                    </Button>

                    <!-- Retry button for failed uploads -->
                    <Button
                        v-if="isRetryable"
                        variant="outline"
                        class="flex-1"
                        @click="handleRetry"
                    >
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Retry
                    </Button>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
