<script setup lang="ts">
import { CloudOff, RefreshCw, Check } from 'lucide-vue-next';

defineProps<{
    pendingCount: number;
    isSyncing: boolean;
    isOnline: boolean;
}>();

const emit = defineEmits<{
    (e: 'sync'): void;
}>();
</script>

<template>
    <button
        @click="emit('sync')"
        :disabled="isSyncing || !isOnline"
        class="relative flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors"
        :class="{
            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-200': pendingCount > 0 && isOnline,
            'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200': !isOnline,
            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200': pendingCount === 0 && isOnline,
        }"
    >
        <CloudOff v-if="!isOnline" class="h-4 w-4" />
        <RefreshCw v-else-if="isSyncing" class="h-4 w-4 animate-spin" />
        <Check v-else-if="pendingCount === 0" class="h-4 w-4" />
        <RefreshCw v-else class="h-4 w-4" />

        <span v-if="!isOnline">Offline</span>
        <span v-else-if="isSyncing">Syncing...</span>
        <span v-else-if="pendingCount === 0">Synced</span>
        <span v-else>{{ pendingCount }} pending</span>
    </button>
</template>
