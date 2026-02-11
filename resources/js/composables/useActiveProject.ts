import { ref, onMounted } from 'vue';

const STORAGE_KEY = 'activeProject';

const activeProjectId = ref<string | null>(null);

export interface Project {
    id: number;
    external_id: string;
    name: string;
    description: string | null;
}

/**
 * Composable for managing the active/default project selection.
 * Persists to localStorage for cross-page and offline support.
 */
export function useActiveProject() {
    onMounted(() => {
        if (typeof window !== 'undefined') {
            activeProjectId.value = localStorage.getItem(STORAGE_KEY);
        }
    });

    /**
     * Set the active project.
     */
    function setActiveProject(externalId: string): void {
        activeProjectId.value = externalId;
        if (typeof window !== 'undefined') {
            localStorage.setItem(STORAGE_KEY, externalId);
        }
    }

    /**
     * Clear the active project.
     */
    function clearActiveProject(): void {
        activeProjectId.value = null;
        if (typeof window !== 'undefined') {
            localStorage.removeItem(STORAGE_KEY);
        }
    }

    /**
     * Get the active project from a list, falling back to first project.
     */
    function getActiveProject(projects: Project[]): Project | null {
        if (!projects.length) return null;

        // Try to find the stored active project
        if (activeProjectId.value) {
            const found = projects.find(p => p.external_id === activeProjectId.value);
            if (found) return found;
        }

        // Fallback to first project
        return projects[0];
    }

    /**
     * Get the active project ID, with fallback to first project.
     */
    function getActiveProjectId(projects: Project[]): string {
        const project = getActiveProject(projects);
        return project?.external_id ?? '';
    }

    /**
     * Check if a project is the active one.
     */
    function isActiveProject(externalId: string): boolean {
        return activeProjectId.value === externalId;
    }

    return {
        activeProjectId,
        setActiveProject,
        clearActiveProject,
        getActiveProject,
        getActiveProjectId,
        isActiveProject,
    };
}

/**
 * Initialize active project from localStorage (call on app mount).
 */
export function initializeActiveProject(): void {
    if (typeof window !== 'undefined') {
        activeProjectId.value = localStorage.getItem(STORAGE_KEY);
    }
}
