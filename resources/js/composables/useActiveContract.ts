import { ref, onMounted } from 'vue';

const STORAGE_KEY = 'activeContract';

const activeContractId = ref<string | null>(null);

export interface Contract {
    id: string;
    name: string;
    milestones: string[];
    display_number: string;
}

/**
 * Composable for managing the active contract selection.
 * Persists to localStorage for cross-page consistency.
 */
export function useActiveContract() {
    onMounted(() => {
        if (typeof window !== 'undefined') {
            activeContractId.value = localStorage.getItem(STORAGE_KEY);
        }
    });

    function setActiveContract(id: string): void {
        activeContractId.value = id;
        if (typeof window !== 'undefined') {
            localStorage.setItem(STORAGE_KEY, id);
        }
    }

    function getActiveContractId(contracts: Contract[]): string {
        if (activeContractId.value) {
            const found = contracts.find(c => c.id === activeContractId.value);
            if (found) return found.id;
        }
        return contracts[0]?.id ?? '';
    }

    return {
        activeContractId,
        setActiveContract,
        getActiveContractId,
    };
}
