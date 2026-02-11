<?php

namespace App\Services\TrackAI;

use App\Models\AttendanceSession;
use Carbon\Carbon;

class AttendanceSessionService
{
    /**
     * Get the current open session for a user and project.
     */
    public function getOpenSession(int $userId, string $projectExternalId): ?AttendanceSession
    {
        return AttendanceSession::query()
            ->forUserAndProject($userId, $projectExternalId)
            ->open()
            ->latest('check_in_at')
            ->first();
    }

    /**
     * Get any open session for a user (across all projects).
     */
    public function getAnyOpenSession(int $userId): ?AttendanceSession
    {
        return AttendanceSession::query()
            ->where('user_id', $userId)
            ->open()
            ->latest('check_in_at')
            ->first();
    }

    /**
     * Check if user can check in to the specified project.
     * Returns true if no open session exists for this user/project.
     */
    public function canCheckIn(int $userId, string $projectExternalId): bool
    {
        return ! $this->getOpenSession($userId, $projectExternalId);
    }

    /**
     * Check if user can check out from the specified project.
     * Returns true if an open session exists for this user/project.
     */
    public function canCheckOut(int $userId, string $projectExternalId): bool
    {
        return (bool) $this->getOpenSession($userId, $projectExternalId);
    }

    /**
     * Open a new attendance session (check-in).
     */
    public function openSession(
        int $userId,
        string $projectExternalId,
        float $latitude,
        float $longitude,
        ?string $remarks = null
    ): AttendanceSession {
        return AttendanceSession::create([
            'user_id' => $userId,
            'project_external_id' => $projectExternalId,
            'check_in_at' => now(),
            'check_in_latitude' => $latitude,
            'check_in_longitude' => $longitude,
            'check_in_remarks' => $remarks,
            'status' => AttendanceSession::STATUS_OPEN,
        ]);
    }

    /**
     * Close an attendance session (check-out).
     */
    public function closeSession(
        AttendanceSession $session,
        float $latitude,
        float $longitude,
        ?string $remarks = null
    ): AttendanceSession {
        $session->update([
            'check_out_at' => now(),
            'check_out_latitude' => $latitude,
            'check_out_longitude' => $longitude,
            'check_out_remarks' => $remarks,
            'status' => AttendanceSession::STATUS_CLOSED,
        ]);

        return $session->fresh();
    }

    /**
     * Auto-close a session (for missed checkouts).
     */
    public function autoCloseSession(AttendanceSession $session, string $reason): AttendanceSession
    {
        $session->update([
            'check_out_at' => now(),
            'status' => AttendanceSession::STATUS_AUTO_CLOSED,
            'auto_closed_reason' => $reason,
        ]);

        return $session->fresh();
    }

    /**
     * Find and auto-close any orphaned sessions from previous days.
     * Returns the auto-closed session if one was found.
     */
    public function autoClosePreviousDaySessions(int $userId): ?AttendanceSession
    {
        $today = Carbon::today();

        $orphanedSession = AttendanceSession::query()
            ->where('user_id', $userId)
            ->open()
            ->whereDate('check_in_at', '<', $today)
            ->latest('check_in_at')
            ->first();

        if ($orphanedSession) {
            return $this->autoCloseSession(
                $orphanedSession,
                AttendanceSession::AUTO_CLOSE_REASON_PREVIOUS_DAY
            );
        }

        return null;
    }

    /**
     * Get all open sessions that should be auto-closed (checked in before cutoff time).
     *
     * @return \Illuminate\Database\Eloquent\Collection<AttendanceSession>
     */
    public function getSessionsForAutoClose(Carbon $cutoffTime): \Illuminate\Database\Eloquent\Collection
    {
        return AttendanceSession::query()
            ->open()
            ->where('check_in_at', '<', $cutoffTime)
            ->get();
    }

    /**
     * Get the current attendance status for a user and project.
     *
     * @return array{status: string, session: ?AttendanceSession, auto_closed_session: ?AttendanceSession}
     */
    public function getStatus(int $userId, string $projectExternalId): array
    {
        // First, check for and auto-close any orphaned sessions from previous days
        $autoClosed = $this->autoClosePreviousDaySessions($userId);

        // Get current open session
        $openSession = $this->getOpenSession($userId, $projectExternalId);

        return [
            'status' => $openSession ? 'checked_in' : 'checked_out',
            'session' => $openSession,
            'auto_closed_session' => $autoClosed,
        ];
    }
}
