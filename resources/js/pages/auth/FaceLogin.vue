<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';

const props = defineProps<{
    username: string;
    captureProvider?: string;
    hypervergeCapture?: {
        enabled?: boolean;
        workflow?: string;
    };
}>();

type State = 'initializing' | 'ready' | 'captured' | 'submitting' | 'success' | 'error';
type CaptureMode = 'browser' | 'hyperverge';
type FaceLoginResponse = {
    verified?: boolean;
    redirect?: string;
    reason?: string;
    details?: {
        message?: string;
        issue?: string;
        registration_url?: string | null;
        registration_required?: boolean;
    };
};
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

const state = ref<State>('initializing');
const errorMessage = ref('');
const failureReason = ref('');
const registrationUrl = ref<string | null>(null);
const videoRef = ref<HTMLVideoElement | null>(null);
const canvasRef = ref<HTMLCanvasElement | null>(null);
const capturedImage = ref<string | null>(null);
const stream = ref<MediaStream | null>(null);
const isOffline = ref(!navigator.onLine);
const cameraInitialized = ref(false);
const captureMode = ref<CaptureMode>(props.captureProvider === 'hyperverge' && props.hypervergeCapture?.enabled ? 'hyperverge' : 'browser');
const hypervergeState = ref<'idle' | 'loading' | 'complete' | 'error'>('idle');
const hypervergeMessage = ref('');
const hypervergeSummary = ref<Record<string, unknown> | null>(null);

const canUseHyperverge = computed(() => props.hypervergeCapture?.enabled === true);

const stateMessage = computed(() => {
    switch (state.value) {
        case 'initializing':
            return captureMode.value === 'browser' ? 'Starting camera...' : 'Preparing HyperVerge...';
        case 'ready':
            return captureMode.value === 'browser'
                ? 'Align your face within the frame and hold still to verify your identity.'
                : 'Launch HyperVerge to capture your face, then verify with Saras.';
        case 'captured':
            return 'Photo captured. Ready to verify.';
        case 'submitting':
            return 'Verifying...';
        case 'success':
            return 'Verified. Signing in...';
        case 'error':
            return errorMessage.value || 'Verification failed';
        default:
            return '';
    }
});

async function startCamera() {
    if (captureMode.value !== 'browser') return;

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
    } catch (err) {
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

    // Mirror the image for selfie view
    ctx.translate(canvas.width, 0);
    ctx.scale(-1, 1);
    ctx.drawImage(video, 0, 0);

    capturedImage.value = canvas.toDataURL('image/jpeg', 0.9);
    state.value = 'captured';
    failureReason.value = '';
    registrationUrl.value = null;
}

function retake() {
    capturedImage.value = null;
    failureReason.value = '';
    registrationUrl.value = null;
    if (captureMode.value === 'browser') {
        state.value = cameraInitialized.value ? 'ready' : 'initializing';
        if (!cameraInitialized.value) {
            void startCamera();
        }
    } else {
        state.value = 'ready';
    }
}

function retryCamera() {
    captureMode.value = 'browser';
    state.value = 'initializing';
    errorMessage.value = '';
    startCamera();
}

function selectCaptureMode(mode: CaptureMode) {
    if (captureMode.value === mode) return;

    captureMode.value = mode;
    capturedImage.value = null;
    failureReason.value = '';
    registrationUrl.value = null;
    errorMessage.value = '';

    if (mode === 'browser') {
        hypervergeState.value = 'idle';
        hypervergeMessage.value = '';
        state.value = 'initializing';
        void startCamera();
        return;
    }

    stopCamera();
    state.value = 'ready';
}

