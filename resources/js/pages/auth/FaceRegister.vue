<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Camera, Check, RotateCcw, Upload } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';

const props = defineProps<{
    username?: string;
    captureProvider?: string;
    hypervergeCapture?: {
        enabled?: boolean;
        workflow?: string;
    };
}>();

type HypervergeTokenResponse = {
    ok?: boolean;
    access_token?: string;
    workflow_id?: string;
    transaction_id?: string;
    sdk_url?: string;
    message?: string;
};

type HypervergeResult = {
    status?: string;
    details?: Record<string, unknown>;
    transactionId?: string;
    errorMessage?: string;
    errorCode?: string | number;
    latestModule?: string;
};

declare global {
    interface Window {
        HyperKycConfig?: new (...args: unknown[]) => {
            setInputs?: (inputs: Record<string, unknown>) => void;
            supportDarkMode?: (enabled: boolean) => void;
            setUseLocation?: (enabled: boolean) => void;
        };
        HyperKYCModule?: {
            launch: (config: unknown, callback: (result: HypervergeResult) => void) => Promise<void>;
        };
    }
}

type CaptureStep = 'selfie' | 'document';
type State = 'initializing' | 'ready' | 'captured' | 'submitting' | 'success' | 'error';

const step = ref<CaptureStep>('selfie');
const state = ref<State>(props.hypervergeCapture?.enabled === true ? 'ready' : 'initializing');
const errorMessage = ref('');
const validationErrors = ref<Record<string, string>>({});
const videoRef = ref<HTMLVideoElement | null>(null);
const canvasRef = ref<HTMLCanvasElement | null>(null);
const documentFileInputRef = ref<HTMLInputElement | null>(null);
const stream = ref<MediaStream | null>(null);
const selfieImage = ref<string | null>(null);
const documentImage = ref<string | null>(null);
const isOffline = ref(!navigator.onLine);
const cameraInitialized = ref(false);
const hypervergeState = ref<'idle' | 'loading' | 'complete' | 'error'>('idle');
const hypervergeMessage = ref('');
const hypervergeSummary = ref<Record<string, unknown> | null>(null);

const activeImage = computed(() => (step.value === 'selfie' ? selfieImage.value : documentImage.value));
const instructionHeading = computed(() => (step.value === 'selfie' ? 'LIVE BIOMETRIC' : 'ID DOCUMENT / CARD'));
const instruction = computed(() => (
    step.value === 'selfie'
        ? 'Position your face inside the frame with good lighting and look directly at the camera.'
        : "Position your official ID (Driver's License, Passport, ID Card) inside the frame, or upload a photo."
));

const stateMessage = computed(() => {
    if (state.value === 'success') return 'Registration complete. Opening face verification...';
    if (state.value === 'submitting') return 'Registering face with Saras...';
    if (state.value === 'error') return errorMessage.value || 'Registration failed.';
    if (state.value === 'captured') return `${step.value === 'selfie' ? 'Selfie' : 'Document'} captured.`;
    if (state.value === 'ready') return instruction.value;

    return 'Starting camera...';
});
const canTryHyperverge = computed(() => props.hypervergeCapture?.enabled === true);
const hypervergeImagesReady = computed(() => selfieImage.value !== null && documentImage.value !== null && hypervergeState.value === 'complete');

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
        state.value = 'captured';
    } else {
        documentImage.value = image;
        void submit();
    }
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

