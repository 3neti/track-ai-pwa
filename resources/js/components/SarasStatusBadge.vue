<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Cloud, CloudOff, FlaskConical } from 'lucide-vue-next';
import { computed, onMounted, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

interface SarasStatus {
    mode: 'stub' | 'live' | 'disabled';
    healthy: boolean;
}

const page = usePage();
const saras = computed(() => page.props.saras as SarasStatus);
const debug = computed(() => page.props.debug as boolean);

const statusConfig = computed(() => {
    const { mode, healthy } = saras.value;

    if (mode === 'stub') {
        return {
            icon: FlaskConical,
            label: 'Stub',
            tooltip: 'Using mock Saras responses',
            variant: 'secondary' as const,
            color: 'text-amber-600 dark:text-amber-400',
            bgColor: 'bg-amber-100 dark:bg-amber-900/30',
        };
    }

    if (mode === 'disabled') {
        return {
            icon: CloudOff,
            label: 'Disabled',
            tooltip: 'Saras integration is disabled',
            variant: 'outline' as const,
            color: 'text-neutral-500',
            bgColor: 'bg-neutral-100 dark:bg-neutral-800',
        };
    }

    if (healthy) {
        return {
            icon: Cloud,
            label: 'Live',
            tooltip: 'Connected to Saras AI',
            variant: 'default' as const,
            color: 'text-emerald-600 dark:text-emerald-400',
            bgColor: 'bg-emerald-100 dark:bg-emerald-900/30',
        };
    }

    return {
        icon: CloudOff,
        label: 'Offline',
        tooltip: 'Saras connection unavailable',
        variant: 'destructive' as const,
        color: 'text-red-600 dark:text-red-400',
        bgColor: 'bg-red-100 dark:bg-red-900/30',
    };
});

// Debug logging
const logSarasStatus = () => {
    if (debug.value) {
        const { mode, healthy } = saras.value;
        console.log(
            `%c[Saras] %cStatus: ${mode} | Healthy: ${healthy}`,
            'color: #8b5cf6; font-weight: bold',
            'color: inherit'
        );
    }
};

onMounted(() => {
    logSarasStatus();
});

watch(saras, () => {
    logSarasStatus();
});
</script>

<template>
    <TooltipProvider :delay-duration="0">
        <Tooltip>
            <TooltipTrigger as-child>
                <Badge
                    :variant="statusConfig.variant"
                    :class="[
                        'flex cursor-default items-center gap-1 px-2 py-0.5 text-xs font-medium',
                        statusConfig.bgColor,
                        statusConfig.color,
                    ]"
                >
                    <component
                        :is="statusConfig.icon"
                        class="h-3 w-3"
                    />
                    <span class="hidden sm:inline">{{ statusConfig.label }}</span>
                </Badge>
            </TooltipTrigger>
            <TooltipContent side="bottom">
                <p>{{ statusConfig.tooltip }}</p>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
