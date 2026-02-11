<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => 'PROJ-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->words(3, true).' Project',
            'description' => fake()->sentence(),
            'cached_at' => now(),
        ];
    }
}
