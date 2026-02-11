<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { FolderKanban, RefreshCw, MapPin, Calendar } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import SyncBadge from '@/components/app/SyncBadge.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import axios from 'axios';

interface Project {
    id: number;
    external_id: string;
    name: string;
    description: string | null;
    cached_at: string | null;
}

defineProps<{
    projects: Project[];
}>();

const { pendingCount, syncStatus, isOnline, triggerSync } = useOfflineQueue();
const isSyncing = ref(false);

const syncProjects = async () => {
    isSyncing.value = true;
    try {
        await axios.post('/api/projects/sync');
        router.reload();
    } catch (error) {
        console.error('Failed to sync projects:', error);
    } finally {
        isSyncing.value = false;
    }
};
</script>

<template>
    <div class="min-h-screen bg-background pb-20">
        <Head title="Projects" />

        <!-- Header -->
        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2">
                    <FolderKanban class="h-6 w-6 text-primary" />
                    <h1 class="text-lg font-semibold">My Projects</h1>
                </div>
                <SyncBadge
                    :pending-count="pendingCount"
                    :is-syncing="syncStatus.isSyncing"
                    :is-online="isOnline"
                    @sync="triggerSync"
                />
            </div>
        </header>

        <!-- Content -->
        <main class="p-4">
            <div class="mb-4 flex items-center justify-between">
                <p class="text-sm text-muted-foreground">
                    {{ projects.length }} project(s) assigned
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    @click="syncProjects"
                    :disabled="isSyncing || !isOnline"
                >
                    <RefreshCw :class="{ 'animate-spin': isSyncing }" class="mr-2 h-4 w-4" />
                    Sync from Server
                </Button>
            </div>

            <div v-if="projects.length === 0" class="py-12 text-center">
                <FolderKanban class="mx-auto h-12 w-12 text-muted-foreground/50" />
                <h3 class="mt-4 text-lg font-medium">No projects yet</h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    Click "Sync from Server" to fetch your assigned projects.
                </p>
            </div>

            <div v-else class="grid gap-4">
                <Card v-for="project in projects" :key="project.external_id">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">{{ project.name }}</CardTitle>
                        <CardDescription class="text-xs">
                            {{ project.external_id }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p v-if="project.description" class="mb-3 text-sm text-muted-foreground">
                            {{ project.description }}
                        </p>
                        <div class="flex items-center gap-4 text-xs text-muted-foreground">
                            <span v-if="project.cached_at" class="flex items-center gap-1">
                                <Calendar class="h-3 w-3" />
                                Last synced: {{ new Date(project.cached_at).toLocaleDateString() }}
                            </span>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </main>

        <AppBottomNav />
    </div>
</template>
