import { ref, computed } from 'vue';

const STORAGE_KEY = 'activeContract';
const NAME_STORAGE_KEY = 'activeContractName';

// Initialize synchronously from localStorage so the value is available
// before onMounted (watches with immediate: true fire during setup).
const activeContractId = ref<string | null>(
    typeof window !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null
);
const activeContractName = ref<string | null>(
    typeof window !== 'undefined' ? localStorage.getItem(NAME_STORAGE_KEY) : null
);

export interface Contract {
    id: string;
    saras_process_id?: string;
    name: string;
    milestones: string[];
    display_number: string;
}

/**
 * Composable for managing the active contract selection.
 * Persists to localStorage for cross-page consistency.
 */
export function useActiveContract() {

    function setActiveContract(id: string, name?: string): void {
        activeContractId.value = id;
        activeContractName.value = name ?? null;
        if (typeof window !== 'undefined') {
            localStorage.setItem(STORAGE_KEY, id);
            if (name) localStorage.setItem(NAME_STORAGE_KEY, name);
        }
    }

    function getActiveContractId(contracts: Contract[]): string {
        if (activeContractId.value) {
            const found = contracts.find(c => c.id === activeContractId.value);
            if (found) return found.id;
        }
        return contracts[0]?.id ?? '';
    }

    function clearActiveContract(): void {
        activeContractId.value = null;
        activeContractName.value = null;
        if (typeof window !== 'undefined') {
            localStorage.removeItem(STORAGE_KEY);
            localStorage.removeItem(NAME_STORAGE_KEY);
        }
    }

    const hasActiveContract = computed(() => !!activeContractId.value);

    return {
        activeContractId,
        activeContractName,
        hasActiveContract,
        setActiveContract,
        getActiveContractId,
        clearActiveContract,
    };
}
