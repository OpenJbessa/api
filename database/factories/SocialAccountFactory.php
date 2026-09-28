<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->demo(),
            'provider' => fake()->randomElement(['github', 'google']),
            'provider_user_id' => (string) fake()->unique()->numberBetween(1, 10_000_000),
        ];
    }
}
