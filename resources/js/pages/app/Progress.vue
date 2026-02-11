<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { TrendingUp, Camera, Check, AlertCircle, Sparkles } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import SyncBadge from '@/components/app/SyncBadge.vue';
import ProjectSelector from '@/components/app/ProjectSelector.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { useGeolocation } from '@/composables/useGeolocation';
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

const { pendingCount, syncStatus, isOnline, triggerSync, queueRequest } = useOfflineQueue();
const { state: geoState, getCurrentPosition } = useGeolocation();
const { getActiveProjectId } = useActiveProject();

const selectedProject = ref(getActiveProjectId(props.projects));
const remarks = ref('');
const isSubmitting = ref(false);
const message = ref<{ type: 'success' | 'error'; text: string } | null>(null);
const aiStatus = ref<string | null>(null);

const checklistItems = ref([
    { type: 'top_view', label: 'Top View', completed: false, photo: null as File | null },
    { type: 'left_side', label: 'Left Side View', completed: false, photo: null as File | null },
    { type: 'right_side', label: 'Right Side View', completed: false, photo: null as File | null },
    { type: 'front_view', label: 'Front View', completed: false, photo: null as File | null },
    { type: 'detail', label: 'Detail Shot', completed: false, photo: null as File | null },
]);

const completedCount = computed(() => checklistItems.value.filter(item => item.completed).length);

const canSubmit = computed(() => {
    return selectedProject.value && completedCount.value > 0 && !isSubmitting.value;
});

const handlePhotoCapture = (index: number, event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        checklistItems.value[index].photo = target.files[0];
        checklistItems.value[index].completed = true;
    }
};

const handleSubmit = async () => {
    if (!canSubmit.value) return;

    isSubmitting.value = true;
    message.value = null;
    aiStatus.value = null;

    await getCurrentPosition();

    const payload = {
        contract_id: selectedProject.value,
        checklist_items: checklistItems.value
            .filter(item => item.completed)
            .map(item => ({
                type: item.type,
                completed: item.completed,
                notes: null,
            })),
        remarks: remarks.value || null,
        latitude: geoState.value.latitude || 0,
        longitude: geoState.value.longitude || 0,
    };

    const idempotencyKey = `progress_${selectedProject.value}_${Date.now()}`;

    try {
        if (isOnline.value) {
            const response = await axios.post('/api/progress/submit', payload);

            if (response.data.success) {
                message.value = { type: 'success', text: 'Progress submitted successfully!' };

                // Trigger AI analysis
                aiStatus.value = 'Analyzing...';
                const aiResponse = await axios.post('/api/progress/ai', {
                    contract_id: selectedProject.value,
                    entry_id: response.data.entry_id,
                });

                if (aiResponse.data.success) {
                    aiStatus.value = `AI Analysis: ${aiResponse.data.status}`;
                }

                // Reset form
                checklistItems.value.forEach(item => {
                    item.completed = false;
                    item.photo = null;
                });
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
                        v-model="selectedProject"
                        :projects="projects"
                        label="Project"
                    />

                    <!-- Photo Checklist -->
                    <div class="grid gap-2">
                        <Label>Photo Checklist ({{ completedCount }}/{{ checklistItems.length }})</Label>
                        <div class="space-y-2">
                            <div
                                v-for="(item, index) in checklistItems"
                                :key="item.type"
                                class="flex items-center gap-3 rounded-lg border p-3"
                                :class="{ 'border-green-500 bg-green-50 dark:bg-green-950': item.completed }"
                            >
                                <div class="flex items-center gap-2 flex-1">
                                    <Check v-if="item.completed" class="h-5 w-5 text-green-600" />
                                    <Camera v-else class="h-5 w-5 text-muted-foreground" />
                                    <span class="text-sm">{{ item.label }}</span>
                                </div>
                                <label class="cursor-pointer">
                                    <Button variant="outline" size="sm" as="span">
                                        {{ item.completed ? 'Retake' : 'Capture' }}
                                    </Button>
                                    <input
                                        type="file"
                                        accept="image/*"
                                        capture="environment"
                                        class="hidden"
                                        @change="(e) => handlePhotoCapture(index, e)"
                                    />
                                </label>
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
    </div>
</template>