async function launchHypervergeCapture() {
    if (!canUseHyperverge.value || isOffline.value) return;

    stopCamera();
    hypervergeState.value = 'loading';
    hypervergeMessage.value = 'Starting HyperVerge...';
    hypervergeSummary.value = null;
    state.value = 'initializing';
    errorMessage.value = '';

    try {
        const response = await fetch('/auth/hyperverge/token', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                workflow: props.hypervergeCapture?.workflow || 'faceAuth',
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
            email: props.username,
        });

        await window.HyperKYCModule.launch(config, (result: HypervergeResult) => {
            const image = extractLoginImage(result);

            if (image) {
                capturedImage.value = image;
                state.value = 'captured';
                hypervergeMessage.value = 'HyperVerge returned a login image.';
            } else {
                state.value = 'error';
                errorMessage.value = 'HyperVerge completed, but no login image was returned.';
                hypervergeMessage.value = errorMessage.value;
            }

            hypervergeState.value = 'complete';
            hypervergeSummary.value = summarizeHypervergeResult(result);
        });
    } catch (error) {
        state.value = 'error';
        hypervergeState.value = 'error';
        hypervergeMessage.value = error instanceof Error ? error.message : 'HyperVerge capture failed.';
        errorMessage.value = hypervergeMessage.value;
    }
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

        const data = (await result.json()) as FaceLoginResponse;

        if (data.verified) {
            state.value = 'success';
            stopCamera();
            // Redirect using Inertia
            setTimeout(() => {
                router.visit(data.redirect || '/app/projects');
            }, 500);
        } else {
            state.value = 'error';
            failureReason.value = data.reason || '';
            registrationUrl.value = data.details?.registration_url || null;
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

function continueToFaceRegistration() {
    stopCamera();
    router.visit(registrationUrl.value || login({ query: { username: props.username } }).url);
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

function extractLoginImage(result: HypervergeResult): string | null {
    const matches = findBase64Images(result);
    const preferred = matches.find((match) => /selfie|face|live/i.test(match.path)) ?? matches[0] ?? null;

    return normalizeImageValue(preferred?.value);
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

onMounted(() => {
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    if (captureMode.value === 'browser') {
        void startCamera();
    } else {
        state.value = 'ready';
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
        title="Face Authentication"
        description="Biometric Identity Verification"
    >
        <Head title="Face Login" />

        <p class="mb-4 text-center text-sm font-medium text-muted-foreground">
            {{ username }}
        </p>

        <!-- Offline Warning -->
        <div
            v-if="isOffline"
            class="mb-4 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200"
        >
            You are offline. Face login requires an internet connection.
        </div>

        <div class="mb-3 text-xs font-semibold tracking-wide text-muted-foreground">
            LIVE BIOMETRIC
        </div>

        <div
            v-if="canUseHyperverge"
            class="mb-4 grid grid-cols-2 gap-1 rounded-md border border-border bg-muted/40 p-1"
        >
            <button
                type="button"
                class="rounded-sm px-3 py-2 text-sm font-medium transition"
                :class="captureMode === 'browser' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                @click="selectCaptureMode('browser')"
            >
                Browser Camera
            </button>
            <button
                type="button"
                class="rounded-sm px-3 py-2 text-sm font-medium transition"
                :class="captureMode === 'hyperverge' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                @click="selectCaptureMode('hyperverge')"
            >
                HyperVerge SDK
            </button>
        </div>

        <!-- Camera / Capture View -->
        <div class="relative mx-auto aspect-[4/3] w-full max-w-sm overflow-hidden rounded-lg bg-black">
            <!-- Video Preview -->
            <video
                v-show="captureMode === 'browser' && (state === 'ready' || state === 'initializing')"
                ref="videoRef"
                class="h-full w-full object-cover"
                style="transform: scaleX(-1)"
                autoplay
                playsinline
                muted
            />

            <div
                v-if="captureMode === 'hyperverge' && !capturedImage"
                class="flex h-full w-full flex-col items-center justify-center gap-3 bg-zinc-950 px-6 text-center text-white"
            >
                <div class="h-48 w-36 rounded-full border-2 border-white/50" />
                <p class="text-sm text-white/80">
                    HyperVerge will open its own capture screen.
                </p>
            </div>

            <!-- Captured Image -->
            <img
                v-if="capturedImage && state !== 'ready' && state !== 'initializing'"
                :src="capturedImage"
                alt="Captured selfie"
                class="h-full w-full object-cover"
            />

            <!-- Face Guide Overlay -->
            <div
                v-if="captureMode === 'browser' && state === 'ready'"
                class="pointer-events-none absolute inset-0 flex items-center justify-center"
            >
                <div class="h-48 w-36 rounded-full border-2 border-white/50" />
            </div>

            <!-- Loading Overlay -->
            <div
                v-if="state === 'submitting' || (captureMode === 'browser' && state === 'initializing') || hypervergeState === 'loading'"
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
                v-if="captureMode === 'browser' && state === 'ready'"
                type="button"
                class="w-full"
                @click="capture"
                :disabled="isOffline"
            >
                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Capture Face Selfie
            </Button>

            <Button
                v-if="captureMode === 'hyperverge' && state === 'ready'"
                type="button"
                class="w-full"
                @click="launchHypervergeCapture"
                :disabled="isOffline || hypervergeState === 'loading'"
            >
                <Spinner v-if="hypervergeState === 'loading'" class="mr-2" />
                Launch HyperVerge Face Capture
            </Button>

            <div
                v-if="captureMode === 'hyperverge' && hypervergeMessage"
                class="rounded-md border border-border bg-muted/30 p-3 text-sm"
                :class="hypervergeState === 'error' ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'"
            >
                {{ hypervergeMessage }}
            </div>

            <pre
                v-if="captureMode === 'hyperverge' && hypervergeSummary"
                class="max-h-40 overflow-auto rounded-md bg-muted p-3 text-xs text-muted-foreground"
            >{{ JSON.stringify(hypervergeSummary, null, 2) }}</pre>

            <!-- Verify / Retake Buttons -->
            <template v-if="state === 'captured'">
                <Button
                    type="button"
                    class="w-full"
                    @click="submit"
                    :disabled="isOffline"
                >
                    Verify and Sign In
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="w-full"
                    @click="retake"
                >
                    Retake Selfie
                </Button>
            </template>

            <!-- Error State Buttons -->
            <template v-if="state === 'error'">
                <Button
                    v-if="failureReason === 'not_enrolled'"
                    type="button"
                    class="w-full"
                    @click="continueToFaceRegistration"
                >
                    Sign and Register Face
                </Button>
                <!-- Camera never initialized -->
                <Button
                    v-if="captureMode === 'browser' && !cameraInitialized"
                    type="button"
                    class="w-full"
                    @click="retryCamera"
                >
                    Retry Camera
                </Button>
                <!-- Verification failed after capture -->
                <Button
                    v-else
                    type="button"
                    class="w-full"
                    @click="retake"
                >
                    Retake Selfie
                </Button>
            </template>
        </div>

        <div class="mt-6 text-center">
            <Link
                :href="login()"
                class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
            >
                Use a different email
            </Link>
        </div>
    </AuthBase>
</template>
