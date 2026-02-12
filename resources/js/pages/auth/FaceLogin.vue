<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';

const props = defineProps<{
    username: string;
}>();

type State = 'initializing' | 'ready' | 'captured' | 'submitting' | 'success' | 'error';

const state = ref<State>('initializing');
const errorMessage = ref('');
const videoRef = ref<HTMLVideoElement | null>(null);
const canvasRef = ref<HTMLCanvasElement | null>(null);
const capturedImage = ref<string | null>(null);
const stream = ref<MediaStream | null>(null);
const isOffline = ref(!navigator.onLine);

const stateMessage = computed(() => {
    switch (state.value) {
        case 'initializing':
            return 'Starting camera...';
        case 'ready':
            return 'Position your face in the frame';
        case 'captured':
            return 'Photo captured. Ready to verify.';
        case 'submitting':
            return 'Verifying...';
        case 'success':
            return 'Verified! Redirecting...';
        case 'error':
            return errorMessage.value || 'Verification failed';
        default:
            return '';
    }
});

async function startCamera() {
    try {
        stream.value = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false,
        });

        if (videoRef.value) {
            videoRef.value.srcObject = stream.value;
            await videoRef.value.play();
            state.value = 'ready';
        }
    } catch (err) {
        state.value = 'error';
        errorMessage.value = 'Could not access camera. Please allow camera permissions.';
    }
}

function stopCamera() {
    if (stream.value) {
        stream.value.getTracks().forEach((track) => track.stop());
        stream.value = null;
    }
}

function capture() {
    if (!videoRef.value || !canvasRef.value) return;

    const canvas = canvasRef.value;
    const video = videoRef.value;

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    // Mirror the image for selfie view
    ctx.translate(canvas.width, 0);
    ctx.scale(-1, 1);
    ctx.drawImage(video, 0, 0);

    capturedImage.value = canvas.toDataURL('image/jpeg', 0.9);
    state.value = 'captured';
}

function retake() {
    capturedImage.value = null;
    state.value = 'ready';
}

async function submit() {
    if (!capturedImage.value || !props.username) return;

    if (isOffline.value) {
        state.value = 'error';
        errorMessage.value = 'You are offline. Face login requires an internet connection.';
        return;
    }

    state.value = 'submitting';

    try {
        // Convert base64 to blob
        const response = await fetch(capturedImage.value);
        const blob = await response.blob();

        const formData = new FormData();
        formData.append('username', props.username);
        formData.append('selfie', blob, 'selfie.jpg');

        const result = await fetch('/auth/face/verify', {
            method: 'POST',
            body: formData,
            headers: {
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
        });

        const data = await result.json();

        if (data.verified) {
            state.value = 'success';
            stopCamera();
            // Redirect using Inertia
            setTimeout(() => {
                router.visit(data.redirect || '/app/projects');
            }, 500);
        } else {
            state.value = 'error';
            errorMessage.value = data.details?.message || data.details?.issue || 'Face not recognized. Please try again.';
        }
    } catch (err) {
        state.value = 'error';
        errorMessage.value = 'Connection error. Please try again.';
    }
}

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function handleOnline() {
    isOffline.value = false;
}

function handleOffline() {
    isOffline.value = true;
}

onMounted(() => {
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    startCamera();
});

onUnmounted(() => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
    stopCamera();
});
</script>

<template>
    <AuthBase
        title="Face Login"
        :description="`Logging in as ${username}`"
    >
        <Head title="Face Login" />

        <!-- Offline Warning -->
        <div
            v-if="isOffline"
            class="mb-4 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200"
        >
            You are offline. Face login requires an internet connection.
        </div>

        <!-- Camera / Capture View -->
        <div class="relative mx-auto aspect-[4/3] w-full max-w-sm overflow-hidden rounded-lg bg-black">
            <!-- Video Preview -->
            <video
                v-show="state === 'ready' || state === 'initializing'"
                ref="videoRef"
                class="h-full w-full object-cover"
                style="transform: scaleX(-1)"
                autoplay
                playsinline
                muted
            />

            <!-- Captured Image -->
            <img
                v-if="capturedImage && state !== 'ready' && state !== 'initializing'"
                :src="capturedImage"
                alt="Captured selfie"
                class="h-full w-full object-cover"
            />

            <!-- Face Guide Overlay -->
            <div
                v-if="state === 'ready'"
                class="pointer-events-none absolute inset-0 flex items-center justify-center"
            >
                <div class="h-48 w-36 rounded-full border-2 border-white/50" />
            </div>

            <!-- Loading Overlay -->
            <div
                v-if="state === 'initializing' || state === 'submitting'"
                class="absolute inset-0 flex items-center justify-center bg-black/50"
            >
                <Spinner class="h-8 w-8 text-white" />
            </div>

            <!-- Success Overlay -->
            <div
                v-if="state === 'success'"
                class="absolute inset-0 flex items-center justify-center bg-green-500/50"
            >
                <svg class="h-16 w-16 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>

            <!-- Hidden Canvas -->
            <canvas ref="canvasRef" class="hidden" />
        </div>

        <!-- Status Message -->
        <p
            class="mt-4 text-center text-sm"
            :class="{
                'text-muted-foreground': state !== 'error' && state !== 'success',
                'text-red-600 dark:text-red-400': state === 'error',
                'text-green-600 dark:text-green-400': state === 'success',
            }"
        >
            {{ stateMessage }}
        </p>

        <!-- Action Buttons -->
        <div class="mt-6 flex flex-col gap-3">
            <!-- Capture Button -->
            <Button
                v-if="state === 'ready'"
                type="button"
                class="w-full"
                @click="capture"
                :disabled="isOffline"
            >
                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Capture
            </Button>

            <!-- Verify / Retake Buttons -->
            <template v-if="state === 'captured'">
                <Button
                    type="button"
                    class="w-full"
                    @click="submit"
                    :disabled="isOffline"
                >
                    Verify & Log In
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="w-full"
                    @click="retake"
                >
                    Retake Photo
                </Button>
            </template>

            <!-- Error State Buttons -->
            <template v-if="state === 'error'">
                <Button
                    type="button"
                    class="w-full"
                    @click="retake"
                >
                    Try Again
                </Button>
            </template>
        </div>

        <!-- Back to Password Login -->
        <div class="mt-6 text-center">
            <Link
                :href="login()"
                class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
            >
                Use password instead
            </Link>
        </div>
    </AuthBase>
</template>
