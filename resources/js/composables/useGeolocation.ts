import { ref, onMounted, onUnmounted } from 'vue';

export interface GeolocationState {
    latitude: number | null;
    longitude: number | null;
    accuracy: number | null;
    timestamp: number | null;
    error: string | null;
    isLoading: boolean;
}

export function useGeolocation(options?: PositionOptions) {
    const state = ref<GeolocationState>({
        latitude: null,
        longitude: null,
        accuracy: null,
        timestamp: null,
        error: null,
        isLoading: false,
    });

    const isSupported = ref('geolocation' in navigator);
    let watchId: number | null = null;

    const defaultOptions: PositionOptions = {
        enableHighAccuracy: true,
        timeout: 10000,
        maximumAge: 0,
        ...options,
    };

    const updatePosition = (position: GeolocationPosition) => {
        state.value = {
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
            timestamp: position.timestamp,
            error: null,
            isLoading: false,
        };
    };

    const handleError = (error: GeolocationPositionError) => {
        let errorMessage: string;

        switch (error.code) {
            case error.PERMISSION_DENIED:
                errorMessage = 'Location access denied. Please enable location permissions.';
                break;
            case error.POSITION_UNAVAILABLE:
                errorMessage = 'Location information unavailable.';
                break;
            case error.TIMEOUT:
                errorMessage = 'Location request timed out.';
                break;
            default:
                errorMessage = 'An unknown error occurred.';
        }

        state.value = {
            ...state.value,
            error: errorMessage,
            isLoading: false,
        };
    };

    const getCurrentPosition = (): Promise<GeolocationState> => {
        return new Promise((resolve) => {
            if (!isSupported.value) {
                state.value.error = 'Geolocation is not supported by your browser.';
                resolve(state.value);
                return;
            }

            state.value.isLoading = true;
            state.value.error = null;

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    updatePosition(position);
                    resolve(state.value);
                },
                (error) => {
                    handleError(error);
                    resolve(state.value);
                },
                defaultOptions
            );
        });
    };

    const startWatching = () => {
        if (!isSupported.value || watchId !== null) {
            return;
        }

        state.value.isLoading = true;

        watchId = navigator.geolocation.watchPosition(
            updatePosition,
            handleError,
            defaultOptions
        );
    };

    const stopWatching = () => {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }
    };

    onUnmounted(() => {
        stopWatching();
    });

    return {
        state,
        isSupported,
        getCurrentPosition,
        startWatching,
        stopWatching,
    };
}
