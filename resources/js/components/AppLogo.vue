<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

type Branding = {
    name?: string;
    short_name?: string;
    square_logo?: string | null;
    rectangle_logo?: string | null;
};

const page = usePage();

const branding = computed<Branding>(() => (page.props.branding as Branding | undefined) ?? {});
const displayName = computed(() => branding.value.name || 'Track AI');
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
    >
        <img
            v-if="branding.square_logo"
            :src="branding.square_logo"
            :alt="`${displayName} logo`"
            class="size-full object-cover"
        />
        <AppLogoIcon v-else class="size-5 fill-current text-white dark:text-black" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm min-w-0">
        <img
            v-if="branding.rectangle_logo"
            :src="branding.rectangle_logo"
            :alt="displayName"
            class="h-8 max-w-36 object-contain object-left"
        />
        <span v-else class="mb-0.5 truncate leading-tight font-semibold">{{ displayName }}</span>
    </div>
</template>
