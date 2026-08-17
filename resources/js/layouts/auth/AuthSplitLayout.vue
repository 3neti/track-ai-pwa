<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

const page = usePage();

type Branding = {
    name?: string;
    square_logo?: string | null;
    rectangle_logo?: string | null;
};

const branding = computed<Branding>(() => (page.props.branding as Branding | undefined) ?? {});
const displayName = computed(() => branding.value.name || 'Track AI');

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <div
            class="relative hidden h-full flex-col bg-muted p-10 text-white lg:flex dark:border-r"
        >
            <div class="absolute inset-0 bg-zinc-900" />
            <Link
                :href="home()"
                class="relative z-20 flex items-center text-lg font-medium"
            >
                <span class="mr-2 flex size-8 items-center justify-center overflow-hidden rounded-md">
                    <img
                        v-if="branding.square_logo"
                        :src="branding.square_logo"
                        :alt="`${displayName} logo`"
                        class="size-full object-cover"
                    />
                    <AppLogoIcon v-else class="size-8 fill-current text-white" />
                </span>
                <img
                    v-if="branding.rectangle_logo"
                    :src="branding.rectangle_logo"
                    :alt="displayName"
                    class="h-8 max-w-48 object-contain"
                />
                <span v-else>{{ displayName }}</span>
            </Link>
        </div>
        <div class="lg:p-8">
            <div
                class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]"
            >
                <div class="flex flex-col space-y-2 text-center">
                    <h1 class="text-xl font-medium tracking-tight" v-if="title">
                        {{ title }}
                    </h1>
                    <p class="text-sm text-muted-foreground" v-if="description">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
