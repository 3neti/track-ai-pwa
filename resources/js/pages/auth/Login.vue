<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import { ChevronDown } from 'lucide-vue-next';

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
    defaultProjectId: string;
}>();

const username = ref('lester@hurtado.ph');
const showAdvanced = ref(false);

function goToFaceLogin() {
    if (username.value.trim()) {
        router.visit(`/face-login?username=${encodeURIComponent(username.value.trim())}`);
    }
}
</script>

<template>
    <AuthBase
        title="Log in to your account"
        description="Enter your email and password below to log in"
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
                    <Input
                        id="username"
                        type="text"
                        name="username"
                        v-model="username"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="username"
                        placeholder="Enter your username"
                    />
                    <InputError :message="errors.username" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Password</Label>
                        <TextLink
                            v-if="canResetPassword"
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
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="rounded-md border">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm font-medium"
                        @click="showAdvanced = !showAdvanced"
                    >
                        <span>Project context</span>
                        <ChevronDown class="h-4 w-4 transition-transform" :class="{ 'rotate-180': showAdvanced }" />
                    </button>
                    <div v-if="showAdvanced" class="grid gap-2 border-t px-3 py-3">
                        <Label for="saras_project_id">Saras Project ID</Label>
                        <Input
                            id="saras_project_id"
                            type="text"
                            name="saras_project_id"
                            :default-value="props.defaultProjectId"
                            :tabindex="3"
                            autocomplete="off"
                            placeholder="Use configured default"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional. Keep the default project unless Saras provided another project ID.
                        </p>
                        <InputError :message="errors.saras_project_id" />
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" :tabindex="4" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="mt-4 w-full"
                    :tabindex="5"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    Log in
                </Button>

                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <span class="w-full border-t" />
                    </div>
                    <div class="relative flex justify-center text-xs uppercase">
                        <span class="bg-background px-2 text-muted-foreground">Or</span>
                    </div>
                </div>

                <Button
                    type="button"
                    variant="outline"
                    class="w-full"
                    :tabindex="6"
                    :disabled="!username.trim() || processing"
                    @click="goToFaceLogin"
                    data-test="face-login-button"
                >
                    <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    Login with Face
                </Button>
            </div>
        </Form>
    </AuthBase>
</template>
