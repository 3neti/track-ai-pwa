<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import { Clock, MapPin, LogIn, LogOut, AlertCircle, Timer, AlertTriangle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import SyncBadge from '@/components/app/SyncBadge.vue';
import ProjectSelector from '@/components/app/ProjectSelector.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { useGeolocation } from '@/composables/useGeolocation';
import { useActiveProject } from '@/composables/useActiveProject';
import axios from 'axios';

const page = usePage();

interface Project {
    id: number;
    external_id: string;
    name: string;
    description: string | null;
}

interface AttendanceSession {
    id: number;
    check_in_at: string;
    check_in_latitude?: number;
    check_in_longitude?: number;
    check_out_at?: string;
    duration_minutes?: number;
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
const isLoadingStatus = ref(false);
const message = ref<{ type: 'success' | 'error' | 'warning'; text: string } | null>(null);

// Attendance state
const attendanceStatus = ref<'checked_in' | 'checked_out'>('checked_out');
const currentSession = ref<AttendanceSession | null>(null);
const autoClosedWarning = ref<string | null>(null);

const canCheckIn = computed(() => {
    return selectedProject.value && geoState.value.latitude && !isSubmitting.value && attendanceStatus.value === 'checked_out';
});

const canCheckOut = computed(() => {
    return selectedProject.value && geoState.value.latitude && !isSubmitting.value && attendanceStatus.value === 'checked_in';
});

const sessionDuration = computed(() => {
    if (!currentSession.value?.check_in_at) return null;
    const checkInTime = new Date(currentSession.value.check_in_at);
    const now = new Date();
    const diffMs = now.getTime() - checkInTime.getTime();
    const hours = Math.floor(diffMs / (1000 * 60 * 60));
    const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
    return `${hours}h ${minutes}m`;
});

const formattedCheckInTime = computed(() => {
    if (!currentSession.value?.check_in_at) return null;
    return new Date(currentSession.value.check_in_at).toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
});

// Fetch attendance status when project changes
const fetchStatus = async () => {
    if (!selectedProject.value || !isOnline.value) return;

    isLoadingStatus.value = true;
    autoClosedWarning.value = null;

    try {
        const response = await axios.get('/api/attendance/status', {
            params: { contract_id: selectedProject.value },
        });

        if (response.data.success) {
            attendanceStatus.value = response.data.attendance_status;
            currentSession.value = response.data.session;

            // Show warning if a session was auto-closed
            if (response.data.auto_closed_session) {
                const reason = response.data.auto_closed_session.reason === 'previous_day_unclosed'
                    ? 'Your previous session was automatically closed because you forgot to check out.'
                    : 'A previous session was automatically closed.';
                autoClosedWarning.value = reason;
            }
        }
    } catch (error) {
        console.error('Failed to fetch attendance status:', error);
    } finally {
        isLoadingStatus.value = false;
    }
};

// Watch for project changes
watch(selectedProject, () => {
    fetchStatus();
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

                // Update local state from response
                attendanceStatus.value = response.data.attendance_status;
                currentSession.value = response.data.session;
            } else {
                message.value = { type: 'error', text: response.data.message || 'Operation failed.' };
            }
        } else {
            // Queue for later - optimistically update UI
            await queueRequest(endpoint, 'POST', payload, idempotencyKey);
            message.value = { type: 'success', text: `${type === 'check-in' ? 'Check-in' : 'Check-out'} saved offline. Will sync when online.` };
            remarks.value = '';

            // Optimistic update for offline mode
            if (type === 'check-in') {
                attendanceStatus.value = 'checked_in';
                currentSession.value = {
                    id: 0,
                    check_in_at: new Date().toISOString(),
                };
            } else {
                attendanceStatus.value = 'checked_out';
                currentSession.value = null;
            }
        }
    } catch (error: any) {
        const errorMessage = error.response?.data?.message || 'Failed to record attendance.';

        // If it's a policy error (already checked in/out), update state
        if (error.response?.data?.attendance_status) {
            attendanceStatus.value = error.response.data.attendance_status;
            currentSession.value = error.response.data.session;
        }

        // If online request fails due to network, queue it
        if (!error.response && isOnline.value) {
            await queueRequest(endpoint, 'POST', payload, idempotencyKey);
            message.value = { type: 'success', text: 'Request failed but saved offline. Will retry later.' };
        } else {
            message.value = { type: 'error', text: errorMessage };
        }
    } finally {
        isSubmitting.value = false;
    }
};

// Initialize on mount
onMounted(() => {
    getCurrentPosition();
    fetchStatus();
});
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
                    <!-- Auto-closed Warning -->
                    <Alert v-if="autoClosedWarning" variant="default" class="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                        <AlertTriangle class="h-4 w-4 text-amber-600" />
                        <AlertTitle class="text-amber-800 dark:text-amber-200">Session Auto-Closed</AlertTitle>
                        <AlertDescription class="text-amber-700 dark:text-amber-300">{{ autoClosedWarning }}</AlertDescription>
                    </Alert>

                    <!-- Message -->
                    <Alert v-if="message" :variant="message.type === 'error' ? 'destructive' : 'default'">
                        <AlertCircle class="h-4 w-4" />
                        <AlertDescription>{{ message.text }}</AlertDescription>
                    </Alert>

                    <!-- Current Status Card -->
                    <div
                        v-if="selectedProject && !isLoadingStatus"
                        class="rounded-lg border-2 p-4"
                        :class="attendanceStatus === 'checked_in' ? 'border-green-500 bg-green-50 dark:bg-green-950' : 'border-muted'"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div
                                    class="h-3 w-3 rounded-full"
                                    :class="attendanceStatus === 'checked_in' ? 'bg-green-500 animate-pulse' : 'bg-muted-foreground'"
                                />
                                <span class="font-medium">
                                    {{ attendanceStatus === 'checked_in' ? 'Currently Checked In' : 'Not Checked In' }}
                                </span>
                            </div>
                            <Badge :variant="attendanceStatus === 'checked_in' ? 'default' : 'secondary'">
                                {{ attendanceStatus === 'checked_in' ? 'On Site' : 'Off Site' }}
                            </Badge>
                        </div>
                        <div v-if="attendanceStatus === 'checked_in' && currentSession" class="mt-3 flex items-center gap-4 text-sm text-muted-foreground">
                            <span class="flex items-center gap-1">
                                <Clock class="h-4 w-4" />
                                Since {{ formattedCheckInTime }}
                            </span>
                            <span class="flex items-center gap-1">
                                <Timer class="h-4 w-4" />
                                {{ sessionDuration }}
                            </span>
                        </div>
                    </div>

                    <!-- Loading Status -->
                    <div v-else-if="isLoadingStatus" class="rounded-lg border p-4">
                        <div class="flex items-center gap-2 text-sm text-muted-foreground">
                            <div class="h-4 w-4 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                            <span>Loading attendance status...</span>
                        </div>
                    </div>

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
                            :disabled="!canCheckIn"
                            class="h-14"
                            :class="{ 'opacity-50': attendanceStatus === 'checked_in' }"
                        >
                            <LogIn class="mr-2 h-5 w-5" />
                            Check In
                        </Button>
                        <Button
                            @click="handleCheckOut"
                            :disabled="!canCheckOut"
                            variant="outline"
                            class="h-14"
                            :class="{ 'border-green-500 text-green-600 hover:bg-green-50': attendanceStatus === 'checked_in' }"
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
