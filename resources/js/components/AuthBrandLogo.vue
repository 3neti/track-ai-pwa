<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import type { AppPageProps, AuthBrandingLogo, Branding } from '@/types';

const page = usePage<AppPageProps>();
const activeIndex = ref(0);
let rotationTimer: number | undefined;

const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const branding = computed<Branding>(() => page.props.branding);
const authLogos = computed<AuthBrandingLogo[]>(() => page.props.auth_branding?.logos ?? []);
const intervalMs = computed(() => Math.max(1000, page.props.auth_branding?.interval_ms ?? 10000));
const logos = computed<AuthBrandingLogo[]>(() => {
    if (isAuthenticated.value) {
        return [{
            name: branding.value.name,
            square_logo: branding.value.square_logo,
            rectangle_logo: branding.value.rectangle_logo,
        }];
    }

    return authLogos.value;
});
const activeLogo = computed<AuthBrandingLogo | null>(() => {
    if (logos.value.length === 0) {
        return null;
    }

    return logos.value[activeIndex.value % logos.value.length];
});
const logoUrl = computed(() => activeLogo.value?.rectangle_logo || activeLogo.value?.square_logo || null);
const displayName = computed(() => activeLogo.value?.name || branding.value.name || 'Track AI');
const imageClass = computed(() => activeLogo.value?.rectangle_logo
    ? 'h-10 max-w-56 object-contain'
    : 'size-12 object-contain');

onMounted(() => {
    if (logos.value.length > 1) {
        rotationTimer = window.setInterval(() => {
            activeIndex.value = (activeIndex.value + 1) % logos.value.length;
        }, intervalMs.value);
    }
});

onUnmounted(() => {
    if (rotationTimer) {
        window.clearInterval(rotationTimer);
    }
});
</script>

<template>
    <div class="flex min-h-12 items-center justify-center">
        <img
            v-if="logoUrl"
            :src="logoUrl"
            :alt="`${displayName} logo`"
            :class="imageClass"
        />
        <AppLogoIcon
            v-else
            class="size-10 fill-current text-[var(--foreground)] dark:text-white"
        />
    </div>
</template>
