<?php

namespace App\Console\Commands;

use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Services\TrackAI\AttendanceSessionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoCheckoutCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trackai:auto-checkout
                            {--cutoff= : Cutoff time in HH:MM format (default: 22:00)}
                            {--dry-run : Show what would be closed without actually closing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-close orphaned attendance sessions (forgotten checkouts)';

    public function __construct(
        protected AttendanceSessionService $sessionService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoffTime = $this->getCutoffTime();
        $isDryRun = $this->option('dry-run');

        $this->info(sprintf(
            'Auto-checkout: Finding sessions checked in before %s%s',
            $cutoffTime->format('Y-m-d H:i:s'),
            $isDryRun ? ' (DRY RUN)' : ''
        ));

        $sessions = $this->sessionService->getSessionsForAutoClose($cutoffTime);

        if ($sessions->isEmpty()) {
            $this->info('No orphaned sessions found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d orphaned session(s) to close.', $sessions->count()));

        $closedCount = 0;

        foreach ($sessions as $session) {
            $this->line(sprintf(
                '  - User ID: %d, Project: %s, Checked in: %s',
                $session->user_id,
                $session->project_external_id,
                $session->check_in_at->format('Y-m-d H:i:s')
            ));

            if (! $isDryRun) {
                $this->sessionService->autoCloseSession(
                    $session,
                    AttendanceSession::AUTO_CLOSE_REASON_END_OF_DAY
                );

                AuditLog::log($session->user_id, 'attendance_auto_checkout', $session->project_external_id, [
                    'session_id' => $session->id,
                    'check_in_at' => $session->check_in_at->toIso8601String(),
                    'auto_closed_at' => now()->toIso8601String(),
                    'reason' => 'end_of_day',
                ]);

                $closedCount++;
            }
        }

        if ($isDryRun) {
            $this->warn('Dry run complete. No sessions were actually closed.');
        } else {
            $this->info(sprintf('Successfully auto-closed %d session(s).', $closedCount));
        }

        return self::SUCCESS;
    }

    /**
     * Get the cutoff time for auto-checkout.
     */
    protected function getCutoffTime(): Carbon
    {
        $cutoffOption = $this->option('cutoff');

        if ($cutoffOption) {
            // Parse HH:MM format
            $parts = explode(':', $cutoffOption);
            if (count($parts) === 2) {
                return Carbon::today()->setTime((int) $parts[0], (int) $parts[1]);
            }
        }

        // Default: 10 PM today
        $defaultTime = config('attendance.auto_checkout_time', '22:00');
        $parts = explode(':', $defaultTime);

        return Carbon::today()->setTime((int) $parts[0], (int) ($parts[1] ?? 0));
    }
}
