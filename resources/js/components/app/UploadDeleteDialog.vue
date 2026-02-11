<script setup lang="ts">
import { ref } from 'vue';
import { Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Upload } from '@/composables/useProjectUploads';

const props = defineProps<{
    open: boolean;
    upload: Upload | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [reason: string | undefined];
}>();

const reason = ref('');

function handleConfirm() {
    emit('confirm', reason.value || undefined);
    emit('update:open', false);
    reason.value = '';
}

function handleCancel() {
    emit('update:open', false);
    reason.value = '';
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete Upload?</DialogTitle>
                <DialogDescription>
                    <template v-if="upload?.status === 'pending'">
                        This upload has not been synced yet and will be permanently removed.
                    </template>
                    <template v-else>
                        This upload will be marked as deleted. The original file on the server will be preserved.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div v-if="upload" class="py-4">
                <p class="mb-4 text-sm font-medium">
                    "{{ upload.title }}"
                </p>

                <div class="grid gap-2">
                    <Label for="reason">Reason (optional)</Label>
                    <Input
                        id="reason"
                        v-model="reason"
                        placeholder="Why are you deleting this?"
                    />
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="handleCancel">Cancel</Button>
                <Button
                    variant="destructive"
                    @click="handleConfirm"
                >
                    <Trash2 class="mr-2 h-4 w-4" />
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
