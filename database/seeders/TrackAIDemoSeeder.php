<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TrackAIDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🌱 Seeding Track AI demo data...');

        // Truncate for idempotency (SQLite compatible)
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        User::query()->delete();
        Project::query()->delete();
        Upload::query()->forceDelete();
        AuditLog::query()->delete();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        $this->seedUsers();
        $this->seedProjects();
        $this->seedUploads();
        $this->seedAuditLogs();

        $this->command->info('✅ Demo data seeded successfully!');
    }

    /**
     * Seed deterministic demo users.
     */
    protected function seedUsers(): void
    {
        $this->command->info('👥 Creating demo users...');

        // Admin
        User::create([
            'name' => 'Admin User',
            'username' => 'admin',
            'email' => 'admin@track-ai.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        // Engineers
        foreach (range(1, 5) as $i) {
            User::create([
                'name' => "Engineer $i",
                'username' => sprintf('engineer%02d', $i),
                'email' => sprintf('engineer%02d@track-ai.test', $i),
                'password' => Hash::make('password'),
                'role' => 'engineer',
                'email_verified_at' => now(),
            ]);
        }

        // Inspectors
        foreach (range(1, 2) as $i) {
            User::create([
                'name' => "Inspector $i",
                'username' => sprintf('inspector%02d', $i),
                'email' => sprintf('inspector%02d@track-ai.test', $i),
                'password' => Hash::make('password'),
                'role' => 'inspector',
                'email_verified_at' => now(),
            ]);
        }

        $this->command->info('   Created 8 users (1 admin, 5 engineers, 2 inspectors)');
    }

    /**
     * Seed realistic DPWH projects.
     */
    protected function seedProjects(): void
    {
        $this->command->info('🏗️  Creating demo projects...');

        $projects = [
            ['NCR', 'Manila-Cavite Expressway Extension', 'Extension of MCX from Bacoor to Noveleta with 4-lane highway construction.'],
            ['R1', 'Ilocos Norte Coastal Road Rehabilitation', 'Rehabilitation and widening of coastal road from Laoag to Pagudpud.'],
            ['R3', 'Nueva Ecija Farm-to-Market Road', null],
            ['R4A', 'Batangas Port Access Road', 'Construction of 6km access road to Batangas International Port with 2 bridges.'],
            ['NCR', 'Pasig River Flood Control Project', 'Installation of flood gates and embankment strengthening along 8km stretch.'],
            ['R3', 'Tarlac-Pangasinan-La Union Expressway', 'TPLEX extension phase 2 covering 40km with 3 interchanges.'],
            ['R4A', 'Cavite-Laguna Expressway (CALAX)', 'Completion of remaining 15km segment with toll plaza construction.'],
            ['R1', 'Baguio-Bontoc Road Improvement', 'Road widening and slope protection works for 25km mountain road.'],
            ['NCR', 'Skyway Stage 4 Construction', null],
            ['R3', 'Pampanga River Bridge Construction', 'Construction of 800-meter bridge with approach roads in San Fernando.'],
            ['R4A', 'Tagaytay Ridge Road Development', 'New ridge road construction with scenic viewpoints, 12km length.'],
            ['NCR', 'EDSA Greenways Project', 'Installation of bike lanes, pedestrian facilities, and green spaces along EDSA.'],
            ['R1', 'Pangasinan Drainage Improvement', 'Comprehensive drainage system upgrade for flood mitigation in Dagupan City area.'],
            ['R3', 'Nueva Ecija Irrigation Support Road', 'All-weather road construction to support NIA irrigation projects.'],
            ['R4A', 'Batangas Earthquake Retrofit Program', 'Seismic retrofitting of 5 bridges and 3 government buildings in Batangas province.'],
        ];

        $cachedAtOptions = [
            now(),
            now()->subDays(1),
            now()->subDays(3),
            now()->subDays(7),
            now()->subDays(10),
        ];

        foreach ($projects as $index => $projectData) {
            [$region, $name, $description] = $projectData;
            Project::create([
                'external_id' => sprintf('DPWH-%s-2024-%03d', $region, $index + 101),
                'name' => $name,
                'description' => $description,
                'cached_at' => $cachedAtOptions[$index % count($cachedAtOptions)],
            ]);
        }

        $this->command->info('   Created 15 projects across different regions');
    }

    /**
     * Seed demo uploads across various statuses.
     */
    protected function seedUploads(): void
    {
        $this->command->info('📤 Creating demo uploads...');

        $users = User::whereIn('role', ['engineer', 'inspector'])->get();
        $projects = Project::all();

        $documentTypes = ['equipment_pictures', 'delivery_receipts', 'purchase_order', 'documents', 'meals'];
        $tagOptions = [['daily', 'inspection'], ['progress', 'equipment'], ['daily', 'progress'], ['inspection', 'equipment']];
        $titles = ['Site progress photo', 'Equipment delivery', 'Material inspection', 'Foundation work', 'Steel reinforcement', 'Concrete pouring'];
        $errors = ['Network timeout', 'File too large', 'Server error (500)', 'Invalid file format'];
        $totalUploads = 0;

        foreach ($projects->take(5) as $projectIndex => $project) {
            $user = $users[$projectIndex % $users->count()];

            // Uploaded items (6 per project)
            foreach (range(1, 6) as $i) {
                Upload::create([
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'contract_id' => $project->external_id,
                    'entry_id' => 'ENT-'.Str::ulid(),
                    'remote_file_id' => 'FILE-'.Str::ulid(),
                    'title' => $titles[($projectIndex + $i) % count($titles)],
                    'remarks' => $i % 2 === 0 ? 'Progress documentation' : null,
                    'document_type' => $documentTypes[$i % count($documentTypes)],
                    'tags' => $tagOptions[$i % count($tagOptions)],
                    'mime' => 'image/jpeg',
                    'size' => 500000 + ($i * 100000),
                    'status' => Upload::STATUS_UPLOADED,
                    'client_request_id' => Str::uuid()->toString(),
                    'created_at' => now()->subDays($i + $projectIndex),
                ]);
                $totalUploads++;
            }

            // Pending items (2 per project)
            foreach (range(1, 2) as $i) {
                Upload::create([
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'contract_id' => $project->external_id,
                    'title' => 'Pending: Upload '.$i,
                    'document_type' => $documentTypes[$i % count($documentTypes)],
                    'tags' => ['pending_sync'],
                    'status' => Upload::STATUS_PENDING,
                    'client_request_id' => Str::uuid()->toString(),
                    'created_at' => now()->subHours($i + $projectIndex),
                ]);
                $totalUploads++;
            }

            // Failed items (2 per project)
            foreach (range(1, 2) as $i) {
                Upload::create([
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'contract_id' => $project->external_id,
                    'title' => 'Failed: Upload '.$i,
                    'document_type' => $documentTypes[$i % count($documentTypes)],
                    'status' => Upload::STATUS_FAILED,
                    'last_error' => $errors[($projectIndex + $i) % count($errors)],
                    'client_request_id' => Str::uuid()->toString(),
                    'created_at' => now()->subDays($i),
                ]);
                $totalUploads++;
            }
        }

        // Add one locked upload
        $lockedProject = $projects->first();
        $lockedUser = $users->first();
        Upload::create([
            'user_id' => $lockedUser->id,
            'project_id' => $lockedProject->id,
            'contract_id' => $lockedProject->external_id,
            'entry_id' => 'ENT-'.Str::ulid(),
            'remote_file_id' => 'FILE-'.Str::ulid(),
            'title' => 'Locked: Progress Evidence Photo',
            'document_type' => 'equipment_pictures',
            'tags' => ['progress', 'evidence'],
            'mime' => 'image/jpeg',
            'size' => 2500000,
            'status' => Upload::STATUS_UPLOADED,
            'client_request_id' => Str::uuid()->toString(),
            'locked_at' => now()->subDays(3),
            'locked_reason' => 'Referenced in progress submission',
            'created_at' => now()->subDays(10),
        ]);
        $totalUploads++;

        // Add one soft-deleted upload
        $deleted = Upload::create([
            'user_id' => $lockedUser->id,
            'project_id' => $lockedProject->id,
            'contract_id' => $lockedProject->external_id,
            'entry_id' => 'ENT-'.Str::ulid(),
            'remote_file_id' => 'FILE-'.Str::ulid(),
            'title' => 'Deleted: Duplicate Photo',
            'document_type' => 'equipment_pictures',
            'status' => Upload::STATUS_DELETED,
            'client_request_id' => Str::uuid()->toString(),
            'created_at' => now()->subDays(15),
        ]);
        $deleted->delete(); // Soft delete
        $totalUploads++;

        $this->command->info("   Created ~$totalUploads uploads across 5 projects");
    }

    /**
     * Seed audit logs with realistic timeline.
     */
    protected function seedAuditLogs(): void
    {
        $this->command->info('📝 Creating audit logs...');

        $users = User::whereIn('role', ['engineer', 'inspector'])->get();
        $projects = Project::all();
        $admin = User::where('role', 'admin')->first();

        $documentTypes = ['equipment_pictures', 'delivery_receipts', 'meals'];
        $totalLogs = 0;

        // Generate activity for each field user over 45 days
        foreach ($users as $userIndex => $user) {
            $assignedProjects = $projects->take(min(5, $projects->count()));

            // Projects sync entry
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'projects_sync',
                'project_external_id' => null,
                'metadata_json' => ['synced_count' => $assignedProjects->count()],
                'created_at' => now()->subDays(45),
            ]);
            $totalLogs++;

            // Generate daily activities over 45 days (skip some days deterministically)
            foreach (range(1, 45) as $daysAgo) {
                $date = now()->subDays($daysAgo);

                // Skip weekends
                if ($date->isWeekend()) {
                    continue;
                }

                // Skip every 3rd day to simulate missed days
                if ($daysAgo % 3 === 0) {
                    continue;
                }

                $project = $assignedProjects[$daysAgo % $assignedProjects->count()];
                $minuteOffset = ($daysAgo + $userIndex) % 30;

                // Morning check-in
                $checkInTime = $date->copy()->setTime(8, $minuteOffset);
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'attendance_check_in',
                    'project_external_id' => $project->external_id,
                    'metadata_json' => [
                        'entry_id' => 'ENT-'.Str::ulid(),
                        'contract_id' => $project->external_id,
                        'latitude' => 14.5 + ($daysAgo * 0.01),
                        'longitude' => 121.0 + ($daysAgo * 0.01),
                        'remarks' => $daysAgo % 5 === 0 ? 'Morning inspection' : null,
                    ],
                    'created_at' => $checkInTime,
                ]);
                $totalLogs++;

                // Mid-day activities (on most days)
                if ($daysAgo % 2 === 0) {
                    $midDayTime = $date->copy()->setTime(10 + ($daysAgo % 4), $minuteOffset);

                    // Upload
                    if ($daysAgo % 3 !== 2) {
                        $entryId = 'ENT-'.Str::ulid();

                        AuditLog::create([
                            'user_id' => $user->id,
                            'action' => 'upload_init',
                            'project_external_id' => $project->external_id,
                            'metadata_json' => [
                                'entry_id' => $entryId,
                                'contract_id' => $project->external_id,
                                'document_type' => $documentTypes[$daysAgo % count($documentTypes)],
                                'tags' => ['daily_report'],
                            ],
                            'created_at' => $midDayTime,
                        ]);
                        $totalLogs++;

                        AuditLog::create([
                            'user_id' => $user->id,
                            'action' => 'upload_file',
                            'project_external_id' => $project->external_id,
                            'metadata_json' => [
                                'entry_id' => $entryId,
                                'file_id' => 'FILE-'.Str::ulid(),
                                'file_mime' => 'image/jpeg',
                                'file_size' => 500000 + ($daysAgo * 50000),
                            ],
                            'created_at' => $midDayTime->copy()->addSeconds(5),
                        ]);
                        $totalLogs++;
                    }

                    // Progress report
                    if ($daysAgo % 2 === 0) {
                        $progressTime = $midDayTime->copy()->addHours(1);
                        $entryId = 'ENT-'.Str::ulid();

                        AuditLog::create([
                            'user_id' => $user->id,
                            'action' => 'progress_submit',
                            'project_external_id' => $project->external_id,
                            'metadata_json' => [
                                'entry_id' => $entryId,
                                'contract_id' => $project->external_id,
                                'checklist_completed' => 3 + ($daysAgo % 3),
                                'latitude' => 14.5 + ($daysAgo * 0.01),
                                'longitude' => 121.0 + ($daysAgo * 0.01),
                            ],
                            'created_at' => $progressTime,
                        ]);
                        $totalLogs++;

                        // AI workflow (on most progress reports)
                        if ($daysAgo % 5 !== 0) {
                            $workflowId = 'WF-'.Str::ulid();

                            AuditLog::create([
                                'user_id' => $user->id,
                                'action' => 'ai_workflow_started',
                                'project_external_id' => $project->external_id,
                                'metadata_json' => [
                                    'workflow_id' => $workflowId,
                                    'contract_id' => $project->external_id,
                                    'entry_id' => $entryId,
                                ],
                                'created_at' => $progressTime->copy()->addSeconds(2),
                            ]);
                            $totalLogs++;

                            AuditLog::create([
                                'user_id' => $user->id,
                                'action' => 'ai_workflow_completed',
                                'project_external_id' => $project->external_id,
                                'metadata_json' => [
                                    'workflow_id' => $workflowId,
                                    'status' => 'completed',
                                    'results' => [
                                        'progress_percentage' => 65 + ($daysAgo % 28),
                                        'quality_score' => round(7.5 + (($daysAgo % 20) * 0.1), 2),
                                    ],
                                ],
                                'created_at' => $progressTime->copy()->addMinutes(1 + ($daysAgo % 4)),
                            ]);
                            $totalLogs++;
                        }
                    }
                }

                // Evening check-out
                $checkOutTime = $date->copy()->setTime(17, $minuteOffset);
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'attendance_check_out',
                    'project_external_id' => $project->external_id,
                    'metadata_json' => [
                        'entry_id' => 'ENT-'.Str::ulid(),
                        'contract_id' => $project->external_id,
                        'latitude' => 14.5 + ($daysAgo * 0.01),
                        'longitude' => 121.0 + ($daysAgo * 0.01),
                        'remarks' => $daysAgo % 4 === 0 ? 'End of day report submitted' : null,
                    ],
                    'created_at' => $checkOutTime,
                ]);
                $totalLogs++;
            }
        }

        // Add edge case logs
        $user = $users->first();
        $project = $projects->first();

        // Failed upload
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'upload_failed',
            'project_external_id' => $project->external_id,
            'metadata_json' => [
                'entry_id' => 'ENT-'.Str::ulid(),
                'error_code' => 'FILE_TOO_LARGE',
                'error_message' => 'File size exceeds 10MB limit',
            ],
            'created_at' => now()->subDays(5),
        ]);
        $totalLogs++;

        // Failed AI workflow
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'ai_workflow_failed',
            'project_external_id' => $project->external_id,
            'metadata_json' => [
                'workflow_id' => 'WF-'.Str::ulid(),
                'error_code' => 'INSUFFICIENT_PHOTOS',
                'error_message' => 'At least 3 photos required for analysis',
            ],
            'created_at' => now()->subDays(3),
        ]);
        $totalLogs++;

        // Admin audit view
        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'audit_view',
            'project_external_id' => null,
            'metadata_json' => [
                'viewed_range' => 'last_30_days',
                'filter_user' => $user->id,
            ],
            'created_at' => now()->subDays(1),
        ]);
        $totalLogs++;

        $this->command->info("   Created ~$totalLogs audit log entries");
    }
}
