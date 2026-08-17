<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-6 md:p-10"
    >
        <div class="flex w-full max-w-md flex-col gap-6">
            <Link
                :href="home()"
                class="flex items-center gap-2 self-center font-medium"
            >
                <div class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-md">
                    <img
                        v-if="branding.square_logo"
                        :src="branding.square_logo"
                        :alt="`${displayName} logo`"
                        class="size-full object-cover"
                    />
                    <AppLogoIcon
                        v-else
                        class="size-9 fill-current text-black dark:text-white"
                    />
                </div>
                <img
                    v-if="branding.rectangle_logo"
                    :src="branding.rectangle_logo"
                    :alt="displayName"
                    class="h-8 max-w-48 object-contain"
                />
                <span v-else>{{ displayName }}</span>
            </Link>

            <div class="flex flex-col gap-6">
                <Card class="rounded-xl">
                    <CardHeader class="px-10 pt-8 pb-0 text-center">
                        <CardTitle class="text-xl">{{ title }}</CardTitle>
                        <CardDescription>
                            {{ description }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="px-10 py-8">
                        <slot />
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