async function launchHypervergeCapture() {
    if (!canTryHyperverge.value) return;

    hypervergeState.value = 'loading';
    hypervergeMessage.value = 'Starting HyperVerge...';
    hypervergeSummary.value = null;

    try {
        const response = await fetch('/auth/hyperverge/token', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                workflow: props.hypervergeCapture?.workflow || 'enrol',
            }),
        });
        const token = (await response.json()) as HypervergeTokenResponse;

        if (!response.ok || !token.ok || !token.access_token || !token.workflow_id || !token.transaction_id || !token.sdk_url) {
            throw new Error(token.message || 'Unable to start HyperVerge capture.');
        }

        await loadHypervergeSdk(token.sdk_url);

        if (!window.HyperKycConfig || !window.HyperKYCModule) {
            throw new Error('HyperVerge SDK did not load.');
        }

        const config = new window.HyperKycConfig(
            token.access_token,
            token.workflow_id,
            token.transaction_id,
            true,
        );

        config.supportDarkMode?.(false);
        config.setUseLocation?.(false);
        config.setInputs?.({
            email: props.username || '',
        });

        await window.HyperKYCModule.launch(config, (result: HypervergeResult) => {
            const images = extractImages(result);

            if (images.selfie) {
                selfieImage.value = images.selfie;
            }

            if (images.document) {
                documentImage.value = images.document;
            }

            if (images.selfie && images.document) {
                step.value = 'document';
                state.value = 'captured';
                hypervergeMessage.value = 'Images captured. Saras will register your face profile.';
            } else {
                hypervergeMessage.value = 'No usable registration images were captured. Please try again.';
            }

            hypervergeState.value = 'complete';
            hypervergeSummary.value = summarizeHypervergeResult(result);
        });
    } catch (error) {
        hypervergeState.value = 'error';
        hypervergeMessage.value = error instanceof Error ? error.message : 'HyperVerge capture failed.';
    }
}

function loadHypervergeSdk(url: string): Promise<void> {
    if (window.HyperKYCModule && window.HyperKycConfig) {
        return Promise.resolve();
    }

    const existing = document.querySelector<HTMLScriptElement>(`script[src="${url}"]`);

    if (existing) {
        return new Promise((resolve, reject) => {
            existing.addEventListener('load', () => resolve(), { once: true });
            existing.addEventListener('error', () => reject(new Error('HyperVerge SDK failed to load.')), { once: true });
        });
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('HyperVerge SDK failed to load.'));
        document.head.appendChild(script);
    });
}

function extractImages(result: HypervergeResult): { selfie: string | null; document: string | null } {
    const matches = findBase64Images(result);
    const selfie = matches.find((match) => /selfie|face|live/i.test(match.path)) ?? null;
    const document = matches.find((match) => /document|doc|id|card/i.test(match.path)) ?? null;

    return {
        selfie: normalizeImageValue(selfie?.value ?? matches[0]?.value),
        document: normalizeImageValue(document?.value ?? matches.find((match) => match.value !== selfie?.value)?.value),
    };
}

function findBase64Images(value: unknown, path = ''): Array<{ path: string; value: string }> {
    if (typeof value === 'string' && looksLikeImage(value)) {
        return [{ path, value }];
    }

    if (!value || typeof value !== 'object') {
        return [];
    }

    return Object.entries(value as Record<string, unknown>).flatMap(([key, child]) => (
        findBase64Images(child, path ? `${path}.${key}` : key)
    ));
}

function looksLikeImage(value: string): boolean {
    return value.startsWith('data:image/')
        || /^\/9j\/[A-Za-z0-9+/=]+/.test(value)
        || /^iVBORw0KGgo[A-Za-z0-9+/=]+/.test(value);
}

function normalizeImageValue(value?: string): string | null {
    if (!value) return null;
    if (value.startsWith('data:image/')) return value;
    if (value.startsWith('/9j/')) return `data:image/jpeg;base64,${value}`;
    if (value.startsWith('iVBORw0KGgo')) return `data:image/png;base64,${value}`;

    return null;
}

function summarizeHypervergeResult(result: HypervergeResult): Record<string, unknown> {
    const details = result.details && typeof result.details === 'object' ? result.details : {};

    return {
        status: result.status ?? null,
        transactionId: result.transactionId ?? null,
        errorCode: result.errorCode ?? null,
        errorMessage: result.errorMessage ?? null,
        latestModule: result.latestModule ?? null,
        detailKeys: Object.keys(details),
        imageFieldPaths: findBase64Images(result).map((match) => match.path),
    };
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

function cancel() {
    router.visit(props.username ? `/login?username=${encodeURIComponent(props.username)}` : '/login');
}

async function uploadDocumentPhoto(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) return;

    documentImage.value = await fileToDataUrl(file);
    validationErrors.value = {};
    void submit();
}

function fileToDataUrl(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(file);
    });
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

    if (!canTryHyperverge.value) {
        startCamera();
    }
});

onUnmounted(() => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
    stopCamera();
});
</script>

