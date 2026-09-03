<script setup lang="ts">
import { Head, Form } from '@inertiajs/vue3';
import { Check, FolderSync, RefreshCw } from 'lucide-vue-next';
import { ref } from 'vue';
import AppBottomNav from '@/components/app/AppBottomNav.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update } from '@/actions/App/Http/Controllers/App/SarasProjectSelectionController';

interface ProjectOption {
    id: string;
    contract_id: string;
    name: string;
    tenant_id: string | null;
    tenant_name: string | null;
    is_default: boolean;
    is_selected: boolean;
}

interface ActiveContext {
    project_id: string | null;
    project_name: string | null;
    source: string;
    subproject_ids: Record<string, string>;
    subproject_sources: Record<string, string>;
}

const props = defineProps<{
    activeContext: ActiveContext;
    projects: ProjectOption[];
    defaultProjectId: string;
    status?: string;
}>();

const selectedProjectId = ref(props.activeContext.project_id || props.defaultProjectId);
</script>

<template>
    <div class="min-h-screen bg-background pb-20">
        <Head title="Project Context" />

        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2">
                    <FolderSync class="h-6 w-6 text-primary" />
                    <h1 class="text-lg font-semibold">Project Context</h1>
                </div>
                <Badge variant="outline">{{ activeContext.source }}</Badge>
            </div>
        </header>

        <main class="space-y-4 p-4">
            <Alert v-if="status">
                <Check class="h-4 w-4" />
                <AlertDescription>{{ status }}</AlertDescription>
            </Alert>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Active Saras Project</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div>
                        <p class="text-sm font-medium">{{ activeContext.project_name || 'Configured project' }}</p>
                        <p class="break-all font-mono text-xs text-muted-foreground">{{ activeContext.project_id || defaultProjectId }}</p>
                    </div>
                    <div class="grid gap-2 rounded-md border p-3 text-xs">
                        <div
                            v-for="(id, key) in activeContext.subproject_ids"
                            :key="key"
                            class="grid grid-cols-[110px_minmax(0,1fr)_72px] items-center gap-2"
                        >
                            <span class="font-medium">{{ key }}</span>
                            <span class="break-all font-mono text-muted-foreground">{{ id || '—' }}</span>
                            <Badge variant="secondary" class="w-fit text-[10px]">{{ activeContext.subproject_sources[key] || 'config' }}</Badge>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Choose Project</CardTitle>
                </CardHeader>
                <CardContent>
                    <Form
                        v-bind="update.form()"
                        v-slot="{ errors, processing }"
                        class="space-y-4"
                    >
                        <div class="grid gap-3">
                            <label
                                v-for="project in projects"
                                :key="project.id"
                                @click="selectedProjectId = project.id"
                                class="flex cursor-pointer items-start gap-3 rounded-md border p-3 transition-colors hover:border-primary/50"
                                :class="selectedProjectId === project.id ? 'border-primary bg-primary/5' : 'border-border'"
                            >
                                <input
                                    type="radio"
                                    :value="project.id"
                                    :checked="selectedProjectId === project.id"
                                    class="mt-1"
                                    @change="selectedProjectId = project.id"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium">{{ project.name }}</span>
                                        <Badge v-if="project.is_default" variant="outline" class="text-[10px]">Default</Badge>
                                        <Badge v-if="project.is_selected" class="text-[10px]">Selected</Badge>
                                    </span>
                                    <span v-if="project.tenant_name" class="mt-1 block text-xs text-muted-foreground">{{ project.tenant_name }}</span>
                                    <span class="mt-1 block break-all font-mono text-xs text-muted-foreground">{{ project.id }}</span>
                                </span>
                            </label>
                        </div>

                        <div class="grid gap-2">
                            <Label for="manual_saras_project_id">Manual Project ID</Label>
                            <Input
                                id="manual_saras_project_id"
                                v-model="selectedProjectId"
                                autocomplete="off"
                            />
                            <p class="text-xs text-muted-foreground">
                                Use this if Saras gives us a D-Day project ID before it appears in the list.
                            </p>
                            <InputError :message="errors.saras_project_id" />
                        </div>

                        <input type="hidden" name="saras_project_id" :value="selectedProjectId" />

                        <Button type="submit" class="w-full" :disabled="processing">
                            <RefreshCw v-if="processing" class="mr-2 h-4 w-4 animate-spin" />
                            <FolderSync v-else class="mr-2 h-4 w-4" />
                            Switch Project Context
                        </Button>
                    </Form>
                </CardContent>
            </Card>
        </main>

        <AppBottomNav />
    </div>
</template>
