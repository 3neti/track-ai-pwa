<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Clock, MapPin, LogIn, LogOut, AlertCircle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import SyncBadge from '@/components/app/SyncBadge.vue';
import ProjectSelector from '@/components/app/ProjectSelector.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { useGeolocation } from '@/composables/useGeolocation';
import axios from 'axios';

const page = usePage();

const { pendingCount, syncStatus, isOnline, triggerSync, queueRequest } = useOfflineQueue();
const { state: geoState, getCurrentPosition } = useGeolocation();

const selectedProject = ref('');
const remarks = ref('');
const isSubmitting = ref(false);
const message = ref<{ type: 'success' | 'error'; text: string } | null>(null);

// Mock projects for now - in real app, fetch from store or props
const projects = ref([
    { id: 1, external_id: 'PROJ-2024-001', name: 'Road Rehabilitation - Bulacan', description: null },
    { id: 2, external_id: 'PROJ-2024-002', name: 'Bridge Construction - Pampanga', description: null },
]);

const canSubmit = computed(() => {
    return selectedProject.value && geoState.value.latitude && !isSubmitting.value;
});

const handleCheckIn = async () => {
    await submitAttendance('check-in');
};

const handleCheckOut = async () => {
    await submitAttendance('check-out');
};

const submitAttendance = async (type: 'check-in' | 'check-out') => {
    if (!selectedProject.value) {
        message.value = { type: 'error', text: 'Please select a project.' };
        return;
    }

    isSubmitting.value = true;
    message.value = null;

    // Get current location
    await getCurrentPosition();

    if (!geoState.value.latitude || !geoState.value.longitude) {
        message.value = { type: 'error', text: geoState.value.error || 'Unable to get location.' };
        isSubmitting.value = false;
        return;
    }

    const payload = {
        contract_id: selectedProject.value,
        latitude: geoState.value.latitude,
        longitude: geoState.value.longitude,
        remarks: remarks.value || null,
    };

    const endpoint = `/api/attendance/${type}`;
    const idempotencyKey = `attendance_${type}_${selectedProject.value}_${Date.now()}`;

    try {
        if (isOnline.value) {
            const response = await axios.post(endpoint, payload);
            if (response.data.success) {
                message.value = { type: 'success', text: `${type === 'check-in' ? 'Check-in' : 'Check-out'} recorded successfully!` };
                remarks.value = '';
            } else {
                throw new Error(response.data.message);
            }
        } else {
            // Queue for later
            await queueRequest(endpoint, 'POST', payload, idempotencyKey);
            message.value = { type: 'success', text: `${type === 'check-in' ? 'Check-in' : 'Check-out'} saved offline. Will sync when online.` };
            remarks.value = '';
        }
    } catch (error) {
        // If online request fails, queue it
        if (isOnline.value) {
            await queueRequest(endpoint, 'POST', payload, idempotencyKey);
            message.value = { type: 'success', text: 'Request failed but saved offline. Will retry later.' };
        } else {
            message.value = { type: 'error', text: 'Failed to save attendance.' };
        }
    } finally {
        isSubmitting.value = false;
    }
};

// Get location on mount
getCurrentPosition();
</script>

<template>
    <div class="min-h-screen bg-background pb-20">
        <Head title="Attendance" />

        <!-- Header -->
        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2">
                    <Clock class="h-6 w-6 text-primary" />
                    <h1 class="text-lg font-semibold">Attendance</h1>
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
                    <CardTitle>Log Attendance</CardTitle>
                    <CardDescription>
                        Record your check-in or check-out for today
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
                        placeholder="Select your project"
                    />

                    <!-- Location Status -->
                    <div class="rounded-lg border p-3">
                        <div class="flex items-center gap-2 text-sm">
                            <MapPin class="h-4 w-4" />
                            <span v-if="geoState.isLoading">Getting location...</span>
                            <span v-else-if="geoState.error" class="text-destructive">
                                {{ geoState.error }}
                            </span>
                            <span v-else-if="geoState.latitude">
                                {{ geoState.latitude?.toFixed(6) }}, {{ geoState.longitude?.toFixed(6) }}
                                <span class="text-muted-foreground">(±{{ geoState.accuracy?.toFixed(0) }}m)</span>
                            </span>
                            <span v-else class="text-muted-foreground">Location not available</span>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div class="grid gap-2">
                        <Label for="remarks">Remarks (optional)</Label>
                        <Textarea
                            id="remarks"
                            v-model="remarks"
                            placeholder="Add any notes about your attendance..."
                            rows="3"
                        />
                    </div>

                    <!-- Action Buttons -->
                    <div class="grid grid-cols-2 gap-3">
                        <Button
                            @click="handleCheckIn"
                            :disabled="!canSubmit"
                            class="h-14"
                        >
                            <LogIn class="mr-2 h-5 w-5" />
                            Check In
                        </Button>
                        <Button
                            @click="handleCheckOut"
                            :disabled="!canSubmit"
                            variant="outline"
                            class="h-14"
                        >
                            <LogOut class="mr-2 h-5 w-5" />
                            Check Out
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </main>

        <AppBottomNav />
    </div>
</template>
