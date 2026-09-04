<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
    initialUsername?: string;
}>();

type LoginMode = 'unknown' | 'password' | 'face_registered' | 'face_registration_required';

const username = ref(props.initialUsername || 'lester@hurtado.ph');
const usernameInput = ref<HTMLInputElement | null>(null);
const faceStatusMessage = ref('');
const loginMode = ref<LoginMode>('unknown');
const checkingFaceStatus = ref(false);

const description = computed(() => {
    if (loginMode.value === 'face_registration_required') {
        return 'Biometric face authentication is required for your account. Please sign in with your temporary password to setup your face profile.';
    }

    if (loginMode.value === 'face_registered') {
        return 'Use face login to continue';
    }

    if (loginMode.value === 'password') {
        return 'Enter your password to log in';
    }

    return 'Enter your email to continue';
});

const passwordVisible = computed(() => loginMode.value === 'password' || loginMode.value === 'face_registration_required');
const passwordLabel = computed(() => (loginMode.value === 'face_registration_required' ? 'Temporary password' : 'Password'));
const passwordPlaceholder = computed(() => (loginMode.value === 'face_registration_required' ? 'Temporary password' : 'Password'));
const loginButtonText = computed(() => (loginMode.value === 'face_registration_required' ? 'Sign and Register Face' : 'Log in'));
const showFaceLogin = computed(() => loginMode.value === 'face_registered');
const showContinue = computed(() => loginMode.value === 'unknown');
const showUseDifferentEmail = computed(() => loginMode.value === 'face_registration_required' || loginMode.value === 'face_registered');

function goToFaceLogin() {
    if (username.value.trim()) {
        router.visit(`/face-login?username=${encodeURIComponent(username.value.trim())}`);
    }
}

function useDifferentEmail() {
    username.value = '';
    faceStatusMessage.value = '';
    loginMode.value = 'unknown';

    window.setTimeout(() => usernameInput.value?.focus(), 50);
}

async function checkFaceRegistrationStatus() {
    const email = username.value.trim();

    if (!email || !email.includes('@')) {
        faceStatusMessage.value = '';
        loginMode.value = 'unknown';
        return;
    }

    checkingFaceStatus.value = true;
    faceStatusMessage.value = '';
    loginMode.value = 'unknown';

    try {
        const response = await fetch('/auth/face/registration-status', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ email }),
        });
        const data = await response.json();

        if (response.ok && data.face_registration_required === true) {
            loginMode.value = 'face_registration_required';
            faceStatusMessage.value = 'Face registration is required.';
        } else if (response.ok && String(data.auth_strategy || '').toUpperCase() === 'FACE') {
            loginMode.value = 'face_registered';
            faceStatusMessage.value = 'Face login is enabled.';
        } else {
            loginMode.value = 'password';
        }
    } catch {
        loginMode.value = 'password';
        faceStatusMessage.value = '';
    } finally {
        checkingFaceStatus.value = false;
    }
}

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function syncInitialUsernameInput(element: HTMLInputElement | null) {
    usernameInput.value = element;

    if (element && username.value && element.value !== username.value) {
        element.value = username.value;
    }
}

onMounted(() => {
    window.setTimeout(() => {
        if (usernameInput.value && username.value && usernameInput.value.value !== username.value) {
            usernameInput.value.value = username.value;
        }
    }, 100);
});

watch(username, () => {
    faceStatusMessage.value = '';
    loginMode.value = 'unknown';
});
</script>

<template>
    <AuthBase
        title="Log in to your account"
        :description="description"
    >
        <Head title="Log in" />

        <div
            v-if="status"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            {{ status }}
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="username">Username</Label>
                    <input
                        id="username"
                        :ref="syncInitialUsernameInput"
                        type="text"
                        name="username"
                        v-model="username"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="off"
                        placeholder="Enter your username"
                        class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive"
                        @blur="checkFaceRegistrationStatus"
                    >
                    <InputError :message="errors.username" />
                    <p v-if="faceStatusMessage" class="text-xs text-muted-foreground">
                        {{ faceStatusMessage }}
                    </p>
                </div>

                <div v-if="passwordVisible" class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">{{ passwordLabel }}</Label>
                        <TextLink
                            v-if="canResetPassword && loginMode === 'password'"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            Forgot password?
                        </TextLink>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        :placeholder="passwordPlaceholder"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div v-if="passwordVisible" class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" :tabindex="4" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button
                    v-if="showContinue"
                    type="button"
                    class="mt-4 w-full"
                    :tabindex="3"
                    :disabled="!username.trim() || processing || checkingFaceStatus"
                    data-test="continue-login-button"
                    @click="checkFaceRegistrationStatus"
                >
                    <Spinner v-if="checkingFaceStatus" />
                    Continue
                </Button>

                <Button
                    v-if="passwordVisible"
                    type="submit"
                    class="mt-4 w-full"
                    :tabindex="5"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    {{ loginButtonText }}
                </Button>

                <Button
                    v-if="showFaceLogin"
                    type="button"
                    variant="default"
                    class="w-full"
                    :tabindex="6"
                    :disabled="!username.trim() || processing"
                    @click="goToFaceLogin"
                    data-test="face-login-button"
                >
                    <Spinner v-if="checkingFaceStatus" />
                    <svg v-else class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    Login with Face
                </Button>

                <Button
                    v-if="showUseDifferentEmail"
                    type="button"
                    variant="outline"
                    class="w-full"
                    :tabindex="7"
                    :disabled="processing"
                    data-test="use-different-email-button"
                    @click="useDifferentEmail"
                >
                    Use Different Email
                </Button>
            </div>
        </Form>
    </AuthBase>
</template>
