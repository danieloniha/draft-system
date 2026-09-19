<?php

namespace Database\Factories;

use App\Models\Draft;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'draft_id' => Draft::factory(),
            'user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'selection_no' => null,
            'token' => Str::random(32),
        ];
    }

    /**
     * An invited participant who has not joined yet.
     */
    public function invited(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }
}
