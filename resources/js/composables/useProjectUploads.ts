import { ref, computed } from 'vue';
import axios from 'axios';

export interface Upload {
    id: number;
    project_id: number | null;
    user_id: number;
    contract_id: string;
    entry_id: string | null;
    remote_file_id: string | null;
    title: string;
    remarks: string | null;
    document_type: string;
    tags: string[];
    mime: string | null;
    size: number | null;
    status: 'pending' | 'uploading' | 'uploaded' | 'failed' | 'deleted';
    last_error: string | null;
    client_request_id: string;
    locked_at: string | null;
    locked_reason: string | null;
    created_at: string;
    updated_at: string;
    user?: { id: number; name: string };
    // Preview computed fields
    preview_type: 'image' | 'pdf' | 'unknown';
    is_previewable: boolean;
    preview_url: string | null;
}

export interface UploadFilters {
    status?: string;
    tag?: string;
    q?: string;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export function useProjectUploads(projectId: number) {
    const uploads = ref<Upload[]>([]);
    const isLoading = ref(false);
    const error = ref<string | null>(null);
    const filters = ref<UploadFilters>({});
    const pagination = ref<PaginationMeta>({
        current_page: 1,
        last_page: 1,
        per_page: 20,
        total: 0,
    });

    const hasMore = computed(() => pagination.value.current_page < pagination.value.last_page);

    async function fetchUploads(page = 1, reset = true) {
        isLoading.value = true;
        error.value = null;

        try {
            const params: Record<string, string | number> = { page };
            if (filters.value.status) params.status = filters.value.status;
            if (filters.value.tag) params.tag = filters.value.tag;
            if (filters.value.q) params.q = filters.value.q;

            const response = await axios.get(`/api/projects/${projectId}/uploads`, { params });

            if (response.data.success) {
                if (reset) {
                    uploads.value = response.data.data;
                } else {
                    uploads.value = [...uploads.value, ...response.data.data];
                }
                pagination.value = response.data.meta;
            }
        } catch (err) {
            error.value = 'Failed to fetch uploads';
            console.error(err);
        } finally {
            isLoading.value = false;
        }
    }

    async function createUpload(data: {
        client_request_id: string;
        contract_id: string;
        title: string;
        document_type: string;
        tags?: string[];
        remarks?: string;
    }): Promise<Upload | null> {
        try {
            const response = await axios.post(`/api/projects/${projectId}/uploads`, data);
            if (response.data.success) {
                uploads.value = [response.data.upload, ...uploads.value];
                return response.data.upload;
            }
            return null;
        } catch (err) {
            error.value = 'Failed to create upload';
            console.error(err);
            return null;
        }
    }

    async function updateUpload(uploadId: number, data: {
        title?: string;
        document_type?: string;
        tags?: string[];
        remarks?: string;
    }): Promise<Upload | null> {
        try {
            const response = await axios.patch(`/api/projects/${projectId}/uploads/${uploadId}`, data);
            if (response.data.success) {
                const index = uploads.value.findIndex(u => u.id === uploadId);
                if (index !== -1) {
                    uploads.value[index] = response.data.upload;
                }
                return response.data.upload;
            }
            return null;
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 423) {
                error.value = err.response.data.message || 'Upload is locked';
            } else {
                error.value = 'Failed to update upload';
            }
            console.error(err);
            return null;
        }
    }

    async function deleteUpload(uploadId: number, reason?: string): Promise<boolean> {
        try {
            const response = await axios.delete(`/api/projects/${projectId}/uploads/${uploadId}`, {
                data: { reason },
            });
            if (response.data.success) {
                uploads.value = uploads.value.filter(u => u.id !== uploadId);
                return true;
            }
            return false;
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 423) {
                error.value = err.response.data.message || 'Upload is locked';
            } else {
                error.value = 'Failed to delete upload';
            }
            console.error(err);
            return false;
        }
    }

    async function retryUpload(uploadId: number): Promise<Upload | null> {
        try {
            const response = await axios.post(`/api/projects/${projectId}/uploads/${uploadId}/retry`);
            if (response.data.success) {
                const index = uploads.value.findIndex(u => u.id === uploadId);
                if (index !== -1) {
                    uploads.value[index] = response.data.upload;
                }
                return response.data.upload;
            }
            return null;
        } catch (err) {
            error.value = 'Failed to retry upload';
            console.error(err);
            return null;
        }
    }

    function setFilters(newFilters: UploadFilters) {
        filters.value = newFilters;
    }

    function clearError() {
        error.value = null;
    }

    return {
        uploads,
        isLoading,
        error,
        filters,
        pagination,
        hasMore,
        fetchUploads,
        createUpload,
        updateUpload,
        deleteUpload,
        retryUpload,
        setFilters,
        clearError,
    };
}
