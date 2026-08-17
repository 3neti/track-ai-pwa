<?php

namespace App\Services\Location;

use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Support\Carbon;

class LocationTrustService
{
    /**
     * @param  array{latitude?: mixed, longitude?: mixed, accuracy?: mixed, timestamp?: mixed, client?: mixed}  $evidence
     * @return array{status: string, reasons: array<int, string>, evidence: array<string, mixed>}
     */
    public function assess(User $user, array $evidence): array
    {
        $reasons = [];
        $normalized = $this->normalizeEvidence($evidence);

        $accuracy = $normalized['accuracy_meters'];
        if (is_float($accuracy) && $accuracy > (float) config('saras.location_trust.max_accuracy_meters', 100)) {
            $reasons[] = 'poor_accuracy';
        }

        $capturedAt = $normalized['captured_at'];
        if ($capturedAt instanceof Carbon) {
            $ageSeconds = abs($capturedAt->diffInSeconds(now()));
            $normalized['age_seconds'] = $ageSeconds;

            if ($ageSeconds > (int) config('saras.location_trust.max_position_age_seconds', 120)) {
                $reasons[] = 'stale_position';
            }
        }

        $speed = $this->speedFromLastKnownPoint($user, $normalized);
        if (is_float($speed)) {
            $normalized['speed_from_last_kmh'] = round($speed, 2);

            if ($speed > (float) config('saras.location_trust.max_speed_kmh', 180)) {
                $reasons[] = 'impossible_travel';
            }
        }

        $status = $reasons === [] ? 'verified' : 'warning';

        if ($status === 'warning' && config('saras.location_trust.mode', 'audit') === 'enforce') {
            $status = 'rejected';
        }

        return [
            'status' => $status,
            'reasons' => $reasons,
            'evidence' => $this->serializeEvidence($normalized),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{latitude: ?float, longitude: ?float, accuracy_meters: ?float, captured_at: ?Carbon, client: mixed}
     */
    private function normalizeEvidence(array $evidence): array
    {
        $capturedAt = null;
        $timestamp = $evidence['timestamp'] ?? null;

        if (is_numeric($timestamp)) {
            $timestamp = (float) $timestamp;
            $capturedAt = Carbon::createFromTimestampMs($timestamp > 10_000_000_000 ? (int) $timestamp : (int) ($timestamp * 1000));
        } elseif (is_string($timestamp) && $timestamp !== '') {
            $capturedAt = Carbon::parse($timestamp);
        }

        return [
            'latitude' => is_numeric($evidence['latitude'] ?? null) ? (float) $evidence['latitude'] : null,
            'longitude' => is_numeric($evidence['longitude'] ?? null) ? (float) $evidence['longitude'] : null,
            'accuracy_meters' => is_numeric($evidence['accuracy'] ?? null) ? (float) $evidence['accuracy'] : null,
            'captured_at' => $capturedAt,
            'client' => $evidence['client'] ?? null,
        ];
    }

    /**
     * @param  array{latitude: ?float, longitude: ?float, captured_at: ?Carbon}  $current
     */
    private function speedFromLastKnownPoint(User $user, array $current): ?float
    {
        if (! is_float($current['latitude']) || ! is_float($current['longitude'])) {
            return null;
        }

        $lastSession = AttendanceSession::where('user_id', $user->id)
            ->where(function ($query): void {
                $query->whereNotNull('check_out_at')
                    ->orWhereNotNull('check_in_at');
            })
            ->latest('updated_at')
            ->first();

        if (! $lastSession) {
            return null;
        }

        $lastLatitude = $lastSession->check_out_latitude ?? $lastSession->check_in_latitude;
        $lastLongitude = $lastSession->check_out_longitude ?? $lastSession->check_in_longitude;
        $lastTimestamp = $lastSession->check_out_at ?? $lastSession->check_in_at;
        $currentTimestamp = $current['captured_at'] ?? now();

        if ($lastLatitude === null || $lastLongitude === null || ! $lastTimestamp) {
            return null;
        }

        $hours = max($lastTimestamp->diffInSeconds($currentTimestamp) / 3600, 1 / 3600);
        $kilometers = $this->haversineKilometers(
            (float) $lastLatitude,
            (float) $lastLongitude,
            $current['latitude'],
            $current['longitude'],
        );

        return $kilometers / $hours;
    }

    private function haversineKilometers(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $earthRadiusKm = 6371;
        $latDelta = deg2rad($toLatitude - $fromLatitude);
        $lonDelta = deg2rad($toLongitude - $fromLongitude);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($lonDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function serializeEvidence(array $evidence): array
    {
        return array_map(
            fn (mixed $value): mixed => $value instanceof Carbon ? $value->toIso8601String() : $value,
            $evidence,
        );
    }
}
