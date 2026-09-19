<?php

namespace Database\Factories;

use App\Models\Draft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Interest>
 */
class InterestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'draft_id' => Draft::factory(),
            'name' => fake()->unique()->word(),
        ];
    }
}
