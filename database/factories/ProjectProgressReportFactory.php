<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectProgressReport>
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
            'project_id' => \App\Models\Project::factory(),
            'user_id' => \App\Models\User::factory(),
            'contract_id' => fake()->uuid(),
            'current_milestone' => 'Foundation',
            'remarks' => fake()->sentence(),
            'progress_status' => 'draft',
            'previous_progress_file_ids' => [],
            'current_progress_file_ids' => [],
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'progress_status' => 'submitted',
            'saras_process_id' => 'process_'.fake()->uuid(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'progress_status' => 'processing',
            'saras_process_id' => 'process_'.fake()->uuid(),
            'saras_workflow_run_id' => 'run_'.fake()->uuid(),
        ]);
    }

    public function evaluated(): static
    {
        return $this->state(fn () => [
            'progress_status' => 'evaluated',
            'saras_process_id' => 'process_'.fake()->uuid(),
            'saras_workflow_run_id' => 'run_'.fake()->uuid(),
            'completion_status' => 'SUCCESS',
        ]);
    }
}
