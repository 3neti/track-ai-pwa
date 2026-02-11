<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Clock, Upload, CheckCircle, XCircle, Trash2, Loader2 } from 'lucide-vue-next';

const props = defineProps<{
    status: 'pending' | 'uploading' | 'uploaded' | 'failed' | 'deleted';
}>();

const config = computed(() => {
    switch (props.status) {
        case 'pending':
            return {
                label: 'Pending',
                variant: 'secondary' as const,
                icon: Clock,
                class: 'bg-yellow-100 text-yellow-800 border-yellow-200',
            };
        case 'uploading':
            return {
                label: 'Uploading',
                variant: 'secondary' as const,
                icon: Loader2,
                class: 'bg-blue-100 text-blue-800 border-blue-200',
                animate: true,
            };
        case 'uploaded':
            return {
                label: 'Uploaded',
                variant: 'secondary' as const,
                icon: CheckCircle,
                class: 'bg-green-100 text-green-800 border-green-200',
            };
        case 'failed':
            return {
                label: 'Failed',
                variant: 'destructive' as const,
                icon: XCircle,
                class: 'bg-red-100 text-red-800 border-red-200',
            };
        case 'deleted':
            return {
                label: 'Deleted',
                variant: 'secondary' as const,
                icon: Trash2,
                class: 'bg-gray-100 text-gray-500 border-gray-200',
            };
        default:
            return {
                label: 'Unknown',
                variant: 'secondary' as const,
                icon: Clock,
                class: '',
            };
    }
});
</script>

<template>
    <Badge :variant="config.variant" :class="['gap-1', config.class]">
        <component
            :is="config.icon"
            :class="['h-3 w-3', config.animate ? 'animate-spin' : '']"
        />
        {{ config.label }}
    </Badge>
</template>
