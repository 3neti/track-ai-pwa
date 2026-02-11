<script setup lang="ts">
import { computed } from 'vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export interface Project {
    id: number;
    external_id: string;
    name: string;
    description: string | null;
}

const props = defineProps<{
    projects: Project[];
    modelValue: string;
    label?: string;
    placeholder?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const selectedProject = computed({
    get: () => props.modelValue,
    set: (value: string) => emit('update:modelValue', value),
});
</script>

<template>
    <div class="grid gap-2">
        <Label v-if="label">{{ label }}</Label>
        <Select v-model="selectedProject">
            <SelectTrigger class="w-full">
                <SelectValue :placeholder="placeholder || 'Select a project'" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="project in projects"
                    :key="project.external_id"
                    :value="project.external_id"
                >
                    {{ project.name }}
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
