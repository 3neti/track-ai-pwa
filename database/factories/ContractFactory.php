<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contract>
 */
class ContractFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'saras_process_id' => fake()->uuid(),
            'name' => 'Contract #'.fake()->numberBetween(1, 100),
            'display_number' => (string) fake()->numberBetween(1, 100),
            'milestones' => ['Foundation', 'Floor1', 'Floor2', 'Roofing', 'Interior'],
            'certificate_status' => 'not_started',
            'last_synced_at' => now(),
        ];
    }

    public function available(): static
    {
        return $this->state(fn () => [
            'certificate_status' => 'available',
            'certificate_file_id' => fake()->uuid(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'certificate_status' => 'pending',
        ]);
    }

    public function unknown(): static
    {
        return $this->state(fn () => [
            'certificate_status' => 'unknown',
        ]);
    }
}