<template>
    <AuthBase
        title="Register Face Profile"
        description="Account Biometric Setup"
    >
        <Head title="Register Face" />

        <p v-if="username" class="mb-4 text-center text-sm font-medium text-muted-foreground">
            {{ username }}
        </p>

        <div
            v-if="canTryHyperverge"
            class="mb-4 grid gap-3 rounded-md border p-3"
        >
            <Button
                type="button"
                variant="outline"
                class="w-full"
                :disabled="hypervergeState === 'loading' || state === 'submitting'"
                data-test="hyperverge-capture-button"
                @click="launchHypervergeCapture"
            >
                <Spinner v-if="hypervergeState === 'loading'" />
                Capture with HyperVerge
            </Button>
            <p
                v-if="hypervergeMessage"
                class="text-xs"
                :class="hypervergeState === 'error' ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'"
            >
                {{ hypervergeMessage }}
            </p>
            <Button
                v-if="hypervergeImagesReady"
                type="button"
                class="w-full"
                :disabled="isOffline || state === 'submitting'"
                data-test="hyperverge-register-button"
                @click="submit"
            >
                Register Face with HyperVerge Images
            </Button>
        </div>

        <div
            v-if="isOffline"
            class="mb-4 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200"
        >
            You are offline. Face registration requires an internet connection.
        </div>

        <div class="mb-4 grid grid-cols-2 gap-2 text-xs font-medium">
            <div class="rounded-md border px-3 py-2" :class="{ 'border-primary bg-primary/10': step === 'selfie' }">
                <Check v-if="selfieImage" class="mr-1 inline h-3 w-3" />
                <span v-else class="mr-1">1</span>
                Live Selfie
            </div>
            <div class="rounded-md border px-3 py-2" :class="{ 'border-primary bg-primary/10': step === 'document' }">
                <Check v-if="documentImage" class="mr-1 inline h-3 w-3" />
                <span v-else class="mr-1">2</span>
                ID Document
            </div>
        </div>

        <div v-if="!canTryHyperverge" class="mb-3 text-xs font-semibold tracking-wide text-muted-foreground">
            {{ instructionHeading }}
        </div>

        <div v-if="!canTryHyperverge || activeImage" class="relative mx-auto aspect-[4/3] w-full max-w-sm overflow-hidden rounded-lg bg-black">
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
                v-if="state === 'ready' && step === 'document'"
                class="pointer-events-none absolute inset-4 flex items-center justify-center rounded-md border-2 border-dashed border-white/50 text-xs font-semibold text-white/70"
            >
                DOCUMENT VIEW
            </div>

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
                v-if="!canTryHyperverge && state === 'ready'"
                type="button"
                class="w-full"
                :disabled="isOffline"
                @click="capture"
            >
                <Camera class="mr-2 h-4 w-4" />
                Capture {{ step === 'selfie' ? 'Face Selfie' : 'ID Document' }}
            </Button>

            <template v-if="!canTryHyperverge && state === 'ready' && step === 'document'">
                <input
                    ref="documentFileInputRef"
                    type="file"
                    accept="image/*"
                    class="hidden"
                    @change="uploadDocumentPhoto"
                >
                <Button
                    type="button"
                    variant="outline"
                    class="w-full"
                    :disabled="isOffline"
                    @click="documentFileInputRef?.click()"
                >
                    <Upload class="mr-2 h-4 w-4" />
                    Upload ID Document Photo
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    class="w-full"
                    @click="backToSelfie"
                >
                    Retake Selfie
                </Button>
            </template>

            <Button
                v-if="state === 'ready' && step === 'selfie'"
                type="button"
                variant="outline"
                class="w-full"
                @click="cancel"
            >
                Cancel
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
                    type="button"
                    variant="outline"
                    class="w-full"
                    @click="retake"
                >
                    <RotateCcw class="mr-2 h-4 w-4" />
                    {{ step === 'selfie' ? 'Retake Selfie' : 'Retake ID Document' }}
                </Button>
                <Button
                    v-if="step === 'document'"
                    type="button"
                    variant="ghost"
                    class="w-full"
                    @click="backToSelfie"
                >
                    Retake Selfie
                </Button>
            </template>

            <template v-if="state === 'error'">
                <Button
                    v-if="canTryHyperverge"
                    type="button"
                    class="w-full"
                    @click="launchHypervergeCapture"
                >
                    Try HyperVerge Again
                </Button>
                <Button
                    v-else-if="!cameraInitialized"
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
