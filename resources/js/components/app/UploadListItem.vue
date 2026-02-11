<script setup lang="ts">
import { computed } from 'vue';
import { MoreVertical, Edit, Trash2, RefreshCw, Eye, Lock, FileImage, FileText, File } from 'lucide-vue-next';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UploadStatusBadge from './UploadStatusBadge.vue';
import type { Upload } from '@/composables/useProjectUploads';

const props = defineProps<{
    upload: Upload;
}>();

const emit = defineEmits<{
    edit: [upload: Upload];
    delete: [upload: Upload];
    retry: [upload: Upload];
    view: [upload: Upload];
}>();

const fileIcon = computed(() => {
    if (props.upload.mime?.startsWith('image/')) {
        return FileImage;
    }
    if (props.upload.mime?.includes('pdf') || props.upload.mime?.includes('document')) {
        return FileText;
    }
    return File;
});

const formattedSize = computed(() => {
    if (!props.upload.size) return null;
    const kb = props.upload.size / 1024;
    if (kb < 1024) return `${kb.toFixed(1)} KB`;
    return `${(kb / 1024).toFixed(1)} MB`;
});

const formattedDate = computed(() => {
    return new Date(props.upload.created_at).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
});

const isEditable = computed(() => {
    return !props.upload.locked_at && props.upload.status !== 'deleted';
});

const isRetryable = computed(() => {
    return props.upload.status === 'failed' && !props.upload.locked_at;
});
</script>

<template>
    <Card class="overflow-hidden">
        <CardContent class="p-4">
            <div class="flex items-start gap-3">
                <!-- File Icon (clickable for preview) -->
                <button
                    type="button"
                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-muted transition-colors hover:bg-muted/80 active:bg-muted/70"
                    @click="emit('view', upload)"
                >
                    <component :is="fileIcon" class="h-6 w-6 text-muted-foreground" />
                </button>

                <!-- Content -->
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <!-- Title (clickable for preview) -->
                            <button
                                type="button"
                                class="truncate font-medium text-left hover:underline"
                                @click="emit('view', upload)"
                            >
                                {{ upload.title }}
                            </button>

                            <!-- Meta info -->
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span>{{ upload.document_type }}</span>
                                <span v-if="formattedSize">• {{ formattedSize }}</span>
                                <span>• {{ formattedDate }}</span>
                            </div>
                        </div>

                        <!-- Status & Actions -->
                        <div class="flex shrink-0 items-center gap-2">
                            <UploadStatusBadge :status="upload.status" />

                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="icon" class="h-8 w-8">
                                        <MoreVertical class="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem @click="emit('view', upload)">
                                        <Eye class="mr-2 h-4 w-4" />
                                        Preview
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="isEditable"
                                        @click="emit('edit', upload)"
                                    >
                                        <Edit class="mr-2 h-4 w-4" />
                                        Edit Metadata
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="isRetryable"
                                        @click="emit('retry', upload)"
                                    >
                                        <RefreshCw class="mr-2 h-4 w-4" />
                                        Retry
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="isEditable"
                                        class="text-destructive"
                                        @click="emit('delete', upload)"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4" />
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <p v-if="upload.remarks" class="mt-2 line-clamp-2 text-sm text-muted-foreground">
                        {{ upload.remarks }}
                    </p>

                    <!-- Tags -->
                    <div v-if="upload.tags?.length" class="mt-2 flex flex-wrap gap-1">
                        <Badge v-for="tag in upload.tags" :key="tag" variant="outline" class="text-xs">
                            {{ tag }}
                        </Badge>
                    </div>

                    <!-- Lock indicator -->
                    <div v-if="upload.locked_at" class="mt-2 flex items-center gap-1 text-xs text-amber-600">
                        <Lock class="h-3 w-3" />
                        <span>{{ upload.locked_reason || 'Locked' }}</span>
                    </div>

                    <!-- Error message -->
                    <div v-if="upload.last_error" class="mt-2 text-xs text-destructive">
                        Error: {{ upload.last_error }}
                    </div>

                    <!-- Uploader -->
                    <div v-if="upload.user" class="mt-2 text-xs text-muted-foreground">
                        By {{ upload.user.name }}
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
