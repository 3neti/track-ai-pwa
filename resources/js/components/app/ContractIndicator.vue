<script setup lang="ts">
import { onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { Briefcase } from 'lucide-vue-next';
import { useActiveContract } from '@/composables/useActiveContract';

const { activeContractId, activeContractName, hasActiveContract } = useActiveContract();

onMounted(() => {
    // Redirect to contracts page if no contract is selected
    if (!hasActiveContract.value) {
        router.visit('/app/contracts');
    }
});
</script>

<template>
    <div v-if="hasActiveContract" class="flex items-center gap-2 px-4 py-2 border-b bg-primary/5 text-sm">
        <Briefcase class="h-4 w-4 text-primary flex-shrink-0" />
        <span class="text-muted-foreground">Current Contract:</span>
        <span class="font-medium text-foreground truncate">{{ activeContractName || `Contract #${activeContractId}` }}</span>
    </div>
</template>
