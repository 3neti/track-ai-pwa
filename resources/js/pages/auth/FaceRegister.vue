<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Camera, Check, RotateCcw } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';

const props = defineProps<{
    username?: string;
}>();

type CaptureStep = 'selfie' | 'document';
type State = 'initializing' | 'ready' | 'captured' | 'submitting' | 'success' | 'error';

const step = ref<CaptureStep>('selfie');
const state = ref<State>('initializing');
const errorMessage = ref('');
const validationErrors = ref<Record<string, string>>({});
const videoRef = ref<HTMLVideoElement | null>(null);
const canvasRef = ref<HTMLCanvasElement | null>(null);
const stream = ref<MediaStream | null>(null);
const selfieImage = ref<string | null>(null);
const documentImage = ref<string | null>(null);
const isOffline = ref(!navigator.onLine);
const cameraInitialized = ref(false);

const activeImage = computed(() => (step.value === 'selfie' ? selfieImage.value : documentImage.value));
const title = computed(() => (step.value === 'selfie' ? 'Register Selfie' : 'Register Document'));
const instruction = computed(() => (step.value === 'selfie' ? 'Capture your face clearly.' : 'Capture the document face image clearly.'));

const stateMessage = computed(() => {
    if (state.value === 'success') return 'Registration complete. Returning to login...';
    if (state.value === 'submitting') return 'Registering face with Saras...';
    if (state.value === 'error') return errorMessage.value || 'Registration failed.';
    if (state.value === 'captured') return `${step.value === 'selfie' ? 'Selfie' : 'Document'} captured.`;
    if (state.value === 'ready') return instruction.value;

    return 'Starting camera...';
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
            cameraInitialized.value = true;
            state.value = 'ready';
        }
    } catch {
        state.value = 'error';
        cameraInitialized.value = false;
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

    ctx.translate(canvas.width, 0);
    ctx.scale(-1, 1);
    ctx.drawImage(video, 0, 0);

    const image = canvas.toDataURL('image/jpeg', 0.9);

    if (step.value === 'selfie') {
        selfieImage.value = image;
    } else {
        documentImage.value = image;
    }

    state.value = 'captured';
}

function retake() {
    if (step.value === 'selfie') {
        selfieImage.value = null;
    } else {
        documentImage.value = null;
    }

    validationErrors.value = {};
    state.value = 'ready';
}

function continueToDocument() {
    step.value = 'document';
    validationErrors.value = {};
    state.value = documentImage.value ? 'captured' : 'ready';
}

function backToSelfie() {
    step.value = 'selfie';
    validationErrors.value = {};
    state.value = selfieImage.value ? 'captured' : 'ready';
}

async function submit() {
    if (!selfieImage.value || !documentImage.value) return;

    if (isOffline.value) {
        state.value = 'error';
        errorMessage.value = 'You are offline. Face registration requires an internet connection.';
        return;
    }

    state.value = 'submitting';
    validationErrors.value = {};

    try {
        const [selfieBlob, documentBlob] = await Promise.all([
            dataUrlToBlob(selfieImage.value),
            dataUrlToBlob(documentImage.value),
        ]);

        const formData = new FormData();
        formData.append('selfie', selfieBlob, 'selfie.jpg');
        formData.append('document', documentBlob, 'document.jpg');

        const response = await fetch('/auth/face/register', {
            method: 'POST',
            body: formData,
            headers: {
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
        });

        const data = await response.json();

        if (response.ok && data.ok) {
            state.value = 'success';
            stopCamera();
            setTimeout(() => {
                router.visit(data.redirect || '/login');
            }, 700);
            return;
        }

        if (data.errors) {
            validationErrors.value = data.errors;
        }

        state.value = 'error';
        errorMessage.value = data.message || 'Face registration failed. Please try again.';
    } catch {
        state.value = 'error';
        errorMessage.value = 'Connection error. Please try again.';
    }
}

async function dataUrlToBlob(dataUrl: string): Promise<Blob> {
    const response = await fetch(dataUrl);

    return await response.blob();
}

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function retryCamera() {
    errorMessage.value = '';
    state.value = 'initializing';
    startCamera();
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
        :title="title"
        :description="username ? `Setting up face login for ${username}` : 'Setting up face login'"
    >
        <Head title="Register Face" />

        <div
            v-if="isOffline"
            class="mb-4 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200"
        >
            You are offline. Face registration requires an internet connection.
        </div>

        <div class="mb-4 grid grid-cols-2 gap-2 text-xs font-medium">
            <div class="rounded-md border px-3 py-2" :class="{ 'border-primary bg-primary/10': step === 'selfie' }">
                <Check v-if="selfieImage" class="mr-1 inline h-3 w-3" />
                Selfie
            </div>
            <div class="rounded-md border px-3 py-2" :class="{ 'border-primary bg-primary/10': step === 'document' }">
                <Check v-if="documentImage" class="mr-1 inline h-3 w-3" />
                Document
            </div>
        </div>

        <div class="relative mx-auto aspect-[4/3] w-full max-w-sm overflow-hidden rounded-lg bg-black">
            <video
                v-show="state === 'ready' || state === 'initializing'"
                ref="videoRef"
                class="h-full w-full object-cover"
                style="transform: scaleX(-1)"
                autoplay
                playsinline
                muted
            />

            <img
                v-if="activeImage && state !== 'ready' && state !== 'initializing'"
                :src="activeImage"
                alt="Captured registration image"
                class="h-full w-full object-cover"
            />

            <div
                v-if="state === 'ready' && step === 'selfie'"
                class="pointer-events-none absolute inset-0 flex items-center justify-center"
            >
                <div class="h-48 w-36 rounded-full border-2 border-white/50" />
            </div>

            <div
                v-if="state === 'initializing' || state === 'submitting'"
                class="absolute inset-0 flex items-center justify-center bg-black/50"
            >
                <Spinner class="h-8 w-8 text-white" />
            </div>

            <canvas ref="canvasRef" class="hidden" />
        </div>

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

        <InputError :message="validationErrors.selfie" class="mt-2" />
        <InputError :message="validationErrors.document" class="mt-2" />

        <div class="mt-6 flex flex-col gap-3">
            <Button
                v-if="state === 'ready'"
                type="button"
                class="w-full"
                :disabled="isOffline"
                @click="capture"
            >
                <Camera class="mr-2 h-4 w-4" />
                Capture {{ step === 'selfie' ? 'Selfie' : 'Document' }}
            </Button>

            <template v-if="state === 'captured'">
                <Button
                    v-if="step === 'selfie'"
                    type="button"
                    class="w-full"
                    @click="continueToDocument"
                >
                    Continue
                </Button>
                <Button
                    v-else
                    type="button"
                    class="w-full"
                    :disabled="!selfieImage || !documentImage || isOffline"
                    @click="submit"
                >
                    Register Face
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="w-full"
                    @click="retake"
                >
                    <RotateCcw class="mr-2 h-4 w-4" />
                    Retake
                </Button>
                <Button
                    v-if="step === 'document'"
                    type="button"
                    variant="ghost"
                    class="w-full"
                    @click="backToSelfie"
                >
                    Back to Selfie
                </Button>
            </template>

            <template v-if="state === 'error'">
                <Button
                    v-if="!cameraInitialized"
                    type="button"
                    class="w-full"
                    @click="retryCamera"
                >
                    Retry Camera
                </Button>
                <Button
                    v-else
                    type="button"
                    class="w-full"
                    @click="retake"
                >
                    Retake
                </Button>
            </template>
        </div>
    </AuthBase>
</template>
