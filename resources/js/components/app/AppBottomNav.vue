<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { FolderKanban, Clock, Upload, TrendingUp, RefreshCw } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();

const currentRoute = computed(() => page.url);

const navItems = [
    { name: 'Projects', href: '/app/projects', icon: FolderKanban },
    { name: 'Attendance', href: '/app/attendance', icon: Clock },
    { name: 'Uploads', href: '/app/uploads', icon: Upload },
    { name: 'Progress', href: '/app/progress', icon: TrendingUp },
    { name: 'Sync', href: '/app/sync', icon: RefreshCw },
];

const isActive = (href: string) => currentRoute.value.startsWith(href);
</script>

<template>
    <nav class="fixed bottom-0 left-0 right-0 z-50 border-t bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 md:hidden">
        <div class="flex items-center justify-around py-2">
            <Link
                v-for="item in navItems"
                :key="item.name"
                :href="item.href"
                class="flex flex-col items-center gap-1 px-3 py-2 text-xs transition-colors"
                :class="{
                    'text-primary': isActive(item.href),
                    'text-muted-foreground hover:text-foreground': !isActive(item.href),
                }"
            >
                <component :is="item.icon" class="h-5 w-5" />
                <span>{{ item.name }}</span>
            </Link>
        </div>
    </nav>
</template>
