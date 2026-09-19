<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Draft>
 */
class DraftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'title' => fake()->sentence(),
            'no_of_interests' => 3,
            'no_of_teams' => 2,
            'selection_time_limit' => 60,
            'start_date' => now(),
        ];
    }
}
