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

export interface Contract {
    id: string;
    name: string;
    milestones: string[];
    display_number: string;
}

const props = defineProps<{
    contracts: Contract[];
    modelValue: string;
    label?: string;
    compact?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const selectedContract = computed({
    get: () => props.modelValue,
    set: (value: string) => emit('update:modelValue', value),
});

const selectedName = computed(() => {
    const c = props.contracts.find(c => c.id === props.modelValue);
    return c?.name || `Contract #${c?.display_number || '?'}`;
});
</script>

<template>
    <div class="grid gap-2">
        <Label v-if="label">{{ label }}</Label>
        <Select v-model="selectedContract">
            <SelectTrigger class="w-full">
                <SelectValue placeholder="Select a contract" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="c in contracts"
                    :key="c.id"
                    :value="c.id"
                >
                    {{ c.name || `Contract #${c.display_number}` }}
                    <span class="text-muted-foreground ml-1">({{ c.milestones.length }} milestones)</span>
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
