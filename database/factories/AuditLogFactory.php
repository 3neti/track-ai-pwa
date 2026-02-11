<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $action = $this->faker->randomElement([
            'projects_sync',
            'attendance_check_in',
            'attendance_check_out',
            'upload_init',
            'upload_file',
            'progress_submit',
            'ai_workflow_started',
            'ai_workflow_completed',
        ]);

        return [
            'user_id' => User::factory(),
            'action' => $action,
            'project_external_id' => $this->faker->boolean(80) ? Project::factory()->create()->external_id : null,
            'metadata_json' => $this->generateMetadataFor($action),
            'created_at' => $this->faker->dateTimeBetween('-45 days', 'now'),
        ];
    }

    /**
     * Generate realistic metadata based on action type.
     */
    protected function generateMetadataFor(string $action): array
    {
        return match ($action) {
            'projects_sync' => [
                'synced_count' => $this->faker->numberBetween(5, 20),
            ],
            'attendance_check_in', 'attendance_check_out' => [
                'entry_id' => 'ENT-'.fake()->unique()->numberBetween(10000, 99999),
                'contract_id' => 'DPWH-'.fake()->randomElement(['NCR', 'R1', 'R3', 'R4A']).'-2024-'.fake()->numberBetween(100, 999),
                'latitude' => fake()->latitude(14.0, 15.0),
                'longitude' => fake()->longitude(120.5, 121.5),
                'remarks' => fake()->optional(0.3)->sentence(),
            ],
            'upload_init' => [
                'entry_id' => 'ENT-'.fake()->unique()->numberBetween(10000, 99999),
                'contract_id' => 'DPWH-'.fake()->randomElement(['NCR', 'R1', 'R3', 'R4A']).'-2024-'.fake()->numberBetween(100, 999),
                'document_type' => fake()->randomElement(['purchase_order', 'equipment_pictures', 'delivery_receipts', 'meals', 'documents']),
                'tags' => fake()->randomElements(['equipment', 'delivery', 'invoice', 'receipt'], fake()->numberBetween(1, 2)),
            ],
            'upload_file' => [
                'entry_id' => 'ENT-'.fake()->unique()->numberBetween(10000, 99999),
                'file_id' => 'FILE-'.fake()->unique()->numberBetween(10000, 99999),
                'file_url' => fake()->url(),
                'file_mime' => fake()->randomElement(['image/jpeg', 'image/png', 'application/pdf']),
                'file_size' => fake()->numberBetween(100000, 5000000),
            ],
            'progress_submit' => [
                'entry_id' => 'ENT-'.fake()->unique()->numberBetween(10000, 99999),
                'contract_id' => 'DPWH-'.fake()->randomElement(['NCR', 'R1', 'R3', 'R4A']).'-2024-'.fake()->numberBetween(100, 999),
                'checklist_completed' => fake()->numberBetween(3, 5),
                'latitude' => fake()->latitude(14.0, 15.0),
                'longitude' => fake()->longitude(120.5, 121.5),
            ],
            'ai_workflow_started' => [
                'workflow_id' => 'WF-'.fake()->unique()->numberBetween(10000, 99999),
                'contract_id' => 'DPWH-'.fake()->randomElement(['NCR', 'R1', 'R3', 'R4A']).'-2024-'.fake()->numberBetween(100, 999),
                'entry_id' => 'ENT-'.fake()->unique()->numberBetween(10000, 99999),
            ],
            'ai_workflow_completed' => [
                'workflow_id' => 'WF-'.fake()->unique()->numberBetween(10000, 99999),
                'status' => fake()->randomElement(['completed', 'completed_with_warnings']),
                'results' => [
                    'progress_percentage' => fake()->numberBetween(60, 95),
                    'quality_score' => fake()->randomFloat(2, 7.5, 9.5),
                ],
            ],
            default => [],
        };
    }

    /**
     * Create a failed upload audit log.
     */
    public function failedUpload(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'upload_failed',
            'metadata_json' => [
                'entry_id' => 'ENT-'.fake()->unique()->numberBetween(10000, 99999),
                'error_code' => fake()->randomElement(['FILE_TOO_LARGE', 'INVALID_FORMAT', 'NETWORK_ERROR']),
                'error_message' => fake()->sentence(),
            ],
        ]);
    }

    /**
     * Create a failed AI workflow audit log.
     */
    public function failedAi(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'ai_workflow_failed',
            'metadata_json' => [
                'workflow_id' => 'WF-'.fake()->unique()->numberBetween(10000, 99999),
                'error_code' => fake()->randomElement(['INSUFFICIENT_PHOTOS', 'ANALYSIS_TIMEOUT', 'API_ERROR']),
                'error_message' => fake()->sentence(),
            ],
        ]);
    }
}
