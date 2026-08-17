<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

type Branding = {
    name?: string;
    square_logo?: string | null;
    rectangle_logo?: string | null;
};

const page = usePage();
const branding = computed<Branding>(() => (page.props.branding as Branding | undefined) ?? {});
const displayName = computed(() => branding.value.name || 'Track AI');
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <div
                            class="mb-1 flex h-9 w-9 items-center justify-center overflow-hidden rounded-md"
                        >
                            <img
                                v-if="branding.square_logo"
                                :src="branding.square_logo"
                                :alt="`${displayName} logo`"
                                class="size-full object-cover"
                            />
                            <AppLogoIcon
                                v-else
                                class="size-9 fill-current text-[var(--foreground)] dark:text-white"
                            />
                        </div>
                        <img
                            v-if="branding.rectangle_logo"
                            :src="branding.rectangle_logo"
                            :alt="displayName"
                            class="h-8 max-w-48 object-contain"
                        />
                        <span v-else class="text-sm font-semibold">{{ displayName }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
