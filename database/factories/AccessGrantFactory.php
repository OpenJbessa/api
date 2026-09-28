<?php

namespace Database\Factories;

use App\Models\AccessGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessGrant>
 */
class AccessGrantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->demo(),
            'ability' => 'dashboard:view',
            'granted_via' => AccessGrant::VIA_DEFAULT,
            'expires_at' => now()->addMinutes(5),
        ];
    }

    public function elevation(string $ability = 'load:burst'): static
    {
        return $this->state(fn (array $attributes): array => [
            'ability' => $ability,
            'granted_via' => AccessGrant::VIA_ELEVATION,
        ]);
    }
}
