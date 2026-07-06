<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectProgressReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectProgressReport>
 */
class ProjectProgressReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'contract_id' => fake()->uuid(),
            'current_milestone' => 'Foundation',
            'remarks' => fake()->sentence(),
            'progress_status' => 'draft',
            'previous_progress_file_ids' => [],
            'current_progress_file_ids' => [],
            'source' => 'local',
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'progress_status' => 'submitted',
            'saras_process_id' => 'process_'.fake()->uuid(),
            'source' => 'saras',
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'progress_status' => 'processing',
            'saras_process_id' => 'process_'.fake()->uuid(),
            'saras_workflow_run_id' => 'run_'.fake()->uuid(),
            'source' => 'saras',
        ]);
    }

    public function evaluated(): static
    {
        return $this->state(fn () => [
            'progress_status' => 'evaluated',
            'saras_process_id' => 'process_'.fake()->uuid(),
            'saras_workflow_run_id' => 'run_'.fake()->uuid(),
            'completion_status' => 'SUCCESS',
            'source' => 'saras',
        ]);
    }
}
