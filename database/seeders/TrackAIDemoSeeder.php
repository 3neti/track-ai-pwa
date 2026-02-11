<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
        AuditLog::query()->delete();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        $this->seedUsers();
        $this->seedProjects();
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
                'cached_at' => fake()->randomElement($cachedAtOptions),
            ]);
        }

        $this->command->info('   Created 15 projects across different regions');
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

        $totalLogs = 0;

        // Generate activity for each field user over 45 days
        foreach ($users as $user) {
            $assignedProjects = $projects->random(min(5, $projects->count()));

            // Projects sync entry
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'projects_sync',
                'project_external_id' => null,
                'metadata_json' => ['synced_count' => $assignedProjects->count()],
                'created_at' => now()->subDays(45),
            ]);
            $totalLogs++;

            // Generate daily activities over 45 days
            foreach (range(1, 45) as $daysAgo) {
                $date = now()->subDays($daysAgo);

                // Skip weekends randomly
                if ($date->isWeekend() && fake()->boolean(60)) {
                    continue;
                }

                // Skip some days randomly
                if (fake()->boolean(30)) {
                    continue;
                }

                $project = $assignedProjects->random();

                // Morning check-in
                $checkInTime = $date->copy()->setTime(8, fake()->numberBetween(0, 30));
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'attendance_check_in',
                    'project_external_id' => $project->external_id,
                    'metadata_json' => [
                        'entry_id' => 'ENT-'.fake()->unique()->numberBetween(10000, 99999),
                        'contract_id' => $project->external_id,
                        'latitude' => fake()->latitude(14.0, 15.0),
                        'longitude' => fake()->longitude(120.5, 121.5),
                        'remarks' => fake()->optional(0.2)->sentence(),
                    ],
                    'created_at' => $checkInTime,
                ]);
                $totalLogs++;

                // Mid-day activities (random uploads/progress)
                if (fake()->boolean(70)) {
                    $midDayTime = $date->copy()->setTime(fake()->numberBetween(10, 14), fake()->numberBetween(0, 59));

                    // Upload
                    if (fake()->boolean(60)) {
                        $entryId = 'ENT-'.fake()->unique()->numberBetween(10000, 99999);

                        AuditLog::create([
                            'user_id' => $user->id,
                            'action' => 'upload_init',
                            'project_external_id' => $project->external_id,
                            'metadata_json' => [
                                'entry_id' => $entryId,
                                'contract_id' => $project->external_id,
                                'document_type' => fake()->randomElement(['equipment_pictures', 'delivery_receipts', 'meals']),
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
                                'file_id' => 'FILE-'.fake()->unique()->numberBetween(10000, 99999),
                                'file_mime' => 'image/jpeg',
                                'file_size' => fake()->numberBetween(500000, 3000000),
                            ],
                            'created_at' => $midDayTime->copy()->addSeconds(5),
                        ]);
                        $totalLogs++;
                    }

                    // Progress report
                    if (fake()->boolean(50)) {
                        $progressTime = $midDayTime->copy()->addHours(1);
                        $entryId = 'ENT-'.fake()->unique()->numberBetween(10000, 99999);

                        AuditLog::create([
                            'user_id' => $user->id,
                            'action' => 'progress_submit',
                            'project_external_id' => $project->external_id,
                            'metadata_json' => [
                                'entry_id' => $entryId,
                                'contract_id' => $project->external_id,
                                'checklist_completed' => fake()->numberBetween(3, 5),
                                'latitude' => fake()->latitude(14.0, 15.0),
                                'longitude' => fake()->longitude(120.5, 121.5),
                            ],
                            'created_at' => $progressTime,
                        ]);
                        $totalLogs++;

                        // AI workflow
                        if (fake()->boolean(80)) {
                            $workflowId = 'WF-'.fake()->unique()->numberBetween(10000, 99999);

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
                                        'progress_percentage' => fake()->numberBetween(65, 92),
                                        'quality_score' => fake()->randomFloat(2, 7.5, 9.5),
                                    ],
                                ],
                                'created_at' => $progressTime->copy()->addMinutes(fake()->numberBetween(1, 5)),
                            ]);
                            $totalLogs++;
                        }
                    }
                }

                // Evening check-out
                $checkOutTime = $date->copy()->setTime(17, fake()->numberBetween(0, 45));
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'attendance_check_out',
                    'project_external_id' => $project->external_id,
                    'metadata_json' => [
                        'entry_id' => 'ENT-'.fake()->unique()->numberBetween(10000, 99999),
                        'contract_id' => $project->external_id,
                        'latitude' => fake()->latitude(14.0, 15.0),
                        'longitude' => fake()->longitude(120.5, 121.5),
                        'remarks' => fake()->optional(0.3)->sentence(),
                    ],
                    'created_at' => $checkOutTime,
                ]);
                $totalLogs++;
            }
        }

        // Add edge case logs
        $user = $users->first();
        $project = $projects->random();

        // Failed upload
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'upload_failed',
            'project_external_id' => $project->external_id,
            'metadata_json' => [
                'entry_id' => 'ENT-'.fake()->unique()->numberBetween(10000, 99999),
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
                'workflow_id' => 'WF-'.fake()->unique()->numberBetween(10000, 99999),
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
