<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Upload, Camera, File, X, AlertCircle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { useActiveProject } from '@/composables/useActiveProject';
import axios from 'axios';

interface Project {
    id: number;
    external_id: string;
    name: string;
    description: string | null;
}

const props = defineProps<{
    projects: Project[];
}>();

const { pendingCount, syncStatus, isOnline, triggerSync } = useOfflineQueue();
const { getActiveProjectId } = useActiveProject();

const selectedProject = ref(getActiveProjectId(props.projects));
const documentType = ref('');
const fileName = ref('');
const remarks = ref('');
const selectedFile = ref<File | null>(null);
const isSubmitting = ref(false);
const message = ref<{ type: 'success' | 'error'; text: string } | null>(null);

const documentTypes = [
    { value: 'purchase_order', label: 'Purchase Order' },
    { value: 'equipment_pictures', label: 'Equipment Pictures' },
    { value: 'delivery_receipts', label: 'Delivery Receipts' },
    { value: 'meals', label: 'Meals' },
    { value: 'documents', label: 'Documents' },
    { value: 'other', label: 'Other' },
];

const canSubmit = computed(() => {
    return selectedProject.value && documentType.value && selectedFile.value && !isSubmitting.value;
});

const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        selectedFile.value = target.files[0];
    }
};

const clearFile = () => {
    selectedFile.value = null;
};

const handleSubmit = async () => {
    if (!canSubmit.value) return;

    isSubmitting.value = true;
    message.value = null;

    // Find project by external_id to get numeric id
    const project = props.projects.find(p => p.external_id === selectedProject.value);
    if (!project) {
        message.value = { type: 'error', text: 'Please select a valid project.' };
        isSubmitting.value = false;
        return;
    }

    try {
        // Generate a client_request_id for idempotency
        const clientRequestId = crypto.randomUUID();

        // Step 1: Create Upload record
        const createResponse = await axios.post(`/api/projects/${project.id}/uploads`, {
            contract_id: selectedProject.value,
            client_request_id: clientRequestId,
            title: fileName.value || selectedFile.value?.name || 'Untitled Upload',
            document_type: documentType.value,
            tags: [documentType.value],
            remarks: remarks.value || null,
        });

        if (!createResponse.data.success) {
            throw new Error(createResponse.data.message);
        }

        const uploadId = createResponse.data.upload.id;

        // Step 2: Upload the file
        const formData = new FormData();
        formData.append('file', selectedFile.value!);

        const uploadResponse = await axios.post(`/api/projects/${project.id}/uploads/${uploadId}/file`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (uploadResponse.data.success) {
            // Navigate to uploads list with preview query param
            const uploadId = uploadResponse.data.upload.id;
            router.visit(`/app/project-uploads?preview=${uploadId}`);
        } else {
            throw new Error(uploadResponse.data.message);
        }
    } catch (error) {
        message.value = { type: 'error', text: 'Failed to upload file. Please try again.' };
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <div class="min-h-screen bg-background pb-20">
        <Head title="Uploads" />

        <!-- Header -->
        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2">
                    <Upload class="h-6 w-6 text-primary" />
                    <h1 class="text-lg font-semibold">Uploads</h1>
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
            <Card>
                <CardHeader>
                    <CardTitle>Upload Document</CardTitle>
                    <CardDescription>
                        Upload photos, receipts, or documents for your project
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Message -->
                    <Alert v-if="message" :variant="message.type === 'error' ? 'destructive' : 'default'">
                        <AlertCircle class="h-4 w-4" />
                        <AlertDescription>{{ message.text }}</AlertDescription>
                    </Alert>

                    <!-- Project Selection -->
                    <ProjectSelector
                        v-model="selectedProject"
                        :projects="projects"
                        label="Project"
                    />

                    <!-- Document Type -->
                    <div class="grid gap-2">
                        <Label>Document Type</Label>
                        <Select v-model="documentType">
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

                    <!-- File Selection -->
                    <div class="grid gap-2">
                        <Label>File</Label>
                        <div v-if="selectedFile" class="flex items-center gap-2 rounded-lg border p-3">
                            <File class="h-5 w-5 text-muted-foreground" />
                            <span class="flex-1 truncate text-sm">{{ selectedFile.name }}</span>
                            <Button variant="ghost" size="icon" @click="clearFile">
                                <X class="h-4 w-4" />
                            </Button>
                        </div>
                        <div v-else class="grid grid-cols-2 gap-2">
                            <label class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-4 transition-colors hover:bg-muted/50">
                                <Camera class="mb-2 h-8 w-8 text-muted-foreground" />
                                <span class="text-sm text-muted-foreground">Take Photo</span>
                                <input
                                    type="file"
                                    accept="image/*"
                                    capture="environment"
                                    class="hidden"
                                    @change="handleFileSelect"
                                />
                            </label>
                            <label class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-4 transition-colors hover:bg-muted/50">
                                <File class="mb-2 h-8 w-8 text-muted-foreground" />
                                <span class="text-sm text-muted-foreground">Choose File</span>
                                <input
                                    type="file"
                                    accept="image/*,.pdf,.doc,.docx"
                                    class="hidden"
                                    @change="handleFileSelect"
                                />
                            </label>
                        </div>
                    </div>

                    <!-- File Name -->
                    <div class="grid gap-2">
                        <Label for="fileName">Name (optional)</Label>
                        <Input
                            id="fileName"
                            v-model="fileName"
                            placeholder="Enter a name for this upload"
                        />
                    </div>

                    <!-- Remarks -->
                    <div class="grid gap-2">
                        <Label for="remarks">Remarks (optional)</Label>
                        <Textarea
                            id="remarks"
                            v-model="remarks"
                            placeholder="Add any notes..."
                            rows="2"
                        />
                    </div>

                    <!-- Submit -->
                    <Button
                        @click="handleSubmit"
                        :disabled="!canSubmit"
                        class="w-full"
                    >
                        <Upload class="mr-2 h-4 w-4" />
                        Upload
                    </Button>
                </CardContent>
            </Card>
        </main>

        <AppBottomNav />
    </div>
</template>
