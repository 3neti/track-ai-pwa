<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { X, Save, Lock } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Upload } from '@/composables/useProjectUploads';

const props = defineProps<{
    open: boolean;
    upload: Upload | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    save: [data: { title: string; document_type: string; tags: string[]; remarks: string }];
}>();

const documentTypes = [
    { value: 'purchase_order', label: 'Purchase Order' },
    { value: 'equipment_pictures', label: 'Equipment Pictures' },
    { value: 'delivery_receipts', label: 'Delivery Receipts' },
    { value: 'meals', label: 'Meals' },
    { value: 'documents', label: 'Documents' },
    { value: 'other', label: 'Other' },
];

const title = ref('');
const documentType = ref('');
const tagsInput = ref('');
const remarks = ref('');
const isSaving = ref(false);

// Reset form when upload changes
watch(() => props.upload, (upload) => {
    if (upload) {
        title.value = upload.title;
        documentType.value = upload.document_type;
        tagsInput.value = upload.tags?.join(', ') || '';
        remarks.value = upload.remarks || '';
    }
}, { immediate: true });

const isLocked = computed(() => props.upload?.locked_at !== null);

function handleSave() {
    if (!props.upload || isLocked.value) return;

    const tags = tagsInput.value
        .split(',')
        .map(t => t.trim())
        .filter(t => t.length > 0);

    emit('save', {
        title: title.value,
        document_type: documentType.value,
        tags,
        remarks: remarks.value,
    });
}

function handleClose() {
    emit('update:open', false);
}
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent side="bottom" class="h-[85vh] overflow-y-auto">
            <SheetHeader>
                <SheetTitle>Edit Upload</SheetTitle>
                <SheetDescription>
                    Update the metadata for this upload.
                </SheetDescription>
            </SheetHeader>

            <div v-if="upload" class="mt-6 space-y-4">
                <!-- Lock warning -->
                <Alert v-if="isLocked" variant="destructive">
                    <Lock class="h-4 w-4" />
                    <AlertDescription>
                        This upload is locked: {{ upload.locked_reason || 'No reason provided' }}
                    </AlertDescription>
                </Alert>

                <!-- Title -->
                <div class="grid gap-2">
                    <Label for="title">Title</Label>
                    <Input
                        id="title"
                        v-model="title"
                        placeholder="Enter title"
                        :disabled="isLocked"
                    />
                </div>

                <!-- Document Type -->
                <div class="grid gap-2">
                    <Label>Document Type</Label>
                    <Select v-model="documentType" :disabled="isLocked">
                        <SelectTrigger>
                            <SelectValue placeholder="Select document type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="type in documentTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Tags -->
                <div class="grid gap-2">
                    <Label for="tags">Tags (comma separated)</Label>
                    <Input
                        id="tags"
                        v-model="tagsInput"
                        placeholder="tag1, tag2, tag3"
                        :disabled="isLocked"
                    />
                </div>

                <!-- Remarks -->
                <div class="grid gap-2">
                    <Label for="remarks">Remarks</Label>
                    <Textarea
                        id="remarks"
                        v-model="remarks"
                        placeholder="Add any notes..."
                        rows="3"
                        :disabled="isLocked"
                    />
                </div>

                <!-- Actions -->
                <div class="flex gap-2 pt-4">
                    <Button
                        variant="outline"
                        class="flex-1"
                        @click="handleClose"
                    >
                        <X class="mr-2 h-4 w-4" />
                        Cancel
                    </Button>
                    <Button
                        class="flex-1"
                        :disabled="isLocked || isSaving || !title.trim()"
                        @click="handleSave"
                    >
                        <Save class="mr-2 h-4 w-4" />
                        Save Changes
                    </Button>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
