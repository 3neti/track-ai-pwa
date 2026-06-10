<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import { ArrowLeft, Briefcase, ClipboardCheck, Clock, Package, LogOut } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const currentRoute = computed(() => page.url);
const isContractsPage = computed(() => currentRoute.value.startsWith('/app/contracts'));

const workspaceItems = [
    { name: 'Back', href: '/app/contracts', icon: ArrowLeft },
    { name: 'Progress', href: '/app/project-progress', icon: ClipboardCheck },
    { name: 'Attendance', href: '/app/attendance', icon: Clock },
    { name: 'Inventory', href: '/app/project-uploads', icon: Package },
];

const isActive = (href: string) => currentRoute.value.startsWith(href) && href !== '/app/contracts';

function handleLogout() {
    router.post('/logout');
}
</script>

<template>
    <nav class="fixed bottom-0 left-0 right-0 z-50 border-t bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 md:hidden">
        <div class="flex items-center justify-around py-2">
            <template v-if="isContractsPage">
                <Link href="/app/contracts" class="flex flex-col items-center gap-1 px-3 py-2 text-xs text-primary">
                    <Briefcase class="h-5 w-5" />
                    <span>Contracts</span>
                </Link>
                <button @click="handleLogout" class="flex flex-col items-center gap-1 px-3 py-2 text-xs text-muted-foreground hover:text-destructive">
                    <LogOut class="h-5 w-5" />
                    <span>Logout</span>
                </button>
            </template>
            <template v-else>
                <Link
                    v-for="item in workspaceItems"
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
                <button @click="handleLogout" class="flex flex-col items-center gap-1 px-3 py-2 text-xs text-muted-foreground hover:text-destructive">
                    <LogOut class="h-5 w-5" />
                    <span>Logout</span>
                </button>
            </template>
        </div>
    </nav>
</template>
